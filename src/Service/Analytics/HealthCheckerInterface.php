<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

/**
 * External-service probes shown on the admin dashboard. The vector store is
 * probed through VectorStoreInterface by StatsService.
 */
interface HealthCheckerInterface
{
    /**
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     */
    public function checkEmbeddings(): array;

    /**
     * The configured LLM provider.
     *
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     */
    public function checkLlm(): array;
}
