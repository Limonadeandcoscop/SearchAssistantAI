<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class VectorStoreBootstrapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): VectorStoreBootstrap
    {
        return new VectorStoreBootstrap(
            $services->get(VectorStoreRegistry::class),
            $services->get('Omeka\Settings')
        );
    }
}
