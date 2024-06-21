<?php

namespace Flowpack\NodeTemplates\Tests\Functional;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManager;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;

/**
 * @property ObjectManager $objectManager
 */
trait ContentRepositoryTestTrait
{
    private readonly ContentRepository $contentRepository;

    private readonly ContentRepositoryId $contentRepositoryId;

    private static bool $persistenceWasSetup = false;

    private static bool $wasContentRepositorySetupCalled = false;

    private function initCleanContentRepository(ContentRepositoryId $contentRepositoryId): void
    {
        if (!self::$persistenceWasSetup) {
            // TODO super hacky and as we never clean up !!!
            $persistenceManager = $this->objectManager->get(PersistenceManager::class);
            if (is_callable([$persistenceManager, 'compile'])) {
                $result = $persistenceManager->compile();
                if ($result === false) {
                    self::markTestSkipped('Test skipped because setting up the persistence failed.');
                }
            }
            self::$persistenceWasSetup = true;
        }

        $this->contentRepositoryId = $contentRepositoryId;

        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $registrySettings = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepositoryRegistry'
        );

        $contentRepositoryRegistry = new ContentRepositoryRegistry(
            $registrySettings,
            $this->objectManager
        );

        $this->contentRepository = $contentRepositoryRegistry->get($this->contentRepositoryId);
        // Performance optimization: only run the setup once
        if (!self::$wasContentRepositorySetupCalled) {
            $this->contentRepository->setUp();
            self::$wasContentRepositorySetupCalled = true;
        }

        $connection = $this->objectManager->get(Connection::class);

        // reset events and projections
        $eventTableName = sprintf('cr_%s_events', $this->contentRepositoryId->value);
        $connection->executeStatement('TRUNCATE ' . $eventTableName);
        $this->contentRepository->resetProjectionStates();
    }
}
