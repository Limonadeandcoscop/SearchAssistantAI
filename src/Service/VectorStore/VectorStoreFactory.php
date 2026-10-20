<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for the configured vector-store backend (VectorStoreInterface).
 *
 * Resolves the backend from the `search_assistant.vector_store_backends` registry
 * (see VectorStoreRegistry).
 */
class VectorStoreFactory implements FactoryInterface
{
    public const CLIENT_TIMEOUT = 5.0;

    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): VectorStoreInterface
    {
        /** @var VectorStoreRegistry $registry */
        $registry = $services->get(VectorStoreRegistry::class);
        return $services->get($registry->getServiceName());
    }
}
