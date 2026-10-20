<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class VectorStoreRegistryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): VectorStoreRegistry
    {
        return new VectorStoreRegistry(
            $services->get('Config')['search_assistant']['vector_store_backends'] ?? [],
            new SearchAssistantSettings($services->get('Omeka\Settings'))
        );
    }
}
