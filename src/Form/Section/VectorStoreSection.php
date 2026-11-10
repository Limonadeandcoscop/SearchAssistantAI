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

        $form->add([
            'name' => 'search_assistant_es_url',
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Elasticsearch URL',
                'info' => 'URL of the Elasticsearch server. Use http://elasticsearch:9200 for Docker deployments or https://localhost:9200 for local development with security enabled.',
            ],
            'attributes' => [
                'id' => 'search_assistant_es_url',
                'value' => SearchAssistantSettings::DEFAULT_ES_URL,
                'placeholder' => SearchAssistantSettings::DEFAULT_ES_URL,
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_es_index',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Elasticsearch Index Name',
                'info' => 'Name of the Elasticsearch index for storing embeddings (lowercase, alphanumeric, hyphens, and underscores only)',
            ],
            'attributes' => [
                'id' => 'search_assistant_es_index',
                'value' => SearchAssistantSettings::DEFAULT_COLLECTION,
                'placeholder' => SearchAssistantSettings::DEFAULT_COLLECTION,
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_es_api_key',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'Elasticsearch API Key (Optional)',
                'info' => 'API key for Elasticsearch authentication (leave empty if security is disabled)',
            ],
            'attributes' => [
                'id' => 'search_assistant_es_api_key',
                'placeholder' => 'Enter API key for Elasticsearch',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_es_verify_ssl',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Verify SSL Certificate',
                'info' => 'Whether to verify SSL certificates for Elasticsearch connections. Disable for Docker deployments with xpack.security.enabled=false or self-signed certificates.',
            ],
            'attributes' => [
                'id' => 'search_assistant_es_verify_ssl',
                'value' => '0',
            ],
        ]);

        ConfigPageHelper::tagSecret($form, ['search_assistant_qdrant_api_key', 'search_assistant_es_api_key']);
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
            'search_assistant_es_url' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [['name' => 'Uri', 'options' => ['allowRelative' => false]]],
            ],
            'search_assistant_es_index' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name' => 'Regex',
                        'options' => [
                            'pattern' => '/^[a-z0-9_-]+$/',
                            'messages' => ['regexNotMatch' => 'Index name must contain only lowercase alphanumeric characters, hyphens, and underscores'],
                        ],
                    ],
                    ['name' => 'StringLength', 'options' => ['min' => 1, 'max' => 255]],
                ],
            ],
            'search_assistant_es_api_key' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
            ],
            'search_assistant_es_verify_ssl' => ['required' => false],
        ];
    }
}
