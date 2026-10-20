<?php
declare(strict_types=1);

namespace SearchAssistantAI\Controller\Admin;

use SearchAssistantAI\Service\Analytics\DashboardStatsContributorInterface;
use Interop\Container\ContainerInterface;

/**
 * Resolves the `search_assistant.dashboard.panels` registry for DashboardController:
 * the panel templates sorted by 'order', and their data providers.
 */
final class DashboardControllerPanels
{
    /**
     * @return array{0: list<array{template: string}>, 1: list<DashboardStatsContributorInterface>}
     */
    public static function resolve(ContainerInterface $services): array
    {
        $panels = array_values($services->get('Config')['search_assistant']['dashboard']['panels'] ?? []);
        usort($panels, static function (array $a, array $b): int {
            return ((int) ($a['order'] ?? 1000)) <=> ((int) ($b['order'] ?? 1000));
        });

        $templates = [];
        $contributors = [];
        foreach ($panels as $panel) {
            if (!empty($panel['template'])) {
                $templates[] = ['template' => (string) $panel['template']];
            }
            if (!empty($panel['stats'])) {
                $contributor = $services->get($panel['stats']);
                if ($contributor instanceof DashboardStatsContributorInterface) {
                    $contributors[] = $contributor;
                }
            }
        }
        return [$templates, $contributors];
    }
}
