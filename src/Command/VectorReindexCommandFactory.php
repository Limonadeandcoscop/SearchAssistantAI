<?php
declare(strict_types=1);

namespace SearchAssistantAI\Command;

use SearchAssistantAI\Service\Indexing\VectorIndexService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class VectorReindexCommandFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): VectorReindexCommand
    {
        return new VectorReindexCommand(
            $services->get(VectorIndexService::class),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\EntityManager')
        );
    }
}
