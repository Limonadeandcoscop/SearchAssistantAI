<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Config;

use Laminas\Http\Request;
use Laminas\Router\Http\TreeRouteStack;
use PHPUnit\Framework\TestCase;

/**
 * Admin routes of the module, mounted under a stub of Omeka's `admin` route.
 */
class RoutesTest extends TestCase
{
    /** @var TreeRouteStack */
    private $router;

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';

        $this->router = TreeRouteStack::factory([
            'routes' => [
                'admin' => [
                    'type' => 'Literal',
                    'options' => ['route' => '/admin'],
                    'may_terminate' => true,
                    'child_routes' => $config['router']['routes']['admin']['child_routes'],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function reindexActions(): array
    {
        return [
            'index (bare)' => ['/admin/search-assistant/reindex', 'index'],
            'preview' => ['/admin/search-assistant/reindex/preview', 'preview'],
            'launch' => ['/admin/search-assistant/reindex/launch', 'launch'],
            'progress' => ['/admin/search-assistant/reindex/progress', 'progress'],
            'cancel' => ['/admin/search-assistant/reindex/cancel', 'cancel'],
        ];
    }

    /**
     * @dataProvider reindexActions
     */
    public function testReindexUrlsReachTheirAction(string $path, string $action): void
    {
        $request = new Request();
        $request->setUri('http://localhost' . $path);

        $match = $this->router->match($request);

        self::assertNotNull($match, "{$path} should match a route");
        self::assertSame('admin/search-assistant/reindex', $match->getMatchedRouteName());
        self::assertSame('Reindex', $match->getParam('controller'));
        self::assertSame($action, $match->getParam('action'));
    }

    public function testViewUrlHelperBuildsActionUrls(): void
    {
        foreach (['preview', 'launch', 'progress', 'cancel'] as $action) {
            self::assertSame(
                '/admin/search-assistant/reindex/' . $action,
                $this->router->assemble(['action' => $action], ['name' => 'admin/search-assistant/reindex'])
            );
        }
    }

    public function testUnknownReindexActionDoesNotMatch(): void
    {
        $request = new Request();
        $request->setUri('http://localhost/admin/search-assistant/reindex/delete-everything');

        self::assertNull($this->router->match($request));
    }
}
