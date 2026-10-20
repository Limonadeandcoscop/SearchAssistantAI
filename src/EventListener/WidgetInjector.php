<?php
declare(strict_types=1);

namespace SearchAssistantAI\EventListener;

use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use Interop\Container\ContainerInterface;
use SearchAssistantAI\Event\ChatEvents;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Router\RouteMatch;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Settings\Settings;

/**
 * Inject the search assistant widget + stylesheet into public-site pages during
 * the `view.layout` event, without requiring site themes to call a view
 * helper in their layout template.
 *
 * Two modes:
 *   - Dedicated /search-assistant route: loads the full search-assistant-page assets
 *     (bigger CSS/JS, dedicated UI).
 *   - Public site pages when `search_assistant_widget_enabled` is on — the
 *     configured homepage only, or every site page, depending on
 *     `search_assistant_widget_display`: loads the floating-widget assets and
 *     appends the rendered widget HTML to document.body on DOMContentLoaded.
 *
 * Admin routes are unconditionally skipped. Extracted from Module.php
 * during the v1.1 refactor.
 */
class WidgetInjector
{
    private const DEFAULT_WELCOME = 'Bonjour ! Comment puis-je vous aider ?';

    /**
     * Bump this when shipping new widget CSS/JS so browsers pull the
     * fresh copy rather than a stale cached one. Compared in-memory
     * with `asset/` mtimes — purely a string, no code read.
     */
    private const CACHE_BUST = '&cb=20261018a';

    private ContainerInterface $services;

    /** Shared event manager, used to trigger ChatEvents::WIDGET_RENDER. */
    private ?SharedEventManagerInterface $sharedEvents = null;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function attach(SharedEventManagerInterface $events): void
    {
        $this->sharedEvents = $events;
        // Wildcard identifier: view.layout fires from multiple sources
        // (the controller, the view helper trigger, …); the module needs
        // to catch them all.
        $events->attach('*', 'view.layout', fn ($e) => $this->onLayout($e));
    }

    public function onLayout(Event $event): void
    {
        $settings = $this->services->get('Omeka\Settings');
        $verbose = (bool) $settings->get('search_assistant_verbose_logging', false);

        $routeMatch = $this->services->get('Application')->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : 'no-route';

        if ($verbose) {
            error_log("[SearchAssistantAI] injectWidget called, route: {$routeName}");
        }

        // Admin routes are off-limits — the widget is public-site only.
        if (!$routeMatch || strpos($routeName, 'admin') !== false) {
            if ($verbose) {
                error_log('[SearchAssistantAI] Skipping widget injection: admin route or no route match');
            }
            return;
        }

        // Listeners may take over a page (e.g. the dedicated /search-assistant page
        // loads its own assets) and keep the floating widget off it.
        if ($this->widgetSkippedByListener($event, $routeName)) {
            if ($verbose) {
                error_log("[SearchAssistantAI] Widget skipped by a widget.render listener (route: {$routeName})");
            }
            return;
        }

        // Admin-configured display scope: every public site page, or the
        // homepage only (default). 'all' still requires a site route, so
        // the login page and the top-level site listing never get the widget.
        if ($settings->get('search_assistant_widget_display', 'homepage') === 'all') {
            $onSitePage = $routeName === 'site' || strpos($routeName, 'site/') === 0;
            if (!$onSitePage) {
                if ($verbose) {
                    error_log("[SearchAssistantAI] Skipping widget injection: not a site route (route: {$routeName})");
                }
                return;
            }
        } else {
            // The bare `site` route is the site index (no homepage configured).
            // Otherwise match against the site's *configured* homepage rather
            // than a hardcoded slug, so the widget works on any instance no
            // matter what the landing page is named.
            $onHomepage = $routeName === 'site'
                || $this->isConfiguredHomepage($routeMatch, $routeName, $verbose);
            if (!$onHomepage) {
                if ($verbose) {
                    error_log("[SearchAssistantAI] Skipping widget injection: not homepage (route: {$routeName})");
                }
                return;
            }
        }

        if (!$settings->get('search_assistant_widget_enabled', false)) {
            if ($verbose) {
                error_log('[SearchAssistantAI] Widget disabled, skipping injection');
            }
            return;
        }

        // Access policy (e.g. signed-in users only).
        if (!$this->services->get(ChatAccessPolicyInterface::class)->canChat()) {
            if ($verbose) {
                error_log('[SearchAssistantAI] Chat access policy denies this visitor, skipping injection');
            }
            return;
        }

        $this->loadWidgetAssets($event, $settings);
        if ($verbose) {
            error_log('[SearchAssistantAI] Assets and widget HTML injected into page');
        }
    }

