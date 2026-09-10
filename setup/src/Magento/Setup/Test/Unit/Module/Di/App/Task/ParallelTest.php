<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Setup\Test\Unit\Module\Di\App\Task;

use Magento\Setup\Module\Di\App\Task\OperationException;
use Magento\Setup\Module\Di\App\Task\Parallel;
use PHPUnit\Framework\TestCase;

class ParallelTest extends TestCase
{
    public function testWorkerCountIsOneForTrivialWorkloads(): void
    {
        $this->assertSame(1, Parallel::workerCount(0));
        $this->assertSame(1, Parallel::workerCount(1));
    }

    public function testWorkerCountRespectsTheMinimumBatchSize(): void
    {
        // Ten items with a minimum of five each can justify at most two workers.
        $this->assertLessThanOrEqual(2, Parallel::workerCount(10, 5));
    }

    public function testWorkerCountIsBounded(): void
    {
        $this->assertLessThanOrEqual(16, Parallel::workerCount(100000));
    }

    public function testEachRunsEveryItemInProcessWhenNotParallel(): void
    {
        $seen = [];
        Parallel::each(['a' => 1, 'b' => 2, 'c' => 3], static function ($item, $key) use (&$seen) {
            $seen[$key] = $item;
        }, 1);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $seen);
    }

    public function testEachRunsPrepareBeforeEachItemInOrder(): void
    {
        $order = [];
        Parallel::each(
            ['first', 'second'],
            static function ($item) use (&$order) {
                $order[] = 'work:' . $item;
            },
            1,
            static function ($item) use (&$order) {
                $order[] = 'prepare:' . $item;
            }
        );

        $this->assertSame(['prepare:first', 'work:first', 'prepare:second', 'work:second'], $order);
    }

    public function testEachSurfacesInProcessFailures(): void
    {
        $this->expectException(\RuntimeException::class);
        Parallel::each([1, 2], static function () {
            throw new \RuntimeException('boom');
        }, 1);
    }

    public function testEachRunsWorkInSeparateProcesses(): void
    {
        $this->requireFork();
        $dir = $this->makeTempDir();
        try {
            $parent = getmypid();
            Parallel::each([1, 2, 3], static function ($item) use ($dir) {
                file_put_contents($dir . '/' . $item, (string)getmypid());
            }, 3);

            $pids = [];
            foreach ([1, 2, 3] as $item) {
                $this->assertFileExists($dir . '/' . $item);
                $pids[] = file_get_contents($dir . '/' . $item);
            }
            $this->assertNotContains((string)$parent, $pids, 'work should not run in the parent');
            $this->assertCount(3, array_unique($pids), 'each item should get its own worker');
        } finally {
            $this->removeTempDir($dir);
        }
    }

    public function testEachRunsOnForkInsideEachChild(): void
    {
        $this->requireFork();
        $dir = $this->makeTempDir();
        try {
            Parallel::each([1, 2], static function ($item) use ($dir) {
                file_put_contents($dir . '/work-' . $item, (string)getmypid());
            }, 2, null, static function () use ($dir) {
                file_put_contents($dir . '/fork-' . getmypid(), 'closed');
            });

            $this->assertCount(2, glob($dir . '/fork-*'), 'onFork should run once per child');
        } finally {
            $this->removeTempDir($dir);
        }
    }

    public function testEachReportsAFailingWorkerByKey(): void
    {
        $this->requireFork();
        $this->expectException(OperationException::class);
        $this->expectExceptionMessageMatches('/adminhtml/');

        Parallel::each(
            ['frontend' => 'frontend', 'adminhtml' => 'adminhtml'],
            static function ($area) {
                if ($area === 'adminhtml') {
                    throw new \RuntimeException('worker failed');
                }
            },
            2
        );
    }

    public function testEachNeverExceedsTheWorkerBudget(): void
    {
        $this->requireFork();
        $dir = $this->makeTempDir();
        try {
            // Each child records that it is alive, waits, then clears the marker.
            Parallel::each(range(1, 6), static function ($item) use ($dir) {
                file_put_contents($dir . '/live-' . getmypid(), '1');
                $peak = count(glob($dir . '/live-*'));
                file_put_contents($dir . '/peak-' . getmypid(), (string)$peak);
                usleep(50000);
                unlink($dir . '/live-' . getmypid());
            }, 2);

            $peaks = array_map('file_get_contents', glob($dir . '/peak-*'));
            $this->assertLessThanOrEqual(2, max(array_map('intval', $peaks)));
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function requireFork(): void
    {
        if (!Parallel::isAvailable()) {
            $this->markTestSkipped('pcntl is not available');
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/magento-parallel-test-' . getmypid() . '-' . uniqid('', false);
        mkdir($dir);

        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
