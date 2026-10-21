<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use SearchAssistantAI\Form\ConfigPageHelper;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Vector-store backend selector + per-backend connection fields
 * (Qdrant URL/collection/api-key, Elasticsearch URL/index/api-key/SSL).
 */
class VectorStoreSection implements ConfigFormSection
{
    public function addTo(Form $form): void
    {
        $form->add([
            'name' => 'search_assistant_vector_store_backend',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Vector Store Backend',
                'info' => 'Select the vector database backend for storing embeddings. Switching backends requires a full reindex.',
                'value_options' => [
                    'qdrant' => 'Qdrant',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_vector_store_backend',
                'value' => 'qdrant',
                'required' => true,
            ],
        ]);

        // ── Qdrant ─────────────────────────────────────────────────

        $form->add([
            'name' => 'search_assistant_qdrant_url',
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Qdrant URL',
                'info' => 'URL of the Qdrant vector database (e.g., http://localhost:6333)',
            ],
            'attributes' => [
                'id' => 'search_assistant_qdrant_url',
                'value' => 'http://localhost:6333',
                'required' => true,
                'placeholder' => 'http://localhost:6333',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_qdrant_collection',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Collection Name',
                'info' => 'Name of the Qdrant collection for storing embeddings (alphanumeric and underscores only)',
            ],
            'attributes' => [
                'id' => 'search_assistant_qdrant_collection',
                'value' => SearchAssistantSettings::DEFAULT_COLLECTION,
                'required' => true,
                'placeholder' => SearchAssistantSettings::DEFAULT_COLLECTION,
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_qdrant_api_key',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'API Key (Optional)',
                'info' => 'API key for Qdrant cloud deployments (leave empty for local development)',
            ],
            'attributes' => [
                'id' => 'search_assistant_qdrant_api_key',
                'placeholder' => 'Enter API key for cloud Qdrant',
            ],
        ]);

        // ── Elasticsearch ─────────────────────────────────────────

        ConfigPageHelper::tagSecret($form, ['search_assistant_qdrant_api_key']);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'search_assistant_vector_store_backend' => [
                'required' => true,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['qdrant'],
                            'messages' => ['notInArray' => 'Invalid vector store backend selection'],
                        ],
                    ],
                ],
            ],
            'search_assistant_qdrant_url' => [
                'required' => true,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [['name' => 'Uri', 'options' => ['allowRelative' => false]]],
            ],
            'search_assistant_qdrant_collection' => [
                'required' => true,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name' => 'Regex',
                        'options' => [
                            'pattern' => '/^[a-zA-Z0-9_]+$/',
                            'messages' => ['regexNotMatch' => 'Collection name must contain only alphanumeric characters and underscores'],
                        ],
                    ],
                    ['name' => 'StringLength', 'options' => ['min' => 1, 'max' => 255]],
                ],
            ],
            'search_assistant_qdrant_api_key' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
            ],
        ];
    }
}
