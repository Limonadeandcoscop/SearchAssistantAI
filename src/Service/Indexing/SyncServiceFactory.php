<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SyncServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): SyncService
    {
        $entityManager = $services->get('Omeka\EntityManager');

        return new SyncService(
            $entityManager,
            $services->get('Omeka\Logger'),
            $services->get(VectorIndexService::class),
            $services->get('Omeka\Job\Dispatcher'),
            $services->get('Omeka\Settings'),
            new SyncQueueRepository($entityManager->getConnection())
        );
    }
}
