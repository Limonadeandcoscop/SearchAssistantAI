<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAI\Service\Prompt\PromptProviderInterface;
use SearchAssistantAI\Service\Prompt\PromptTemplateLoader;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use RuntimeException;
/**
 * Factory for creating QueryProcessorService instances with dependency injection.
 *
 * Implements Laminas ServiceManager FactoryInterface to provide proper
 * dependency injection for the QueryProcessorService. Injects all required
 * services: the configured LLM (LlmInterface) for AI interactions, SemanticSearchService for vector
 * search, Logger for pipeline tracking, and YAML-loaded prompt templates for
 * externalized prompt management.
 */
class QueryProcessorServiceFactory implements FactoryInterface
{
    /**
     * Create and return a QueryProcessorService instance.
     *
     * Retrieves required services from the service container, loads YAML prompt
     * templates from config/prompts directory, and injects them into the
     * QueryProcessorService constructor.
     *
     * @param ContainerInterface $services Service container for dependency retrieval
     * @param string $requestedName Service name being requested
     * @param array<string, mixed>|null $options Additional options (not used)
     * @return QueryProcessorInterface The local RAG pipeline (a delegator may replace it, e.g. the hosted gateway)
     * @throws RuntimeException If YAML templates cannot be loaded
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        // The configured LLM provider, for criteria extraction and response generation
        $claudeService = $services->get(LlmInterface::class);

        // Retrieve SemanticSearchService for vectors in the vector store search operations
        $searchService = $services->get(SemanticSearchService::class);

        // Retrieve Logger for pipeline operation tracking and error logging
        $logger = $services->get('Omeka\Logger');

        // YAML prompt templates from the configured prompt provider.
        $promptService = $services->get(PromptProviderInterface::class);
        $promptTemplates = (new PromptTemplateLoader())->load($promptService);

        // Instantiate and return QueryProcessorService with all dependencies
        return new QueryProcessorService(
            $claudeService,
            $searchService,
            $logger,
            $promptTemplates,
            null,
            $services->get(CriteriaExtractorInterface::class),
            null,
            $conf->getSearchLimit()
        );
    }
}
