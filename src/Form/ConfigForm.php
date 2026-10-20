<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form;

use SearchAssistantAI\Form\Section\ConfigFormSection;
use Laminas\Form\Element\Hidden;
use Laminas\Form\Element\Select;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

/**
 * Configuration form for the Search Assistant AI module.
 *
 * Acts as a slim assembler over the sections listed in the
 * `search_assistant.config_form.sections` registry, each implementing
 * {@see ConfigFormSection} and owning its field definitions, validators
 * and page metadata (see ConfigPageHelper). CSRF is added automatically by Omeka via getForm().
 *
 * Sections add elements directly to this form (no nested Fieldset
 * wrapping), so the form data shape stays flat: controllers iterate
 * `$form->getElements()` by name and persist into Omeka settings keyed
 * by `search_assistant_*`. Splitting via composition rather than Fieldsets is
 * deliberate — a real Fieldset would prefix every field name and
 * break the persistence contract used by ConfigController + Module's
 * handleConfigForm().
 */
class ConfigForm extends Form implements InputFilterProviderInterface
{
    /** Form option holding the section classes (see ConfigFormFactory). */
    public const OPTION_SECTIONS = 'search_assistant_sections';

    /** Form option holding select choices from the registries: field => [value => label]. */
    public const OPTION_CHOICES = 'search_assistant_choices';

    /** @var list<ConfigFormSection> Instantiated sections in display order. */
    private array $sections = [];

    public function init(): void
    {
        foreach ($this->sectionClasses() as $class) {
            $section = new $class();
            $this->sections[] = $section;
            $section->addTo($this);
        }
        foreach ($this->choices() as $field => $labels) {
            $element = $this->has($field) ? $this->get($field) : null;
            if (!$element instanceof Select) {
                continue;
            }
            if (count($labels) === 1) {
                // Nothing to choose: keep the value, hide the field.
                $this->remove($field);
                $this->add([
                    'type' => Hidden::class,
                    'name' => $field,
                    'options' => $element->getOptions(),
                    'attributes' => ['id' => $element->getAttribute('id') ?: $field, 'value' => (string) key($labels)],
                ]);
                continue;
            }
            $element->setValueOptions($labels);
        }
    }

    /**
     * Section classes from the `search_assistant.config_form.sections` registry,
     * injected by ConfigFormFactory; read from the module config when the
     * form is built directly (tests, scripts).
     *
     * @return list<class-string<ConfigFormSection>>
     */
    private function sectionClasses(): array
    {
        $sections = $this->getOption(self::OPTION_SECTIONS);
        if (!is_array($sections)) {
            $config = require dirname(__DIR__, 2) . '/config/module.config.php';
            $sections = $config['search_assistant']['config_form']['sections'];
        }
        return array_values($sections);
    }

    /**
     * Choices of the registry-driven selects: LLM providers and vector-store
     * backends, labelled as registered.
     *
     * @return array<string, array<string, string>>
     */
    private function choices(): array
    {
        $choices = $this->getOption(self::OPTION_CHOICES);
        if (is_array($choices)) {
            return $choices;
        }
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return self::choicesFromConfig($config['search_assistant']);
    }

    /**
     * @param array<string, mixed> $searchAssistantConfig The `search assistant` config key
     * @return array<string, array<string, string>>
     */
    public static function choicesFromConfig(array $searchAssistantConfig): array
    {
        $labels = static function (array $registry): array {
            $out = [];
            foreach ($registry as $key => $entry) {
                $out[(string) $key] = (string) ($entry['label'] ?? ucfirst((string) $key));
            }
            return $out;
        };
        return [
            'search_assistant_llm_provider' => $labels($searchAssistantConfig['llm_providers'] ?? []),
            'search_assistant_vector_store_backend' => $labels($searchAssistantConfig['vector_store_backends'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputFilterSpecification(): array
    {
        $spec = [];
        foreach ($this->sections as $section) {
            $spec += $section->getInputFilterSpecification();
        }
        // Registry-driven selects accept exactly the registered values.
        foreach ($this->choices() as $field => $labels) {
            foreach ($spec[$field]['validators'] ?? [] as $i => $validator) {
                if (($validator['name'] ?? '') === 'InArray') {
                    $spec[$field]['validators'][$i]['options']['haystack'] = array_keys($labels);
                }
            }
            if (count($labels) === 1 && isset($spec[$field])) {
                // Hidden single choice: store that choice whatever was loaded
                // (e.g. a provider of a module that is no longer active).
                $only = (string) key($labels);
                $spec[$field]['filters'][] = [
                    'name' => 'Callback',
                    'options' => ['callback' => static function () use ($only): string {
                        return $only;
                    }],
                ];
            }
        }
        return $spec;
    }
}
