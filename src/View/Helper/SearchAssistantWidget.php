<?php
declare(strict_types=1);
namespace SearchAssistantAI\View\Helper;

use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Settings\Settings;

/**
 * View helper for rendering the search assistant widget.
 */
class SearchAssistantWidget extends AbstractHelper
{
    /**
     * @var Settings
     */
    protected $settings;

    /**
     * Optional cache storage. Typed loosely as object|null because
     * laminas-cache isn't a hard dependency of this module — the field
     * is populated by a caller that has it, or left null otherwise.
     *
     * @var object|null
     */
    protected $cache;

    /**
     * Who may see the widget. Null-safe for older call sites that don't
     * inject it (those allow everyone).
     *
     * @var ChatAccessPolicyInterface|null
     */
    protected $accessPolicy;

    /**
     * Construct the search assistant widget helper.
     *
     * @param Settings $settings
     * @param object|null $cache Optional cache storage (duck-typed: must
     *        expose getItem/setItem/getOptions — Laminas\Cache\Storage\StorageInterface compatible)
     * @param ChatAccessPolicyInterface|null $accessPolicy Who may see the widget; null allows everyone.
     */
    public function __construct(Settings $settings, $cache = null, ?ChatAccessPolicyInterface $accessPolicy = null)
    {
        $this->settings = $settings;
        $this->cache = $cache;
        $this->accessPolicy = $accessPolicy;
    }

    /**
     * Check if the search assistant widget is enabled for the current viewer.
     *
     * Honours the global on/off switch (`search_assistant_widget_enabled`) and the
     * chat access policy (e.g. signed-in users only).
     *
     * @return bool True if widget should render, false otherwise.
     */
    public function isEnabled()
    {
        if (!(bool) $this->settings->get('search_assistant_widget_enabled', false)) {
            return false;
        }
        return $this->accessPolicy === null || $this->accessPolicy->canChat();
    }

    /**
     * Render the search assistant widget HTML.
     *
     * This method loads the necessary CSS and JavaScript assets
     * and renders the widget partial template.
     *
     * @return string The rendered widget HTML, or empty string if disabled
     */
    public function renderWidget()
    {
        if (!$this->isEnabled()) {
            return '';
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $this->getView();

        // Read configuration settings
        $position = $this->settings->get('search_assistant_widget_position', 'bottom-right');
        $color = $this->settings->get('search_assistant_primary_color', '#b74420');
        $apiUrl = $this->settings->get('search_assistant_api_url', '/search-assistant/api/query');
        $translate = $view->plugin('translate');
        $welcomeMessage = (string) $this->settings->get('search_assistant_welcome_message', '') ?: $translate('Hello! How can I help you?');

        // Try to get from cache if available
        if ($this->cache !== null) {
            // Generate cache key based on configuration hash
            $cacheKey = 'search_assistant_widget_' . md5(serialize([
                'enabled' => $this->settings->get('search_assistant_widget_enabled'),
                'position' => $position,
                'color' => $color,
                'api_url' => $apiUrl,
                'welcome' => $welcomeMessage,
            ]));

            $cachedHtml = $this->cache->getItem($cacheKey);
            if ($cachedHtml !== null) {
                return $cachedHtml;
            }
        }

        // Pass configuration to template
        $html = $view->partial('search-assistant-ai/site/widget', [
            'position' => $position,
            'color' => $color,
            'apiUrl' => $apiUrl,
            'welcomeMessage' => $welcomeMessage,
        ]);

        // Store in cache if available
        if ($this->cache !== null) {
            // Set TTL before storing (3600 seconds = 1 hour)
            $options = $this->cache->getOptions();
            $options->setTtl(3600);
            $this->cache->setItem($cacheKey, $html);
        }

        return $html;
    }

    /**
     * Render the search assistant widget.
     *
     * @return string
     */
    public function __invoke()
    {
        return $this->renderWidget();
    }
}
