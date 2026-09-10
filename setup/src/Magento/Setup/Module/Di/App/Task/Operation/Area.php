<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
namespace Magento\Setup\Module\Di\App\Task\Operation;

use Magento\Setup\Module\Di\App\Task\OperationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Setup\Module\Di\App\Task\Parallel;
use Magento\Framework\App;
use Magento\Setup\Module\Di\Compiler\Config;
use Magento\Setup\Module\Di\Definition\Collection as DefinitionsCollection;

/**
 * Area configuration aggregation
 */
class Area implements OperationInterface
{
    /**
     * @var App\AreaList
     */
    private $areaList;

    /**
     * @var \Magento\Setup\Module\Di\Code\Reader\Decorator\Area
     */
    private $areaInstancesNamesList;

    /**
     * @var Config\Reader
     */
    private $configReader;

    /**
     * @var \Magento\Framework\App\ObjectManager\ConfigWriterInterface
     */
    private $configWriter;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var ResourceConnection|null
     */
    private $resourceConnection;

    /**
     * @var \Magento\Setup\Module\Di\Compiler\Config\ModificationChain
     */
    private $modificationChain;

    /**
     * @param App\AreaList $areaList
     * @param \Magento\Setup\Module\Di\Code\Reader\Decorator\Area $areaInstancesNamesList
     * @param Config\Reader $configReader
     * @param \Magento\Framework\App\ObjectManager\ConfigWriterInterface $configWriter
     * @param \Magento\Setup\Module\Di\Compiler\Config\ModificationChain $modificationChain
     * @param array $data
     */
    public function __construct(
        App\AreaList $areaList,
        \Magento\Setup\Module\Di\Code\Reader\Decorator\Area $areaInstancesNamesList,
        Config\Reader $configReader,
        \Magento\Framework\App\ObjectManager\ConfigWriterInterface $configWriter,
        Config\ModificationChain $modificationChain,
        $data = [],
        ?ResourceConnection $resourceConnection = null
    ) {
        $this->areaList = $areaList;
        $this->areaInstancesNamesList = $areaInstancesNamesList;
        $this->configReader = $configReader;
        $this->configWriter = $configWriter;
        $this->data = $data;
        $this->modificationChain = $modificationChain;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritdoc
     */
    public function doOperation()
    {
        if (empty($this->data)) {
            return;
        }

        // Accepts both the historical shape - a plain list of path groups - and a keyed array.
        $singleProcess = (bool)($this->data['single_process'] ?? false);
        $pathGroups = $this->data['paths'] ?? $this->data;

        $definitionsCollection = new DefinitionsCollection();
        foreach ($pathGroups as $paths) {
            if (!is_array($paths)) {
                $paths = (array)$paths;
            }
            foreach ($paths as $path) {
                $definitionsCollection->addCollection($this->getDefinitionsCollection($path));
            }
        }

        $this->sortDefinitions($definitionsCollection);

        $areaCodes = array_merge([App\Area::AREA_GLOBAL], $this->areaList->getCodes());

        // Compilation has just deleted the cache directory, and the configuration cache recreates
        // it lazily the first time a non-global area is loaded. Workers must not race to create
        // it, so areas are processed here until one of them has been through that path; only the
        // remainder is handed to workers.
        while ($areaCodes) {
            $areaCode = array_shift($areaCodes);
            $this->configReader->applyThirdPartyInterfaces($definitionsCollection, $areaCode);
            $this->processArea($areaCode, $definitionsCollection);
            if ($areaCode !== App\Area::AREA_GLOBAL) {
                break;
            }
        }

        // Areas are not independent: generateCachePerScope() back-fills third-party preferences
        // into the shared collection, so each area sees the keys added by the areas before it.
        // The prepare step replays that back-fill in the original order in this process, so a
        // worker starts from exactly the collection its area would have had sequentially.
        Parallel::each(
            // Keyed by area so a failing worker is reported by name rather than by position.
            array_combine($areaCodes, $areaCodes),
            function ($areaCode) use ($definitionsCollection) {
                $this->processArea($areaCode, $definitionsCollection);
            },
            $singleProcess ? 1 : Parallel::workerCount(count($areaCodes)),
            function ($areaCode) use ($definitionsCollection) {
                $this->configReader->applyThirdPartyInterfaces($definitionsCollection, $areaCode);
            },
            function () {
                $this->closeInheritedConnections();
            }
        );
    }

    /**
     * Drop connections inherited from the parent process.
     *
     * A worker inherits every descriptor the parent had open. The compiler cleans the cache before
     * any of this runs, so each worker then misses on every configuration key and writes its own
     * payload back - down the parent's socket, if the backend is a networked one. Reconnecting
     * gives each worker its own.
     *
     * @return void
     */
    private function closeInheritedConnections()
    {
        if ($this->resourceConnection !== null) {
            $this->resourceConnection->closeConnection();
        }
    }

    /**
     * Build, modify and write the compiled DI configuration for a single area.
     *
     * @param string $areaCode
     * @param DefinitionsCollection $definitionsCollection
     * @return void
     */
    private function processArea($areaCode, DefinitionsCollection $definitionsCollection)
    {
        $config = $this->configReader->generateCachePerScope($definitionsCollection, $areaCode);
        $config = $this->modificationChain->modify($config);

        // sort configuration to have it in the same order on every build
        ksort($config['arguments']);
        ksort($config['preferences']);
        ksort($config['instanceTypes']);

        $this->configWriter->write($areaCode, $config);
    }

    /**
     * Returns definitions collection
     *
     * @param string $path
     * @return DefinitionsCollection
     */
    protected function getDefinitionsCollection($path)
    {
        $definitions = new DefinitionsCollection();
        foreach ($this->areaInstancesNamesList->getList($path) as $className => $constructorArguments) {
            $definitions->addDefinition($className, $constructorArguments);
        }
        return $definitions;
    }

    /**
     * Returns operation name
     *
     * @return string
     */
    public function getName()
    {
        return 'Area configuration aggregation';
    }

    /**
     * Sort definitions to make reproducible result
     *
     * @param DefinitionsCollection $definitionsCollection
     */
    private function sortDefinitions(DefinitionsCollection $definitionsCollection): void
    {
        $definitions = $definitionsCollection->getCollection();

        ksort($definitions);

        $definitionsCollection->initialize($definitions);
    }
}
