<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form\Section;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * LLM provider selector + self-hosted connection fields: URL, model name
 * (free text, since users pull arbitrary models) and API format (native
 * Ollama or OpenAI-compatible, e.g. vLLM). The provider choices come from the
 * `search_assistant.llm_providers` registry (see ConfigForm); other providers bring
 * their own section.
 */
class LlmSection implements ConfigFormSection
{
    public function addTo(Form $form): void
    {
        $form->add([
            'name' => 'search_assistant_llm_provider',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'LLM Provider',
                'info' => 'Select the LLM provider for generating search assistant responses',
                'value_options' => [
                    'ollama' => 'Self-hosted (Ollama or OpenAI-compatible)',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_llm_provider',
                'value' => 'ollama',
                'required' => true,
            ],
        ]);

        // ── Claude ────────────────────────────────────────────────

        // ── Ollama ────────────────────────────────────────────────

        $form->add([
            'name' => 'search_assistant_llm_api_format',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Self-hosted LLM API format',
                'info' => 'API spoken by the server. "Ollama" uses /api/chat and /api/tags. "OpenAI-compatible" uses /v1/chat/completions and /v1/models (vLLM, TGI, LiteLLM, Ollama).',
                'value_options' => [
                    'ollama' => 'Ollama (native API)',
                    'openai' => 'OpenAI-compatible (vLLM, TGI...)',
                ],
            ],
            'attributes' => [
                'id' => 'search_assistant_llm_api_format',
                'value' => 'ollama',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_ollama_url', // internal key kept for BC
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Self-hosted LLM URL',
                'info' => 'URL of your self-hosted LLM. Ollama: http://localhost:11434 when it runs natively on the host (macOS/Linux), http://ollama:11434 when it runs in Docker on the same compose network. OpenAI-compatible: the server base URL, with or without /v1 (e.g. http://vllm:8000).',
            ],
            'attributes' => [
                'id' => 'search_assistant_ollama_url',
                'value' => 'http://localhost:11434',
                'placeholder' => 'http://localhost:11434',
            ],
        ]);

        $form->add([
            'name' => 'search_assistant_ollama_model',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Self-hosted LLM Model',
                'info' => 'Name of the model served by your self-hosted LLM (e.g., llama3.2, mistral for Ollama; google/gemma-3-12b-it for vLLM).',
            ],
            'attributes' => [
                'id' => 'search_assistant_ollama_model',
                'value' => 'llama3.2',
                'placeholder' => 'llama3.2',
            ],
        ]);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'search_assistant_llm_provider' => [
                'required' => true,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['ollama'],
                            'messages' => ['notInArray' => 'Invalid LLM provider selection'],
                        ],
                    ],
                ],
            ],
            'search_assistant_llm_api_format' => [
                'required' => false,
                'validators' => [
                    [
                        'name' => 'InArray',
                        'options' => [
                            'haystack' => ['ollama', 'openai'],
                            'messages' => ['notInArray' => 'Invalid LLM API format'],
                        ],
                    ],
                ],
            ],
            'search_assistant_ollama_url' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [['name' => 'Uri', 'options' => ['allowRelative' => false]]],
            ],
            'search_assistant_ollama_model' => [
                'required' => false,
                'filters' => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 1, 'max' => 255,
                            'messages' => [
                                'stringLengthTooShort' => 'Model name must not be empty',
                                'stringLengthTooLong' => 'Model name must not exceed 255 characters',
                            ],
                        ],
                    ],
                    [
                        'name' => 'Regex',
                        'options' => [
                            'pattern' => '#^[a-zA-Z0-9._:/-]+$#',
                            'messages' => ['regexNotMatch' => 'Model name must contain only alphanumeric characters, dots, underscores, colons, slashes, and hyphens'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
