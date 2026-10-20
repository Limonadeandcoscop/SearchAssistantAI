<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\RateLimit;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class RateLimitServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): RateLimitService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        return new RateLimitService(
            $conf->getRateLimitMaxRequests(),
            $conf->getRateLimitWindowSeconds(),
            $conf->getRateLimitStorageDir()
        );
    }
}
