<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

/**
 * Re-ranks vector-search candidates before pagination.
 *
 * Resolved through the ResultScorerInterface::class service alias, so another
 * module can point it at its own implementation.
 */
interface ResultScorerInterface
{
    /**
     * @param list<array<string, mixed>> $candidates Each with at least a 'score'
     * @return list<array<string, mixed>> Sorted best first
     */
    public function rank(array $candidates): array;
}
