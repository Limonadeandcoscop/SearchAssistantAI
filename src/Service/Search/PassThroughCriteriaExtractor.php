<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

use InvalidArgumentException;

/**
 * No criteria extraction: the question itself is embedded and searched,
 * without filters and without an extra LLM call.
 */
class PassThroughCriteriaExtractor implements CriteriaExtractorInterface
{
    public function extract(string $userQuery): array
    {
        if (trim($userQuery) === '') {
            throw new InvalidArgumentException('User query cannot be empty');
        }
        return ['keywords' => [], 'raw' => trim($userQuery)];
    }

    public function buildSearchQuery(array $criteria): string
    {
        if (isset($criteria['raw']) && is_string($criteria['raw']) && $criteria['raw'] !== '') {
            return $criteria['raw'];
        }
        $keywords = $criteria['keywords'] ?? [];
        return is_array($keywords) ? implode(' ', $keywords) : '';
    }

    public function buildSearchFilters(array $criteria): array
    {
        return [];
    }
}
