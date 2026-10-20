<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Embeddings service URL + API format + model + vector dimension. Dimension must match the
 * model the embeddings service is running; changing it requires
 * clearing the vector store and reindexing.
 */
class EmbeddingSection implements ConfigFormSection
{
    private const MAX_DIMENSION = 8192;

    public function addTo(Form $form): void
    {
        $form->add([
            'name' => 'search_assistant_embeddings_url',
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Embeddings Service URL',
                'info' => 'URL of the embeddings generation service (e.g., http://localhost:8000)',
            ],
            'attributes' => [
                'id' => 'search_assistant_embeddings_url',
                'value' => 'http://localhost:8000',
                'required' => true,
                'placeholder' => 'http://localhost:8000',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_embeddings_api_format',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Embeddings API format',
                'info' => '"Native" posts {"text": [...]} to /embed. "OpenAI-compatible" posts {"model", "input"} to /v1/embeddings (vLLM, TEI, Ollama...).',
                'value_options' => [
                    'native' => 'Native (/embed)',
                    'openai' => 'OpenAI-compatible (/v1/embeddings)',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_embeddings_api_format',
                'value' => 'native',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_embeddings_model',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Embeddings Model',
                'info' => 'Model name sent to the server (required for the OpenAI-compatible format, e.g. BAAI/bge-multilingual-gemma2). Ignored by the native format.',
            ],
            'attributes' => [
                'id' => 'search_assistant_embeddings_model',
                'placeholder' => 'BAAI/bge-m3',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_embedding_dimension',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Embedding Vector Dimension',
                'info' => 'Size of the embedding vectors produced by the embeddings service. Must match the model in use (e.g., 384 for all-MiniLM-L6-v2, 768 for all-mpnet-base-v2, 1024 for BAAI/bge-m3 or bge-large, 1536 for text-embedding-3-small). Changing this value requires clearing the vector store collection and running a full reindex.',
            ],
            'attributes' => [
                'id' => 'search_assistant_embedding_dimension',
                'value' => 1024,
                'min' => 1,
                'max' => self::MAX_DIMENSION,
                'step' => 1,
                'required' => true,
            ],
        ]);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'search_assistant_embeddings_url' => [
                'required' => true,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [['name' => 'Uri', 'options' => ['allowRelative' => false]]],
            ],
            'search_assistant_embeddings_api_format' => [
                'required' => false,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['native', 'openai'],
                            'messages' => ['notInArray' => 'Invalid embeddings API format'],
                        ],
                    ],
                ],
            ],
            'search_assistant_embeddings_model' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name' => 'Regex',
                        'options' => [
                            'pattern' => '#^[a-zA-Z0-9._:/-]*$#',
                            'messages' => ['regexNotMatch' => 'Model name must contain only alphanumeric characters, dots, underscores, colons, slashes, and hyphens'],
                        ],
                    ],
                ],
            ],
            'search_assistant_embedding_dimension' => [
                'required' => true,
                'filters' => [['name' => 'ToInt']],
                'validators' => [
                    [
                        'name' => 'Between',
                        'options' => [
                            'min' => 1,
                            'max' => self::MAX_DIMENSION,
                            'inclusive' => true,
                            'messages' => ['notBetween' => 'Embedding dimension must be between 1 and ' . self::MAX_DIMENSION],
                        ],
                    ],
                ],
            ],
        ];
    }
}
