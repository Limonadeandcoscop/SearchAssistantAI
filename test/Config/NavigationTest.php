<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Config;

use Laminas\Stdlib\ArrayUtils;
use PHPUnit\Framework\TestCase;

/**
 * The admin menu uses string keys, so pages another module adds land in
 * the Search Assistant AI menu instead of becoming separate top-level entries.
 */
class NavigationTest extends TestCase
{
    public function testAnotherModuleCanAddAPageToTheSearchAssistantMenu(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $addon = ['navigation' => ['AdminModule' => ['search_assistant' => ['pages' => [
            'analytics' => ['label' => 'Analytics', 'route' => 'admin/search-assistant/analytics', 'order' => 25],
        ]]]]];

        // Same merge as Laminas' ConfigListener (modules load alphabetically).
        $merged = ArrayUtils::merge($config, $addon);
        $menus = $merged['navigation']['AdminModule'];

        self::assertCount(1, $menus, 'still one Search Assistant AI entry');
        self::assertSame(
            ['dashboard', 'interface', 'analytics'],
            array_keys($menus['search_assistant']['pages'])
        );
        self::assertSame('Search Assistant AI', $menus['search_assistant']['label']);
    }
}
