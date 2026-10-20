<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

/**
 * One service probe for the admin dashboard (e.g. an LLM provider).
 * Registered as the `health` service of an entry in a config registry.
 */
interface HealthCheckInterface
{
    /**
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     *         status is 'connected', 'disconnected' or 'not_configured'
     */
    public function check(): array;
}
