<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Form;

use SearchAssistantAI\Form\ConfigForm;
use PHPUnit\Framework\TestCase;

/**
 * Focused tests for the post-v1.1 ConfigForm.
 *
 * The form is a thin assembler over five Section classes that each add their
 * own elements and input-filter specs. This test covers the contract we
 * actually rely on:
 *
 *   1. every field the ConfigController / Module::handleConfigForm() reads
 *      from the form is present
 *   2. the input filter validates a realistic dataset and rejects the
 *      representative bad-input cases per section
 *
 * The previous 1.9k-line version exhaustively tested Laminas element types,
 * label strings, validator chain ordering, and a `csrf` element that the
 * framework names `configform_csrf` automatically — all framework-surface
 * details we don't need to pin.
 */
class ConfigFormTest extends TestCase
{
    private ConfigForm $form;

    protected function setUp(): void
    {
        $this->form = new ConfigForm();
        $this->form->init();
    }

    // ─── assembly ────────────────────────────────────────────────

    public function testFormExposesAllExpectedElements(): void
    {
        foreach ($this->expectedFields() as $name) {
            self::assertTrue(
                $this->form->has($name),
                "ConfigForm should expose element '{$name}'"
            );
        }
    }

    public function testFreshFormDefaults(): void
    {
        // The runtime treats unset settings as off: the form must show them off too.
        foreach (['search_assistant_verbose_logging', 'search_assistant_widget_enabled'] as $name) {
            $checkbox = $this->form->get($name);
            self::assertInstanceOf(\Laminas\Form\Element\Checkbox::class, $checkbox);
            self::assertFalse($checkbox->isChecked(), $name);
        }
        // Empty = the default greeting, translated for each visitor.
        self::assertEmpty($this->form->get('search_assistant_welcome_message')->getValue());
        self::assertSame(
            \SearchAssistantAI\Settings\SearchAssistantSettings::DEFAULT_COLLECTION,
            $this->form->get('search_assistant_qdrant_collection')->getValue()
        );
    }

    // ─── validation: happy path ─────────────────────────────────

    public function testValidDatasetPasses(): void
    {
        $this->form->setData($this->validData());
        self::assertTrue($this->form->isValid(), $this->formatMessages());
    }

    // ─── validation: vector-store section ────────────────────────

    public function testSingleChoiceSelectsAreHidden(): void
    {
        foreach (['search_assistant_vector_store_backend' => 'qdrant', 'search_assistant_llm_provider' => 'ollama'] as $field => $only) {
            $element = $this->form->get($field);
            self::assertInstanceOf(\Laminas\Form\Element\Hidden::class, $element, $field);
            self::assertSame($only, $element->getValue(), $field);
            self::assertSame($field, $element->getAttribute('id'), $field);
        }
    }

    public function testHiddenSingleChoiceAlwaysSavesThatChoice(): void
    {
        // e.g. a provider stored by a module that is no longer active
        $this->form->setData($this->validData([
            'search_assistant_vector_store_backend' => 'pinecone',
            'search_assistant_llm_provider' => 'claude',
        ]));

        self::assertTrue($this->form->isValid(), $this->formatMessages());
        self::assertSame('qdrant', $this->form->getData()['search_assistant_vector_store_backend']);
        self::assertSame('ollama', $this->form->getData()['search_assistant_llm_provider']);
    }

    public function testRejectsInvalidQdrantUrl(): void
    {
        $this->form->setData($this->validData(['search_assistant_qdrant_url' => 'not-a-url']));
        self::assertFalse($this->form->isValid());
    }

    public function testRejectsQdrantCollectionWithSpecialChars(): void
    {
        $this->form->setData($this->validData(['search_assistant_qdrant_collection' => 'bad name!']));
        self::assertFalse($this->form->isValid());
    }

    // ─── validation: advanced section numeric bounds ─────────────

    // ─── validation: API formats (OpenAI-compatible support) ─────

