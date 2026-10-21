<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form;

use Interop\Container\ContainerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Settings\Settings;
use SearchAssistantAI\Settings\SearchAssistantSettings;

/**
 * Render + submission-handler glue for the module configure page
 * (`/admin/module/configure?id=SearchAssistantAI`).
 *
 * Extracted from Module.php to keep the module bootstrap focused on
 * install/upgrade/listener wiring. Owns three concerns that were
 * previously tangled with the rest of Module.php:
 *
 *   1. Fetching + pre-filtering the ConfigForm (keeping only the fields
 *      of this page, see ConfigPageHelper).
 *   2. Rendering the form grouped into fieldsets + subgroups (layout
 *      from the `search_assistant.config_form` registry), with a
 *      small amount of CSS + JS loaded from asset files rather than
 *      heredoc strings.
 *   3. Validating + persisting form submissions, including the
 *      backend-switched "please reindex" warning and the empty-password
 *      preservation policy for API-key fields.
 */
class ConfigFormRenderer
{
    private ContainerInterface $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function render(PhpRenderer $renderer): string
    {
        $settings = $this->services->get('Omeka\Settings');
        $form = $this->services->get('FormElementManager')->get(ConfigForm::class);

        ConfigPageHelper::filterForPage($form, ConfigPageHelper::PAGE_CONFIGURE);
        $form->setData(ConfigPageHelper::load($settings, $form));

        return $this->referenceAssets($renderer)
            . $this->renderSections($form, $renderer)
            . $this->renderRemaining($form, $renderer);
    }

    /**
     * Validate submitted data and persist settings. Returns false (with
     * form errors set on the messenger) if validation fails.
     */
    public function handleSubmission(AbstractController $controller): bool
    {
        $settings = $this->services->get('Omeka\Settings');
        $form = $this->services->get('FormElementManager')->get(ConfigForm::class);

        ConfigPageHelper::filterForPage($form, ConfigPageHelper::PAGE_CONFIGURE);
        $form->setData($controller->params()->fromPost());

        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }

        $data = $form->getData();

        $this->warnOnBackendSwitch($data, $settings, $controller);

        // Secrets keep their stored value on an empty submission (the UI
        // masks them); checkboxes are saved as booleans.
        ConfigPageHelper::persist($settings, $form, $data);

        // Probe the vector store on the next request, even right after a failure.
        $settings->delete(SearchAssistantSettings::KEY_COLLECTION_RETRY_AT);

