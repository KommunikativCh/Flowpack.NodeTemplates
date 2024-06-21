<?php

declare(strict_types=1);

namespace Flowpack\NodeTemplates\Tests\Functional;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Utility\Arrays;
use Symfony\Component\Yaml\Yaml;

/**
 * @property ObjectManagerInterface $objectManager
 * @property NodeTypeManager $nodeTypeManager
 */
trait FakeNodeTypeManagerTrait
{
    private function loadFakeNodeTypes(): void
    {
        $configuration = $this->objectManager->get(ConfigurationManager::class)->getConfiguration('NodeTypes');

        $fileIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/Features'));

        /** @var \SplFileInfo $fileInfo */
        foreach ($fileIterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'yaml' || strpos($fileInfo->getBasename(), 'NodeTypes.') !== 0) {
                continue;
            }

            $configuration = Arrays::arrayMergeRecursiveOverrule(
                $configuration,
                Yaml::parseFile($fileInfo->getRealPath()) ?? []
            );
        }

        $this->nodeTypeManager->overrideNodeTypes($configuration);
    }
}
