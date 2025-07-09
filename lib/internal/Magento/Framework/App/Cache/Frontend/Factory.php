<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Factory that creates cache frontend instances based on options
 */
namespace Magento\Framework\App\Cache\Frontend;

use Cm_Cache_Backend_File;
use Exception;
use LogicException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\Backend\Database;
use Magento\Framework\Cache\Backend\Eaccelerator;
use Magento\Framework\Cache\Backend\RemoteSynchronizedCache;
use Magento\Framework\Cache\Core;
use Magento\Framework\Cache\Frontend\Adapter\Symfony;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Profiler;
use UnexpectedValueException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\XcacheAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Factory
{
    /**
     * Default cache entry lifetime
     */
    public const DEFAULT_LIFETIME = 7200;

    /**
     * Caching params, that applied for all cache frontends regardless of type
     */
    public const PARAM_CACHE_FORCED_OPTIONS = 'cache_options';

    /**
     * @var ObjectManagerInterface
     */
    private $_objectManager;

    /**
     * @var Filesystem
     */
    private $_filesystem;

    /**
     * Cache options to be enforced for all instances being created
     *
     * @var array
     */
    private $_enforcedOptions = [];

    /**
     * Configuration of decorators that are to be applied to every cache frontend being instantiated, format:
     * array(
     *  array('class' => '<decorator_class>', 'arguments' => array()),
     *  ...
     * )
     *
     * @var array
     */
    private $_decorators = [];

    /**
     * Default cache backend type
     *
     * @var string
     */
    protected $_defaultBackend = 'Cm_Cache_Backend_File';

    /**
     * Options for default backend
     *
     * @var array
     */
    protected $_backendOptions = [
        'hashed_directory_level' => 1,
        'file_name_prefix' => 'mage',
    ];

    /**
     * @var ResourceConnection
     */
    protected $_resource;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param Filesystem $filesystem
     * @param ResourceConnection $resource
     * @param array $enforcedOptions
     * @param array $decorators
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Filesystem $filesystem,
        ResourceConnection $resource,
        array $enforcedOptions = [],
        array $decorators = []
    ) {
        $this->_objectManager = $objectManager;
        $this->_filesystem = $filesystem;
        $this->_resource = $resource;
        $this->_enforcedOptions = $enforcedOptions;
        $this->_decorators = $decorators;
    }

    /**
     * Return newly created cache frontend instance
     *
     * @param array $options
     * @return FrontendInterface
     */
    public function create(array $options)
    {
        $options = $this->_getExpandedOptions($options);

        foreach (['backend_options', 'slow_backend_options'] as $section) {
            if (!empty($options[$section]['cache_dir'])) {
                $directory = $this->_filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
                $directory->create($options[$section]['cache_dir']);
                $options[$section]['cache_dir'] = $directory->getAbsolutePath($options[$section]['cache_dir']);
            }
        }

        $idPrefix = isset($options['id_prefix']) ? $options['id_prefix'] : '';
        if (!$idPrefix && isset($options['prefix'])) {
            $idPrefix = $options['prefix'];
        }
        if (empty($idPrefix)) {
            $configDirPath = $this->_filesystem->getDirectoryRead(DirectoryList::CONFIG)->getAbsolutePath();
            $idPrefix =
                // md5() here is not for cryptographic use.
                // phpcs:ignore Magento2.Security.InsecureFunction
                substr(md5($configDirPath), 0, 3) . '_';
        }
        $options['frontend_options']['cache_id_prefix'] = $idPrefix;

        $backend = $this->_getBackendOptions($options);
        $frontend = $this->_getFrontendOptions($options);

        // Start profiling
        $profilerTags = [
            'group' => 'cache',
            'operation' => 'cache:create',
            'frontend_type' => $frontend['type'],
            'backend_type' => $backend['type'],
        ];
        Profiler::start('cache_frontend_create', $profilerTags);

        try {
            $result = $this->_objectManager->create(
                Symfony::class,
                [
                    'cache' => $this->createSymfonyCacheAdapter($backend['type'], $backend['options'], $idPrefix)
                ]
            );
        } catch (\Exception $e) {
            $result = $this->createCacheWithDefaultOptions($options);
        }
        $result = $this->_applyDecorators($result);

        // stop profiling
        Profiler::stop('cache_frontend_create');
        return $result;
    }

    /**
     * Create a Symfony Cache adapter based on type and options.
     *
     * @param string $type
     * @param array $options
     * @param string $namespace
     * @return CacheInterface
     * @throws \Exception
     */
    private function createSymfonyCacheAdapter(string $type, array $options, string $namespace): CacheInterface
    {
        switch (strtolower($type)) {
            case 'filesystem':
            case 'cm_cache_backend_file':
                return new FilesystemAdapter($namespace, 0, $options['cache_dir'] ?? null);
            case 'memcached':
            case 'libmemcached':
                $memcached = new \Memcached();
                foreach ($options['servers'] ?? [] as $server) {
                    $memcached->addServer($server['host'], $server['port']);
                }
                return new MemcachedAdapter($memcached, $namespace);
            case 'redis':
            case 'cm_cache_backend_redis':
                $redis = new \Redis();
                $redis->connect($options['server'], $options['port']);
                return new RedisAdapter($redis, $namespace);
            case 'database':
                $connection = $this->_resource->getConnection();
                $dbOptions = $this->_getDbAdapterOptions();
                return new PdoAdapter($connection->getConnection(), $namespace, $dbOptions['db_table'] ?? 'cache');
            case 'apc':
                return new ApcuAdapter($namespace);
            case 'xcache':
                return new XcacheAdapter($namespace);
            case 'array':
                return new ArrayAdapter();
            case 'twolevels':
                // This case needs special handling, likely a ChainAdapter or custom implementation
                // For now, fallback to FilesystemAdapter or throw an error.
                throw new \Exception('TwoLevels cache backend is not directly supported by Symfony Cache adapters.');
            default:
                // Attempt to create a custom Symfony Cache adapter if a class name is provided
                if (class_exists($type) && in_array(CacheInterface::class, class_implements($type))) {
                    return $this->_objectManager->create($type, $options);
                }
                throw new \Exception(sprintf('Unsupported cache backend type: %s', $type));
        }
    }

    /**
     * Return options expanded with enforced values
     *
     * @param array $options
     * @return array
     */
    private function _getExpandedOptions(array $options)
    {
        return array_replace_recursive($options, $this->_enforcedOptions);
    }

    /**
     * Apply decorators to a cache frontend instance and return the topmost one
     *
     * @param FrontendInterface $frontend
     * @return FrontendInterface
     * @throws LogicException
     * @throws UnexpectedValueException
     */
    private function _applyDecorators(FrontendInterface $frontend)
    {
        foreach ($this->_decorators as $decoratorConfig) {
            if (!isset($decoratorConfig['class'])) {
                throw new LogicException('Class has to be specified for a cache frontend decorator.');
            }
            $decoratorClass = $decoratorConfig['class'];
            $decoratorParams = isset($decoratorConfig['parameters']) ? $decoratorConfig['parameters'] : [];
            $decoratorParams['frontend'] = $frontend;
            // conventionally, 'frontend' argument is a decoration subject
            $frontend = $this->_objectManager->create($decoratorClass, $decoratorParams);
            if (!$frontend instanceof FrontendInterface) {
                throw new UnexpectedValueException('Decorator has to implement the cache frontend interface.');
            }
        }
        return $frontend;
    }

    /**
     * Get cache backend options. Result array contain backend type ('type' key) and backend options ('options')
     *
     * @param  array $cacheOptions
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getBackendOptions(array $cacheOptions) //phpcs:ignore Generic.Metrics.NestingLevel
    {
        $enableTwoLevels = false;
        $type = isset($cacheOptions['backend']) ? $cacheOptions['backend'] : $this->_defaultBackend;
        if (isset($cacheOptions['backend_options']) && is_array($cacheOptions['backend_options'])) {
            $options = $cacheOptions['backend_options'];
        } else {
            $options = [];
        }

        $backendType = false;
        switch (strtolower($type)) {
            case 'sqlite':
                // Symfony Cache does not have a direct SQLite adapter. PdoAdapter can be used with SQLite DSN.
                // For now, fallback to FilesystemAdapter or throw an error.
                throw new \Exception('SQLite cache backend is not directly supported by Symfony Cache adapters.');
            case 'memcached':
            case 'libmemcached':
                $enableTwoLevels = true;
                $backendType = 'memcached';
                break;
            case 'apc':
                if (extension_loaded('apc') && ini_get('apc.enabled')) {
                    $enableTwoLevels = true;
                    $backendType = 'apc';
                }
                break;
            case 'xcache':
                if (extension_loaded('xcache')) {
                    $enableTwoLevels = true;
                    $backendType = 'xcache';
                }
                break;
            case 'eaccelerator':
            case 'varien_cache_backend_eaccelerator':
                // Symfony Cache does not have an eAccelerator adapter.
                // For now, fallback to FilesystemAdapter or throw an error.
                throw new \Exception('eAccelerator cache backend is not directly supported by Symfony Cache adapters.');
            case 'database':
                $backendType = 'database';
                $options = $this->_getDbAdapterOptions();
                break;
            case 'remote_synchronized_cache':
                $backendType = 'twolevels'; // Custom type for ChainAdapter
                $options['remote_backend'] = 'database';
                $options['remote_backend_options'] = $this->_getDbAdapterOptions();
                $options['local_backend'] = 'filesystem';
                $cacheDir = $this->_filesystem->getDirectoryWrite(DirectoryList::CACHE);
                $options['local_backend_options']['cache_dir'] = $cacheDir->getAbsolutePath();
                $cacheDir->create();
                break;
            case 'redis':
            case 'cm_cache_backend_redis':
                $backendType = 'redis';
                break;
            case 'cm_cache_backend_file':
            case 'filesystem':
                $backendType = 'filesystem';
                break;
            default:
                // Assume it's a custom Symfony Cache adapter class name
                if (class_exists($type) && in_array(CacheInterface::class, class_implements($type))) {
                    $backendType = $type;
                } else {
                    // Fallback to default if unknown or not a valid Symfony Cache adapter
                    $backendType = 'filesystem';
                }
        }

        if (!$backendType) {
            $backendType = 'filesystem';
            $cacheDir = $this->_filesystem->getDirectoryWrite(DirectoryList::CACHE);
            $this->_backendOptions['cache_dir'] = $cacheDir->getAbsolutePath();
            $cacheDir->create();
        }
        foreach ($this->_backendOptions as $option => $value) {
            if (!array_key_exists($option, $options)) {
                $options[$option] = $value;
            }
        }

        $backendOptions = ['type' => $backendType, 'options' => $options];
        if ($enableTwoLevels) {
            $backendOptions = $this->_getTwoLevelsBackendOptions($backendOptions, $cacheOptions);
        }
        return $backendOptions;
    }

    /**
     * Get options for database backend type
     *
     * @return array
     */
    protected function _getDbAdapterOptions()
    {
        $options = [];
        $options['db_table'] = $this->_resource->getTableName('cache');
        return $options;
    }

    /**
     * Initialize two levels backend model options
     *
     * @param array $fastOptions fast level backend type and options
     * @param array $cacheOptions all cache options
     * @return array
     */
    protected function _getTwoLevelsBackendOptions($fastOptions, $cacheOptions)
    {
        $options = [];
        $options['fast_backend'] = $fastOptions['type'];
        $options['fast_backend_options'] = $fastOptions['options'];
        $options['fast_backend_custom_naming'] = true;
        $options['fast_backend_autoload'] = true;
        $options['slow_backend_custom_naming'] = true;
        $options['slow_backend_autoload'] = true;

        if (isset($cacheOptions['auto_refresh_fast_cache'])) {
            $options['auto_refresh_fast_cache'] = (bool)$cacheOptions['auto_refresh_fast_cache'];
        } else {
            $options['auto_refresh_fast_cache'] = false;
        }
        if (isset($cacheOptions['slow_backend'])) {
            $options['slow_backend'] = $cacheOptions['slow_backend'];
        } else {
            $options['slow_backend'] = $this->_defaultBackend;
        }
        if (isset($cacheOptions['slow_backend_options'])) {
            $options['slow_backend_options'] = $cacheOptions['slow_backend_options'];
        } else {
            $options['slow_backend_options'] = $this->_backendOptions;
        }
        if ($options['slow_backend'] == 'database') {
            $options['slow_backend'] = 'database';
            $options['slow_backend_options'] = $this->_getDbAdapterOptions();
            if (isset($cacheOptions['slow_backend_store_data'])) {
                $options['slow_backend_options']['store_data'] = (bool)$cacheOptions['slow_backend_store_data'];
            } else {
                $options['slow_backend_options']['store_data'] = false;
            }
        }

        $backend = ['type' => 'twolevels', 'options' => $options];
        return $backend;
    }

    /**
     * Get options of cache frontend (options of Symfony Cache)
     *
     * @param  array $cacheOptions
     * @return array
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getFrontendOptions(array $cacheOptions)
    {
        $options = isset($cacheOptions['frontend_options']) ? $cacheOptions['frontend_options'] : [];
        if (!array_key_exists('caching', $options)) {
            $options['caching'] = true;
        }
        if (!array_key_exists('lifetime', $options)) {
            $options['lifetime'] = isset(
                $cacheOptions['lifetime']
            ) ? $cacheOptions['lifetime'] : self::DEFAULT_LIFETIME;
        }
        if (!array_key_exists('automatic_cleaning_factor', $options)) {
            $options['automatic_cleaning_factor'] = 0;
        }
        $options['type'] = isset($cacheOptions['frontend']) ? $cacheOptions['frontend'] : 'Symfony'; // Default to Symfony adapter
        return $options;
    }

    /**
     * Disable show internals with var_dump
     *
     * @see https://www.php.net/manual/en/language.oop5.magic.php#object.debuginfo
     * @return array
     */
    public function __debugInfo()
    {
        return [];
    }
}