    /**
     * Is the current request the site's configured homepage?
     *
     * Omeka forwards `/s/:site-slug` to the `site/page` route carrying the
     * homepage's `page-slug`, so we load the site and compare that slug to
     * `$site->homepage()` instead of assuming the page is named `home`.
     * Any failure (site/homepage unresolved) is treated as "not homepage".
     */
    private function isConfiguredHomepage(RouteMatch $routeMatch, string $routeName, bool $verbose): bool
    {
        if ($routeName !== 'site/page') {
            return false;
        }

        $pageSlug = $routeMatch->getParam('page-slug');
        $siteSlug = $routeMatch->getParam('site-slug');
        if ($pageSlug === null || $siteSlug === null) {
            return false;
        }

        try {
            $site = $this->services->get('Omeka\ApiManager')
                ->read('sites', ['slug' => $siteSlug])->getContent();
        } catch (\Exception $e) {
            if ($verbose) {
                error_log("[SearchAssistantAI] Could not resolve site '{$siteSlug}': " . $e->getMessage());
            }
            return false;
        }

        $homepage = $site->homepage();
        if ($homepage === null) {
            if ($verbose) {
                error_log("[SearchAssistantAI] Site '{$siteSlug}' has no configured homepage");
            }
            return false;
        }

        $isHome = $homepage->slug() === $pageSlug;
        if ($verbose) {
            error_log(sprintf(
                "[SearchAssistantAI] Homepage check: current page '%s' vs configured homepage '%s' => %s",
                $pageSlug,
                $homepage->slug(),
                $isHome ? 'match' : 'no match'
            ));
        }

        return $isHome;
    }

    /**
     * Narrow the view.layout target to a PhpRenderer. Most of the time
     * it will be, but the Event API exposes getTarget() as mixed so a
     * type guard keeps static analysis honest and guards against the
     * rare third-party hook firing this event from a non-view context.
     */
    private function viewFromEvent(Event $event): ?PhpRenderer
    {
        $target = $event->getTarget();
        return $target instanceof PhpRenderer ? $target : null;
    }

    /**
     * Trigger ChatEvents::WIDGET_RENDER; true when a listener set `skip`.
     */
    private function widgetSkippedByListener(Event $event, string $routeName): bool
    {
        if ($this->sharedEvents === null) {
            return false;
        }
        $events = new EventManager($this->sharedEvents, [self::class]);
        $renderEvent = new Event(ChatEvents::WIDGET_RENDER, $this, [
            'view' => $this->viewFromEvent($event),
            'routeName' => $routeName,
            'skip' => false,
        ]);
        $events->triggerEvent($renderEvent);
        return (bool) $renderEvent->getParam('skip');
    }

    private function loadWidgetAssets(Event $event, Settings $settings): void
    {
        // Using the event-target view (not service-manager lookup) so the
        // headLink/headScript changes attach to the SAME renderer the
        // layout template is about to print.
        $view = $this->viewFromEvent($event);
        if ($view === null) {
            return;
        }
        $assetUrl = $view->plugin('assetUrl');

        $view->headLink()->appendStylesheet($assetUrl('css/search-assistant-widget.css', 'SearchAssistantAI') . self::CACHE_BUST);

        foreach ([
            'vendor/marked.min.js',
            'vendor/purify.min.js',
            'js/search-assistant-widget.js',
        ] as $script) {
            $view->headScript()->appendFile(
                $assetUrl($script, 'SearchAssistantAI') . self::CACHE_BUST,
                'text/javascript',
                ['defer' => 'defer']
            );
        }

        $view->htmlElement('body')->appendAttribute('class', 'search-assistant-widget-active');

        $translate = $view->plugin('translate');
        $widgetHtml = $view->partial('search-assistant-ai/site/widget-body', [
            'position' => $settings->get('search_assistant_widget_position', 'bottom-right'),
            'color' => $settings->get('search_assistant_primary_color', '#b74420'),
            'apiUrl' => $settings->get('search_assistant_api_url', '/search-assistant/api/query'),
            'welcomeMessage' => $settings->get('search_assistant_welcome_message', $translate(self::DEFAULT_WELCOME)),
        ]);

        // Inject the widget HTML via inline script appending to body. This
        // avoids requiring site themes to invoke a view helper in their
        // layout; the HTML is embedded inside a JSON-encoded string so
        // script-tag escaping stays safe under any input.
        $escapedHtml = json_encode($widgetHtml);
        $injectScript = <<<JS
(function () {
    function injectSearchAssistantWidget() {
        var div = document.createElement('div');
        div.innerHTML = {$escapedHtml};
        while (div.firstChild) {
            document.body.appendChild(div.firstChild);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectSearchAssistantWidget);
    } else {
        injectSearchAssistantWidget();
    }
})();
JS;
        $view->headScript()->appendScript($injectScript);
    }
}