    public function testAcceptsOpenAiFormatsWithSlashedModelNames(): void
    {
        $this->form->setData($this->validData([
            'search_assistant_llm_provider' => 'ollama',
            'search_assistant_llm_api_format' => 'openai',
            'search_assistant_ollama_url' => 'http://vllm:8000',
            'search_assistant_ollama_model' => 'google/gemma-3-12b-it',
            'search_assistant_embeddings_api_format' => 'openai',
            'search_assistant_embeddings_model' => 'BAAI/bge-multilingual-gemma2',
        ]));
        self::assertTrue($this->form->isValid(), $this->formatMessages());
    }

    public function testFormatFieldsAreOptional(): void
    {
        // Existing installs submit forms without the new keys.
        $data = $this->validData();
        unset($data['search_assistant_llm_api_format'], $data['search_assistant_embeddings_api_format'], $data['search_assistant_embeddings_model']);
        $this->form->setData($data);
        self::assertTrue($this->form->isValid(), $this->formatMessages());
    }

    public function testRejectsUnknownLlmApiFormat(): void
    {
        $this->form->setData($this->validData(['search_assistant_llm_api_format' => 'grpc']));
        self::assertFalse($this->form->isValid());
    }

    public function testRejectsUnknownEmbeddingsApiFormat(): void
    {
        $this->form->setData($this->validData(['search_assistant_embeddings_api_format' => 'grpc']));
        self::assertFalse($this->form->isValid());
    }

    public function testRejectsEmbeddingsModelWithSpaces(): void
    {
        $this->form->setData($this->validData(['search_assistant_embeddings_model' => 'bad model']));
        self::assertFalse($this->form->isValid());
    }

    // ─── helpers ─────────────────────────────────────────────────

    /**
     * The contract the ConfigController + Module::handleConfigForm() rely on.
     * If a field here disappears, the persistence loop silently stops saving
     * it — keep this list in sync with the Section classes.
     *
     * @return list<string>
     */
    private function expectedFields(): array
    {
        return [
            'search_assistant_vector_store_backend',
            'search_assistant_qdrant_url', 'search_assistant_qdrant_collection', 'search_assistant_qdrant_api_key',
            'search_assistant_embeddings_url', 'search_assistant_embeddings_api_format', 'search_assistant_embeddings_model',
            'search_assistant_embedding_dimension',
            'search_assistant_llm_provider', 'search_assistant_llm_api_format',
            'search_assistant_ollama_url', 'search_assistant_ollama_model',
            'search_assistant_widget_enabled',
            'search_assistant_widget_position', 'search_assistant_primary_color', 'search_assistant_welcome_message',
            'search_assistant_indexed_properties', 'search_assistant_indexed_collections',
            'search_assistant_verbose_logging',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function validData(array $overrides = []): array
    {
        $base = [
            'search_assistant_vector_store_backend' => 'qdrant',
            'search_assistant_qdrant_url' => 'http://localhost:6333',
            'search_assistant_qdrant_collection' => 'omeka_resources',
            'search_assistant_qdrant_api_key' => '',
            'search_assistant_embeddings_url' => 'http://localhost:8000',
            'search_assistant_embedding_dimension' => 1024,
            'search_assistant_llm_provider' => 'ollama',
            'search_assistant_ollama_url' => 'http://localhost:11434',
            'search_assistant_ollama_model' => 'llama3.2',
            'search_assistant_widget_enabled' => '1',
            'search_assistant_widget_position' => 'bottom-right',
            'search_assistant_primary_color' => '#007bff',
            'search_assistant_welcome_message' => 'Hello!',
            'search_assistant_indexed_properties' => '[]',
            'search_assistant_indexed_collections' => '[]',
            'search_assistant_verbose_logging' => '0',
        ];
        return array_merge($base, $overrides);
    }

    private function formatMessages(): string
    {
        return 'Validation messages: ' . json_encode($this->form->getMessages());
    }
}
