<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

/**
 * Step 1 of the RAG pipeline: turn the visitor's question into search
 * criteria, then into the query string and filters sent to the vector search.
 *
 * Resolved through the CriteriaExtractorInterface::class service alias, so
 * another module can point it at its own implementation.
 */
interface CriteriaExtractorInterface
{
    /**
     * @return array<string, mixed> Criteria; 'keywords' (list<string>) is always present
     */
    public function extract(string $userQuery): array;

    /**
     * @param array<string, mixed> $criteria
     */
    public function buildSearchQuery(array $criteria): string;

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed> Filters accepted by SemanticSearchInterface::search()
     */
    public function buildSearchFilters(array $criteria): array;
}
