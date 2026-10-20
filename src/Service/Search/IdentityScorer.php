<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

/**
 * No re-ranking: candidates keep the vector store's similarity order.
 */
class IdentityScorer implements ResultScorerInterface
{
    public function rank(array $candidates): array
    {
        return array_values($candidates);
    }
}
