<?php
declare(strict_types=1);

namespace SearchAssistantAI\Controller\Site;

use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use SearchAssistantAI\Service\Search\QueryProcessorService;
use SearchAssistantAI\Service\RateLimit\RateLimitService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for creating ChatController instances with dependency injection.
 *
 * Implements Laminas ServiceManager FactoryInterface to provide proper
 * dependency injection for the ChatController. Injects all required
 * services: QueryProcessorService for query processing, RateLimitService for
 * rate limiting,
 * Logger for request tracking, and Settings for configuration management.
 */
class ChatControllerFactory implements FactoryInterface
{
    /**
     * Create and return a ChatController instance.
     *
     * Retrieves required services from the service container and injects them
     * into the ChatController constructor. This factory is registered in
     * module.config.php under controllers['factories'].
     *
     * @param ContainerInterface $services Service container for dependency retrieval
     * @param string $requestedName Service name being requested
     * @param array<string, mixed>|null $options Additional options (not used)
     * @return ChatController Configured ChatController instance
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Retrieve QueryProcessorService for semantic search query processing
        $queryProcessorService = $services->get(QueryProcessorService::class);

        // Retrieve RateLimitService for API rate limiting
        $rateLimitService = $services->get(RateLimitService::class);

        // Retrieve Logger for request tracking and error logging
        $logger = $services->get('Omeka\Logger');

        // Retrieve Settings for configuration management
        $settings = $services->get('Omeka\Settings');

        // Instantiate and return ChatController with all dependencies
        return new ChatController(
            $queryProcessorService,
            $rateLimitService,
            $logger,
            $settings,
            $services->get(ChatAccessPolicyInterface::class)
        );
    }
}
