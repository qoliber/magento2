<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Cache;

use Psr\Cache\CacheItemPoolInterface;

class Core implements FrontendInterface
{
    /**
     * @var CacheItemPoolInterface
     */
    protected CacheItemPoolInterface $cachePool;

    /**
     * @param CacheItemPoolInterface $cachePool
     */
    public function __construct(CacheItemPoolInterface $cachePool)
    {
        $this->cachePool = $cachePool;
    }

    /**
     * {@inheritdoc}
     */
    public function test($identifier)
    {
        $item = $this->cachePool->getItem($this->_id($identifier));
        return $item->isHit() ? ($item->getMetadata()['mtime'] ?? time()) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function load($identifier)
    {
        $item = $this->cachePool->getItem($this->_id($identifier));
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $item = $this->cachePool->getItem($this->_id($identifier));
        $item->set($data);

        if ($lifeTime !== null) {
            $item->expiresAfter($lifeTime);
        }

        // Symfony Cache does not directly support tags in the same way Zend Cache does.
        // For now, we'll just save the item. Tag-based invalidation will need a different strategy.
        // This might require custom cache invalidation logic or a different Symfony Cache adapter.

        return $this->cachePool->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($identifier)
    {
        return $this->cachePool->delete($this->_id($identifier));
    }

    /**
     * {@inheritdoc}
     */
    public function clean($mode = FrontendInterface::CLEANING_MODE_ALL, array $tags = [])
    {
        switch ($mode) {
            case FrontendInterface::CLEANING_MODE_ALL:
                return $this->cachePool->clear();
            case FrontendInterface::CLEANING_MODE_MATCHING_TAG:
            case FrontendInterface::CLEANING_MODE_MATCHING_ANY_TAG:
                // Symfony Cache does not natively support cleaning by matching tags directly.
                // For now, we'll clear all cache if tags are provided, as a fallback.
                // A more robust solution would involve a custom cache pool or a tag-aware decorator.
                if (!empty($tags)) {
                    return $this->cachePool->clear();
                }
                return true; // No tags, nothing to do
            default:
                // Other modes like CLEANING_MODE_OLD or NOT_MATCHING_TAG are not directly supported by Symfony Cache
                // and would require custom logic or a different cache pool implementation.
                return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getBackend()
    {
        return $this->cachePool;
    }

    /**
     * {@inheritdoc}
     */
    public function getLowLevelFrontend()
    {
        return $this->cachePool;
    }

    /**
     * Make and return a cache id
     *
     * Checks 'cache_id_prefix' and returns new id with prefix or simply the id if null
     *
     * @param  string $cacheId Cache id
     * @return string Cache id (with or without prefix)
     */
    protected function _id($cacheId)
    {
        if ($cacheId !== null) {
            $cacheId = str_replace('.', '__', $cacheId); //reduce collision chances
            $cacheId = preg_replace('/([^a-zA-Z0-9_]{1,1})/', '_', $cacheId);
            // The cache_id_prefix is now handled by the Symfony Cache adapter's namespace.
            // So, we don't need to prepend it here.
        }
        return $cacheId;
    }

    /**
     * Prepare tags
     *
     * @param string[] $tags
     * @return string[]
     */
    protected function _tags($tags)
    {
        foreach ($tags as $key => $tag) {
            $tags[$key] = $this->_id($tag);
        }
        return $tags;
    }

    /**
     * Return an array of stored cache ids
     *
     * @return string[] array of stored cache ids (string)
     */
    public function getIds()
    {
        // Symfony Cache (PSR-6) does not provide a direct way to list all IDs.
        // This functionality might require a custom cache adapter or be limited.
        return [];
    }

    /**
     * Return an array of stored tags
     *
     * @return string[] array of stored tags (string)
     */
    public function getTags()
    {
        // Symfony Cache (PSR-6) does not directly support listing all tags.
        return [];
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param string[] $tags array of tags
     * @return string[] array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = [])
    {
        // Symfony Cache (PSR-6) does not natively support cleaning by matching tags directly.
        return [];
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param string[] $tags array of tags
     * @return string[] array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = [])
    {
        // Symfony Cache (PSR-6) does not natively support cleaning by matching tags directly.
        return [];
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param string[] $tags array of tags
     * @return string[] array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = [])
    {
        // Symfony Cache (PSR-6) does not natively support cleaning by matching tags directly.
        return [];
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        // PSR-6 does not provide a way to get filling percentage.
        return 100;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $cacheId cache id
     * @return array|bool array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($cacheId)
    {
        $item = $this->cachePool->getItem($this->_id($cacheId));
        if ($item->isHit()) {
            $metadata = $item->getMetadata();
            return [
                'expire' => $metadata['expiry'] ?? 0,
                'mtime' => $metadata['mtime'] ?? time(),
                'tags' => [], // Tags are not directly supported by PSR-6 metadata
            ];
        }
        return false;
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $cacheId cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($cacheId, $extraLifetime)
    {
        $item = $this->cachePool->getItem($this->_id($cacheId));
        if ($item->isHit()) {
            $item->expiresAfter($extraLifetime);
            return $this->cachePool->save($item);
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return [
            'automatic_cleaning' => true,
            'tags' => false, // PSR-6 does not natively support tags
            'expired_read' => false,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => false // PSR-6 does not provide a way to list all IDs
        ];
    }

    /**
     * Set an option
     *
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setOption($name, $value)
    {
        // Options are typically set during adapter instantiation in Symfony Cache.
        // This method might become a no-op or throw an exception if unsupported options are passed.
    }

    /**
     * Get the life time
     *
     * if $specificLifetime is not false, the given specific life time is used
     * else, the global lifetime is used
     *
     * @param  int $specificLifetime
     * @return int Cache life time
     */
    public function getLifetime($specificLifetime)
    {
        // Lifetime is handled by expiresAfter in Symfony Cache items.
        // This method might become a no-op or return a default.
        return $specificLifetime ?: 0; // Return 0 for infinite, or the specific lifetime
    }

    /**
     * Determine system TMP directory and detect if we have read access
     *
     * inspired from \Zend_File_Transfer_Adapter_Abstract
     *
     * @return string
     */
    public function getTmpDir()
    {
        // This is typically handled by the underlying Symfony Cache adapter (e.g., FilesystemAdapter).
        return sys_get_temp_dir();
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
