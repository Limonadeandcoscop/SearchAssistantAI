<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Form;

use SearchAssistantAI\Form\ConfigForm;
use SearchAssistantAI\Form\ConfigPageHelper;
use SearchAssistantAITest\Support\ArraySettingsTrait;
use Laminas\Form\Element;
use PHPUnit\Framework\TestCase;

/**
 * Field set of each admin config page in the free module (SearchAssistantAIPro adds
 * its own fields; see its ConfigPagesTest).
 */
class ConfigPagesTest extends TestCase
{
    use ArraySettingsTrait;

    private const CONFIGURE = [
        'search_assistant_vector_store_backend', 'search_assistant_qdrant_url', 'search_assistant_qdrant_collection',
        'search_assistant_qdrant_api_key', 'search_assistant_embeddings_url', 'search_assistant_embeddings_api_format',
        'search_assistant_embeddings_model', 'search_assistant_embedding_dimension', 'search_assistant_llm_provider',
        'search_assistant_llm_api_format', 'search_assistant_ollama_url', 'search_assistant_ollama_model',
        'search_assistant_verbose_logging',
    ];

    private const INTERFACE = [
        'search_assistant_widget_enabled', 'search_assistant_widget_display',
        'search_assistant_widget_position', 'search_assistant_primary_color', 'search_assistant_welcome_message',
    ];

    private const INDEXING = ['search_assistant_indexed_properties', 'search_assistant_indexed_collections'];

    /**
     * @return list<string>
     */
    private function fieldsOf(string $page): array
    {
        $form = new ConfigForm();
        $form->init();
        ConfigPageHelper::filterForPage($form, $page);
        $names = array_map(static function ($e): string {
            return $e->getName();
        }, array_values($form->getElements()));
        sort($names);
        return $names;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);
        return $names;
    }

    public function testConfigurePageFields(): void
    {
        self::assertSame($this->sorted(self::CONFIGURE), $this->fieldsOf(ConfigPageHelper::PAGE_CONFIGURE));
    }

    public function testInterfacePageFields(): void
    {
        self::assertSame($this->sorted(self::INTERFACE), $this->fieldsOf(ConfigPageHelper::PAGE_INTERFACE));
    }

    public function testIndexingPageFields(): void
    {
        self::assertSame($this->sorted(self::INDEXING), $this->fieldsOf(ConfigPageHelper::PAGE_INDEXING));
    }

    public function testFilteredFormOnlyValidatesItsOwnFields(): void
    {
        $form = new ConfigForm();
        $form->init();
        ConfigPageHelper::filterForPage($form, ConfigPageHelper::PAGE_INTERFACE);

        $form->setData(['search_assistant_widget_enabled' => '1', 'search_assistant_widget_display' => 'all']);
        self::assertTrue($form->isValid(), json_encode($form->getMessages()) ?: '');
    }

    public function testPersistKeepsSecretsOnEmptyAndCastsCheckboxes(): void
    {
        $form = new ConfigForm();
        $form->init();
        $stored = [];
        $settings = $this->arraySettings([]);
        $settings->method('set')->willReturnCallback(function ($id, $value) use (&$stored): void {
            $stored[$id] = $value;
        });

        ConfigPageHelper::persist($settings, $form, [
            'search_assistant_qdrant_api_key' => '',
            'search_assistant_embeddings_model' => 'BAAI/bge-m3',
            'search_assistant_verbose_logging' => '1',
            'search_assistant_widget_enabled' => '0',
            'search_assistant_qdrant_url' => 'http://qdrant:6333',
            'unknown_key' => 'ignored',
        ]);

        self::assertArrayNotHasKey('search_assistant_qdrant_api_key', $stored);
        self::assertSame('BAAI/bge-m3', $stored['search_assistant_embeddings_model']);
        self::assertTrue($stored['search_assistant_verbose_logging']);
        self::assertFalse($stored['search_assistant_widget_enabled']);
        self::assertSame('http://qdrant:6333', $stored['search_assistant_qdrant_url']);
        self::assertArrayNotHasKey('unknown_key', $stored);
    }

    public function testEveryCheckboxAndSecretIsTagged(): void
    {
        $form = new ConfigForm();
        $form->init();
        $secrets = [];
        foreach ($form->getElements() as $element) {
            if ($element->getOption(ConfigPageHelper::OPTION_SECRET)) {
                $secrets[] = $element->getName();
            }
            if ($element instanceof Element\Password) {
                self::assertTrue((bool) $element->getOption(ConfigPageHelper::OPTION_SECRET), $element->getName() . ' is a password field');
            }
        }
        sort($secrets);
        self::assertSame(
            ['search_assistant_qdrant_api_key'],
            $secrets
        );
    }

    public function testReindexWatchListsConfigureFieldsWithTheirFormDefaults(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $form = new ConfigForm();
        $form->init();
        foreach ($config['search_assistant']['config_form']['reindex_watch'] as $key => $default) {
            self::assertContains($key, self::CONFIGURE, $key);
            // A default that differs from the form value would warn on every first save.
            self::assertSame((string) $default, (string) $form->get($key)->getValue(), $key);
        }
    }
}
