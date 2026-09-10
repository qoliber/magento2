<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Setup\Module\Di\App\Task;

/**
 * Runs independent units of compilation work in forked worker processes.
 *
 * Compilation forks only after the codebase has been loaded, so a worker inherits every loaded
 * class copy-on-write instead of paying to load it again. Workers communicate nothing back: each
 * unit of work writes its own output files, so there is no result to merge.
 */
class Parallel
{
    /**
     * Never run more workers than this, however many cores are reported.
     */
    private const MAX_WORKERS = 16;

    /**
     * Whether worker processes can be used.
     *
     * @param bool $enabled
     * @return bool
     */
    public static function isAvailable(bool $enabled = true): bool
    {
        return $enabled
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid');
    }

    /**
     * How many workers to use for the given amount of work, bounded by the available CPU.
     *
     * @param int $items
     * @param int $minPerWorker
     * @return int
     */
    public static function workerCount(int $items, int $minPerWorker = 1): int
    {
        if ($items < 2 || $minPerWorker < 1) {
            return 1;
        }

        return (int)\max(1, \min(self::cpuCount(), self::MAX_WORKERS, \intdiv($items, $minPerWorker)));
    }

    /**
     * Run $work over every item in worker processes, never more than $maxWorkers at a time.
     *
     * $prepare runs in the parent before each fork, so a caller whose items depend on state built
     * up by the preceding items can advance that state in the original order; the child then
     * inherits exactly the state its item would have seen sequentially.
     *
     * $onFork runs first inside each child. Workers inherit whatever descriptors the parent had
     * open - database and cache backend connections in particular - and several processes writing
     * down one socket corrupts it, so a caller that may have such connections open must close them
     * there.
     *
     * Falls back to running in-process whenever forking is unavailable or refused, so the result
     * is the same either way.
     *
     * @param array $items
     * @param callable $work
     * @param int $maxWorkers
     * @param callable|null $prepare
     * @param callable|null $onFork
     * @return void
     * @throws OperationException
     */
    public static function each(
        array $items,
        callable $work,
        int $maxWorkers = 1,
        ?callable $prepare = null,
        ?callable $onFork = null
    ): void {
        $children = [];
        $failures = [];

        foreach ($items as $key => $item) {
            if ($prepare !== null) {
                $prepare($item, $key);
            }

            if ($maxWorkers < 2 || !self::isAvailable()) {
                $work($item, $key);
                continue;
            }

            // Keep the number of live workers within the budget before starting another.
            while (\count($children) >= $maxWorkers) {
                $failures = \array_merge($failures, self::reapOne($children));
            }

            $pid = \pcntl_fork();
            if ($pid === -1) {
                // Fork refused (process or memory limits) - do this item here instead.
                $work($item, $key);
                continue;
            }
            if ($pid === 0) {
                $status = self::runChild($work, $item, $key, $onFork);
                // A forked worker must terminate here; returning would resume the parent's loop.
                // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
                exit($status);
            }
            $children[$pid] = $key;
        }

        while ($children) {
            $failures = \array_merge($failures, self::reapOne($children));
        }

        if ($failures) {
            throw new OperationException(
                'Compilation failed for: ' . \implode(', ', \array_map('strval', $failures))
                . '. See the errors above, or re-run with --single-process to reproduce them in'
                . ' this process.'
            );
        }
    }

    /**
     * Run one item inside a freshly forked child, returning the exit status.
     *
     * @param callable $work
     * @param mixed $item
     * @param mixed $key
     * @param callable|null $onFork
     * @return int
     */
    private static function runChild(callable $work, $item, $key, ?callable $onFork): int
    {
        try {
            if ($onFork !== null) {
                $onFork();
            }
            $work($item, $key);
        } catch (\Throwable $e) {
            // The parent cannot see this exception, so report it in full here: the message alone
            // loses the type and the origin, which is all there is to go on when a worker fails.
            \fwrite(
                STDERR,
                \sprintf(
                    '%s%s failed: %s: %s%s%s%s',
                    PHP_EOL,
                    (string)$key,
                    \get_class($e),
                    $e->getMessage(),
                    PHP_EOL,
                    $e->getTraceAsString(),
                    PHP_EOL
                )
            );
            return 1;
        }

        return 0;
    }

    /**
     * Wait for one child to finish, returning its key if it did not succeed.
     *
     * @param array $children
     * @return array
     */
    private static function reapOne(array &$children): array
    {
        $status = 0;
        do {
            $pid = \pcntl_waitpid(-1, $status);
            // Retry when the wait itself was interrupted by a signal.
        } while ($pid === -1 && \pcntl_get_last_error() === PCNTL_EINTR);

        if ($pid === -1) {
            // Nothing left to wait for. SIGCHLD may be set to SIG_IGN, in which case the kernel
            // reaps children itself and their status is simply unavailable - that is not a
            // failure, so drop them rather than reporting every one as failed.
            $children = [];
            return [];
        }

        if (!\array_key_exists($pid, $children)) {
            return [];
        }

        $key = $children[$pid];
        unset($children[$pid]);

        return \pcntl_wifexited($status) && \pcntl_wexitstatus($status) === 0 ? [] : [$key];
    }

    /**
     * Number of CPUs this process may actually use.
     *
     * Reads the cgroup quota first: a container is commonly limited to a fraction of the cores the
     * host reports, and starting a worker per host core there is how one process becomes N.
     *
     * @return int
     */
    private static function cpuCount(): int
    {
        $quota = self::cgroupCpuQuota();
        if ($quota !== null) {
            return $quota;
        }

        if (\function_exists('pcntl_getcpuaffinity')) {
            $affinity = \pcntl_getcpuaffinity();
            if (\is_array($affinity) && $affinity !== []) {
                return \count($affinity);
            }
        }

        if (\is_readable('/proc/cpuinfo')) {
            $count = \substr_count((string)\file_get_contents('/proc/cpuinfo'), 'processor' . "\t");
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /**
     * CPUs allowed by the cgroup quota, or null when unlimited or not in a cgroup.
     *
     * @return int|null
     */
    private static function cgroupCpuQuota(): ?int
    {
        // cgroup v2: "<quota> <period>", or "max <period>" when unlimited.
        if (\is_readable('/sys/fs/cgroup/cpu.max')) {
            $parts = \preg_split('/\s+/', \trim((string)\file_get_contents('/sys/fs/cgroup/cpu.max')));
            if ($parts && $parts[0] !== 'max' && isset($parts[1]) && (int)$parts[1] > 0) {
                return (int)\max(1, \intdiv((int)$parts[0], (int)$parts[1]));
            }
            return null;
        }

        // cgroup v1: quota of -1 means unlimited.
        $quotaFile = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';
        $periodFile = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';
        if (\is_readable($quotaFile) && \is_readable($periodFile)) {
            $quota = (int)\trim((string)\file_get_contents($quotaFile));
            $period = (int)\trim((string)\file_get_contents($periodFile));
            if ($quota > 0 && $period > 0) {
                return (int)\max(1, \intdiv($quota, $period));
            }
        }

        return null;
    }
}
