<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use SearchAssistantAI\Form\ConfigPageHelper;
use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Public-facing interface customisation: widget toggle, dedicated-page
 * toggle, widget position, primary colour, welcome message — plus the
 * two hidden fields that carry the indexed-property and indexed-collection
 * selections (populated by JS on /admin/search-assistant/config).
 */
class InterfaceSection implements ConfigFormSection
{
    public function addTo(Form $form): void
    {
        $form->add([
            'name' => 'search_assistant_widget_enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Floating Widget',
                'info' => 'Display a floating search assistant widget on public pages',
            ],
            'attributes' => [
                'id' => 'search_assistant_widget_enabled',
                'value' => '1',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_widget_display',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Display On',
                'info' => 'Pages where the floating widget is shown',
                'value_options' => [
                    'homepage' => 'Homepage only',
                    'all' => 'All site pages',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_widget_display',
                'value' => 'homepage',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_widget_position',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Widget Position',
                'info' => 'Position of the floating widget on the page',
                'value_options' => [
                    'bottom-right' => 'Bottom Right',
                    'bottom-left' => 'Bottom Left',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_widget_position',
                'value' => 'bottom-right',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_primary_color',
            'type' => Element\Color::class,
            'options' => [
                'label' => 'Primary Color',
                'info' => 'Primary color for the search assistant interface (hex color code)',
            ],
            'attributes' => [
                'id' => 'search_assistant_primary_color',
                'value' => '#b74420',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_welcome_message',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Welcome Message',
                'info' => 'Initial greeting message displayed to users (max 500 characters)',
            ],
            'attributes' => [
                'id' => 'search_assistant_welcome_message',
                'value' => 'Hello! How can I help you?',
                'rows' => 3,
                'maxlength' => 500,
                'placeholder' => 'Enter a friendly welcome message...',
            ],
        ]);

        // Hidden fields — populated by the indexing-field selector JS on
        // /admin/search-assistant/config. JSON arrays of integer IDs.
        $form->add([
            'name' => 'search_assistant_indexed_properties',
            'type' => Element\Hidden::class,
            'attributes' => [
                'id' => 'search_assistant_indexed_properties',
                'value' => '[]',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_indexed_collections',
            'type' => Element\Hidden::class,
            'attributes' => [
                'id' => 'search_assistant_indexed_collections',
                'value' => '[]',
            ],
        ]);

        ConfigPageHelper::tagPage($form, [
            'search_assistant_widget_enabled',
            'search_assistant_widget_display',
            'search_assistant_widget_position',
            'search_assistant_primary_color',
            'search_assistant_welcome_message',
        ], ConfigPageHelper::PAGE_INTERFACE);
        ConfigPageHelper::tagPage($form, ['search_assistant_indexed_properties', 'search_assistant_indexed_collections'], ConfigPageHelper::PAGE_INDEXING);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'search_assistant_widget_enabled' => ['required' => false],
            'search_assistant_widget_display' => [
                'required' => false,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['homepage', 'all'],
                            'messages' => ['notInArray' => 'Invalid widget display mode'],
                        ],
                    ],
                ],
            ],
            'search_assistant_widget_position' => [
                'required' => false,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['bottom-right', 'bottom-left'],
                            'messages' => ['notInArray' => 'Invalid widget position'],
                        ],
                    ],
                ],
            ],
            'search_assistant_primary_color' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name' => 'Regex',
                        'options' => [
                            'pattern' => '/^#[0-9A-Fa-f]{6}$/',
                            'messages' => ['regexNotMatch' => 'Invalid hex color code (must be in format #RRGGBB)'],
                        ],
                    ],
                ],
            ],
            'search_assistant_welcome_message' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim'], ['name' => 'StripTags']],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'max' => 500,
                            'messages' => ['stringLengthTooLong' => 'Welcome message must not exceed 500 characters'],
                        ],
                    ],
                ],
            ],
            'search_assistant_indexed_properties' => self::jsonIntArraySpec('property'),
            'search_assistant_indexed_collections' => self::jsonIntArraySpec('collection'),
        ];
    }

    /**
     * Validator spec for the two hidden fields that carry a JSON array
     * of integer IDs. Empty / "[]" allowed. Built once instead of
     * duplicating the closure body twice.
     *
     * @return array<string, mixed>
     */
    private static function jsonIntArraySpec(string $kind): array
    {
        return [
            'required' => false,
            'filters' => [['name' => 'StringTrim']],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'callback' => static function ($value): bool {
                            if (empty($value) || $value === '[]') {
                                return true;
                            }
                            $decoded = json_decode((string) $value, true);
                            if (!is_array($decoded)) {
                                return false;
                            }
                            foreach ($decoded as $id) {
                                if (!is_int($id) && !ctype_digit((string) $id)) {
                                    return false;
                                }
                            }
                            return true;
                        },
                        'messages' => ['callbackValue' => "Invalid {$kind} selection format"],
                    ],
                ],
            ],
        ];
    }
}