        return true;
    }

    // ────────────────────────────────────────────────────────────────
    // internals
    // ────────────────────────────────────────────────────────────────

    /**
     * Flip the `search_assistant_collection_verified` flag and flash a warning when
     * the admin changes a setting of the `reindex_watch` registry: where
     * vectors are stored (backend, URL, collection) or how they are computed
     * (embedding model, dimension). The flag triggers a collection-existence
     * probe + create on the next request. The first configuration only
     * flips the flag: there is no index yet to rebuild.
     *
     * @param array<string, mixed> $data
     */
    private function warnOnBackendSwitch(array $data, Settings $settings, AbstractController $controller): void
    {
        $watched = $this->formConfig()['reindex_watch'] ?? [];
        $changed = false;
        foreach ($watched as $key => $default) {
            // Compared as strings: the form gives "768" or 768 where settings hold 768.
            if (isset($data[$key]) && (string) $data[$key] !== (string) $settings->get($key, $default)) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return;
        }
        $firstConfiguration = $settings->get(SearchAssistantSettings::KEY_BACKEND) === null;
        $settings->set('search_assistant_collection_verified', false);
        if ($firstConfiguration) {
            return;
        }
        $translator = $this->services->get('MvcTranslator');
        $controller->messenger()->addWarning(
            $translator->translate('Vector store settings changed. Run a full reindex so that search uses the new settings.')
        );
    }

    /**
     * Stylesheets and scripts of the `search_assistant.config_form.assets` registry:
     * entry => [path, module] (module defaults to Search Assistant AI).
     */
    private function referenceAssets(PhpRenderer $renderer): string
    {
        $assetUrl = $renderer->plugin('assetUrl');
        $html = '';
        foreach ($this->formConfig()['assets'] ?? [] as $asset) {
            $path = (string) ($asset[0] ?? '');
            $url = $renderer->escapeHtmlAttr($assetUrl($path, (string) ($asset[1] ?? 'SearchAssistantAI')));
            $html .= substr($path, -4) === '.css'
                ? sprintf('<link rel="stylesheet" href="%s">' . "\n", $url)
                : sprintf('<script src="%s" defer></script>' . "\n", $url);
        }
        return $html;
    }

    private function renderSections(\Laminas\Form\Form $form, PhpRenderer $renderer): string
    {
        $html = '';
        $rendered = [];
        $translator = $this->services->get('MvcTranslator');

        // Returns [html, whether a visible row was rendered]. Hidden fields
        // (e.g. a registry select with a single choice) get no row.
        $renderFields = static function (array $fieldNames) use ($form, $renderer, &$rendered): array {
            $out = '';
            $visible = false;
            foreach ($fieldNames as $field) {
                if (!$form->has($field)) {
                    continue;
                }
                $element = $form->get($field);
                if ($element instanceof \Laminas\Form\Element\Hidden) {
                    /** @var \Laminas\Form\View\Helper\FormHidden $hidden */
                    $hidden = $renderer->plugin('formHidden');
                    $out .= $hidden($element);
                } else {
                    $out .= $renderer->formRow($element);
                    $visible = true;
                }
                $rendered[$field] = true;
            }
            return [$out, $visible];
        };

        foreach ($this->sortedLayout() as $section) {
            [$sectionHtml, $visible] = $renderFields($section['fields'] ?? []);

            foreach ($section['subgroups'] ?? [] as $sub) {
                [$subHtml, $subVisible] = $renderFields($sub['fields'] ?? []);
                if ($subHtml === '') {
                    continue;
                }
                // No separator above the first subgroup when nothing is shown
                // above it (inline, so a cached stylesheet cannot bring it back).
                $first = $visible ? '' : ' style="border-top: 0; margin-top: 0; padding-top: 0;"';
                $visible = $visible || $subVisible;
                $sectionHtml .= sprintf(
                    '<div id="%s" class="search-assistant-config-subgroup"%s><div class="search-assistant-config-subgroup-title">%s</div>%s</div>',
                    $renderer->escapeHtmlAttr($sub['id']),
                    $first,
                    $renderer->escapeHtml($translator->translate($sub['title'])),
                    $subHtml
                );
            }

            if ($sectionHtml === '') {
                continue;
            }

            $html .= sprintf(
                '<fieldset id="%s" class="search-assistant-config-section"><legend>%s</legend>%s</fieldset>',
                $renderer->escapeHtmlAttr($section['id']),
                $renderer->escapeHtml($translator->translate($section['title'])),
                $sectionHtml
            );
        }

        $this->renderedFields = $rendered;
        return $html;
    }

    /**
     * Names of fields already rendered by renderSections() — used by renderRemaining().
     *
     * @var array<string, bool>
     */
    private array $renderedFields = [];

    /**
     * Render any leftover elements (CSRF token, hidden fields, fields a
     * section adds without a layout entry) so the form stays functional
     * even if the layout falls behind the form definition.
     */
    private function renderRemaining(\Laminas\Form\Form $form, PhpRenderer $renderer): string
    {
        $html = '';
        foreach ($form->getElements() as $element) {
            $name = $element->getName();
            if (isset($this->renderedFields[$name])) {
                continue;
            }
            $html .= $renderer->formRow($element);
        }
        return $html;
    }

    /**
     * @return array<string, mixed> The `search_assistant.config_form` registry
     */
    private function formConfig(): array
    {
        return $this->services->get('Config')['search_assistant']['config_form'] ?? [];
    }

    /**
     * Layout sections sorted by their 'order' (stable for equal orders).
     *
     * @return list<array<string, mixed>>
     */
    private function sortedLayout(): array
    {
        $sections = array_values($this->formConfig()['layout'] ?? []);
        $positions = array_keys($sections);
        array_multisort(
            array_map(static function (array $section): int {
                return (int) ($section['order'] ?? 1000);
            }, $sections),
            $positions,
            $sections
        );
        return $sections;
    }
}
