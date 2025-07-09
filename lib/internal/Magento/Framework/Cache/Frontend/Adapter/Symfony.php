<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Symfony Cache adapter for Magento cache frontend interface
 */
class Symfony implements FrontendInterface
{
    private CacheInterface $cache;

    /**
     * Symfony constructor.
     *
     * @param CacheInterface $cache
     */
    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function test($identifier)
    {
        return $this->cache->has($identifier) ? $this->cache->getItem($identifier)->getMetadata()['mtime'] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function load($identifier)
    {
        $item = $this->cache->getItem($identifier);
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function save($data, $identifier, $tags = [], $specificLifetime = false)
    {
        $item = $this->cache->getItem($identifier);
        $item->set($data);

        if ($specificLifetime !== false) {
            $item->expiresAfter($specificLifetime);
        }

        // Symfony Cache does not directly support tags in the same way Zend Cache does.
        // For now, we'll just save the item. Tag-based invalidation will need a different strategy.
        // This might require custom cache invalidation logic or a different Symfony Cache adapter.

        return $this->cache->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($identifier)
    {
        return $this->cache->delete($identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function clean($mode = FrontendInterface::CLEANING_MODE_ALL, array $tags = [])
    {
        switch ($mode) {
            case FrontendInterface::CLEANING_MODE_ALL:
                return $this->cache->clear();
            case FrontendInterface::CLEANING_MODE_MATCHING_TAG:
                // Symfony Cache does not natively support cleaning by matching tags directly.
                // This would require iterating through all items and checking tags, or a custom tag-aware cache pool.
                // For now, we'll clear all cache if tags are provided, as a fallback.
                // A more robust solution would involve a custom cache pool or a tag-aware decorator.
                if (!empty($tags)) {
                    return $this->cache->clear();
                }
                return true; // No tags, nothing to do
            case FrontendInterface::CLEANING_MODE_MATCHING_ANY_TAG:
                // Same as MATCHING_TAG, Symfony Cache doesn't have direct support.
                if (!empty($tags)) {
                    return $this->cache->clear();
                }
                return true;
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
        // Symfony Cache doesn't expose a direct 'backend' object like Zend Cache.
        // Returning the underlying CacheItemPoolInterface for compatibility, if needed.
        if ($this->cache instanceof CacheItemPoolInterface) {
            return $this->cache;
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getLowLevelFrontend()
    {
        
        return $this->cache;
    }
}
