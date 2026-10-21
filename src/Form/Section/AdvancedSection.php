<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * "Advanced settings": verbose logging. Search limit, queue processing and
 * rate limits keep their defaults unless an add-on module makes them
 * editable.
 */
class AdvancedSection implements ConfigFormSection
{
    public function addTo(Form $form): void
    {
        $form->add([
            'name' => 'search_assistant_verbose_logging',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Verbose Logging',
                'info' => 'Log detailed debugging information (useful for troubleshooting)',
            ],
            'attributes' => ['id' => 'search_assistant_verbose_logging'],
        ]);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'search_assistant_verbose_logging' => ['required' => false],
        ];
    }
}
