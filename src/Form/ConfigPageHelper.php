<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form;

use Laminas\Form\Element;
use Laminas\Form\ElementInterface;
use Laminas\Form\Form;
use Omeka\Settings\Settings;

/**
 * Which ConfigForm fields belong to which admin page, and how they are
 * loaded and saved.
 *
 * Each element carries its own metadata in its options, set by the
 * section that adds it:
 *   - `search_assistant_page`: PAGE_CONFIGURE (default), PAGE_INTERFACE,
 *     PAGE_INDEXING or PAGE_HIDDEN (kept in settings, shown nowhere);
 *   - `search_assistant_secret`: true for API keys (an empty submission keeps the
 *     stored value, since the UI masks them).
 * Checkboxes are saved as booleans.
 */
final class ConfigPageHelper
{
    /** Module configure page (/admin/module/configure?id=SearchAssistantAI): infrastructure. */
    public const PAGE_CONFIGURE = 'configure';
    /** /admin/search-assistant/config: widget settings. */
    public const PAGE_INTERFACE = 'interface';
    /** /admin/search-assistant/indexing: indexed properties and item sets. */
    public const PAGE_INDEXING = 'indexing';
    /** Kept in settings but shown on no page. */
    public const PAGE_HIDDEN = 'hidden';

    public const OPTION_PAGE = 'search_assistant_page';
    public const OPTION_SECRET = 'search_assistant_secret';

    /**
     * @param list<string> $fields
     */
    public static function tagPage(Form $form, array $fields, string $page): void
    {
        foreach ($fields as $field) {
            if ($form->has($field)) {
                $form->get($field)->setOption(self::OPTION_PAGE, $page);
            }
        }
    }

    /**
     * @param list<string> $fields
     */
    public static function tagSecret(Form $form, array $fields): void
    {
        foreach ($fields as $field) {
            if ($form->has($field)) {
                $form->get($field)->setOption(self::OPTION_SECRET, true);
            }
        }
    }

    public static function pageOf(ElementInterface $element): string
    {
        return (string) ($element->getOption(self::OPTION_PAGE) ?: self::PAGE_CONFIGURE);
    }

    /**
     * Remove every field (element and input filter) that is not on $page.
     * The CSRF element is kept.
     */
    public static function filterForPage(Form $form, string $page): void
    {
        $inputFilter = $form->getInputFilter();
        foreach ($form->getElements() as $element) {
            if ($element instanceof Element\Csrf) {
                continue;
            }
            if (self::pageOf($element) === $page) {
                continue;
            }
            $name = $element->getName();
            $form->remove($name);
            if ($inputFilter->has($name)) {
                $inputFilter->remove($name);
            }
        }
    }

    /**
     * Current values of the form's fields, from settings (element default otherwise).
     *
     * @return array<string, mixed>
     */
    public static function load(Settings $settings, Form $form): array
    {
        $data = [];
        foreach ($form->getElements() as $element) {
            if ($element instanceof Element\Csrf) {
                continue;
            }
            $name = $element->getName();
            $data[$name] = $settings->get($name, $element->getValue());
        }
        return $data;
    }

    /**
     * Save validated form data to settings.
     *
     * @param array<string, mixed> $data
     */
    public static function persist(Settings $settings, Form $form, array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$form->has($key)) {
                continue;
            }
            $element = $form->get($key);
            if ($element instanceof Element\Csrf) {
                continue;
            }
            if ($element->getOption(self::OPTION_SECRET) && $value === '') {
                continue;
            }
            if ($element instanceof Element\Checkbox) {
                $value = !empty($value);
            }
            $settings->set($key, $value);
        }
    }
}
