<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

/**
 * Adds data to the admin dashboard stats (GET /admin/search-assistant/dashboard/stats).
 *
 * Registered as the `stats` service of a `search_assistant.dashboard.panels` entry;
 * the panel's `template` renders the matching tiles.
 */
interface DashboardStatsContributorInterface
{
    /**
     * @return array<string, mixed> Merged into the stats payload (top-level keys)
     */
    public function contribute(): array;
}
