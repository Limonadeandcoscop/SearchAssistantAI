<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Analytics;

use SearchAssistantAI\Controller\Admin\DashboardControllerPanels;
use SearchAssistantAI\Service\Analytics\DashboardStatsContributorInterface;
use SearchAssistantAITest\Support\ModuleServices;
use PHPUnit\Framework\TestCase;

class DashboardPanelsTest extends TestCase
{
    public function testPanelsAreSortedAndResolved(): void
    {
        $first = $this->createMock(DashboardStatsContributorInterface::class);
        $second = $this->createMock(DashboardStatsContributorInterface::class);
        $services = new ModuleServices(
            ['First\Stats' => $first, 'Second\Stats' => $second],
            ['search_assistant' => ['dashboard' => ['panels' => [
                'second' => ['order' => 20, 'template' => 'second/panel', 'stats' => 'Second\Stats'],
                'first' => ['order' => 5, 'template' => 'first/panel', 'stats' => 'First\Stats'],
            ]]]]
        );

        [$templates, $contributors] = DashboardControllerPanels::resolve($services);

        self::assertSame([['template' => 'first/panel'], ['template' => 'second/panel']], $templates);
        self::assertSame([$first, $second], $contributors);
    }

    public function testFreeDashboardHasNoPanels(): void
    {
        self::assertSame([[], []], DashboardControllerPanels::resolve(new ModuleServices([])));
    }
}
