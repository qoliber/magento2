<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * \Magento\Framework\Cache\Core test case
 */
namespace Magento\Framework\Cache\Test\Unit;

use Magento\Framework\Cache\Core;
use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;

class CoreTest extends TestCase
{
    /**
     * @var Core
     */
    protected Core $_core;

    /**
     * @var CacheItemPoolInterface|MockObject
     */
    protected $cachePoolMock;

    protected function setUp(): void
    {
        $this->cachePoolMock = $this->getMockBuilder(CacheItemPoolInterface::class)
            ->getMock();
        $this->_core = new Core($this->cachePoolMock);
    }

    protected function tearDown(): void
    {
        unset($this->cachePoolMock);
        unset($this->_core);
    }

    

    public function testSaveDisabled()
    {
        $this->cachePoolMock->expects($this->never())->method('save');
        $this->_core->save('data', 'id');
        $this->assertTrue(true); // Assert that no exception is thrown
    }

    

    public function testSave()
    {
        $data = 'data';
        $id = 'id';
        $itemMock = $this->getMockBuilder(CacheItemInterface::class)->getMock();

        $this->cachePoolMock->expects($this->once())
            ->method('getItem')
            ->with($this->_core->_id($id))
            ->willReturn($itemMock);

        $itemMock->expects($this->once())
            ->method('set')
            ->with($data);

        $this->cachePoolMock->expects($this->once())
            ->method('save')
            ->with($itemMock)
            ->willReturn(true);

        $result = $this->_core->save($data, $id);
        $this->assertTrue($result);
    }

    public function testClean()
    {
        // Test CLEANING_MODE_ALL
        $this->cachePoolMock->expects($this->once())
            ->method('clear')
            ->willReturn(true);
        $result = $this->_core->clean(FrontendInterface::CLEANING_MODE_ALL);
        $this->assertTrue($result);

        // Test CLEANING_MODE_MATCHING_TAG with tags
        $this->cachePoolMock->expects($this->once())
            ->method('clear')
            ->willReturn(true);
        $result = $this->_core->clean(FrontendInterface::CLEANING_MODE_MATCHING_TAG, ['tag1']);
        $this->assertTrue($result);

        // Test CLEANING_MODE_MATCHING_TAG without tags
        $this->cachePoolMock->expects($this->never())
            ->method('clear');
        $result = $this->_core->clean(FrontendInterface::CLEANING_MODE_MATCHING_TAG, []);
        $this->assertTrue($result);

        // Test CLEANING_MODE_MATCHING_ANY_TAG with tags
        $this->cachePoolMock->expects($this->once())
            ->method('clear')
            ->willReturn(true);
        $result = $this->_core->clean(FrontendInterface::CLEANING_MODE_MATCHING_ANY_TAG, ['tag1', 'tag2']);
        $this->assertTrue($result);

        // Test CLEANING_MODE_MATCHING_ANY_TAG without tags
        $this->cachePoolMock->expects($this->never())
            ->method('clear');
        $result = $this->_core->clean(FrontendInterface::CLEANING_MODE_MATCHING_ANY_TAG, []);
        $this->assertTrue($result);

        // Test unsupported mode
        $result = $this->_core->clean('unsupported_mode');
        $this->assertFalse($result);
    }

    
}
