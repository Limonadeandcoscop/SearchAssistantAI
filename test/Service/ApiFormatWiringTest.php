<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service;

use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAITest\Support\ModuleServices;
use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\Embedding\EmbeddingServiceFactory;
use SearchAssistantAI\Service\Llm\LlmServiceFactory;
use SearchAssistantAI\Service\Llm\OllamaService;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Settings → factories wiring for the OpenAI-compatible API formats.
 *
 * Guarantees for existing installs: with no format keys stored, everything
 * stays native; in hosted mode the gateway wins whatever the format keys say.
 */
class ApiFormatWiringTest extends TestCase
{
    /** @var string */
    private $cwd;

    /** @var string */
    private $tmp;

    // EmbeddingServiceFactory uses a cwd-relative cache dir: keep it out of the module.
    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->tmp = sys_get_temp_dir() . '/api_format_wiring_' . uniqid();
        mkdir($this->tmp);
        chdir($this->tmp);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        foreach (['/cache/embeddings', '/cache', ''] as $dir) {
            if (is_dir($this->tmp . $dir)) {
                rmdir($this->tmp . $dir);
            }
        }
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function settings(array $stored): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function ($id, $default = null) use ($stored) {
            return array_key_exists($id, $stored) ? $stored[$id] : $default;
        });
        return $settings;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function container(array $stored): ContainerInterface
    {
        return ModuleServices::withSettings($this->settings($stored));
    }

    /**
     * @return mixed
     */
    private function prop(object $object, string $name)
    {
        $prop = (new ReflectionClass($object))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    // ─── settings defaults ───────────────────────────────────────────

    public function testFormatsDefaultToNativeWhenUnset(): void
    {
        $conf = new SearchAssistantSettings($this->settings([]));
        self::assertSame('ollama', $conf->getLlmApiFormat());
        self::assertSame('native', $conf->getEmbeddingsApiFormat());
        self::assertSame('', $conf->getEmbeddingsModel());
    }

    public function testInvalidFormatsFallBackToNative(): void
    {
        $conf = new SearchAssistantSettings($this->settings([
            'search_assistant_llm_api_format' => 'bogus',
            'search_assistant_embeddings_api_format' => 'bogus',
        ]));
        self::assertSame('ollama', $conf->getLlmApiFormat());
        self::assertSame('native', $conf->getEmbeddingsApiFormat());
    }

    // ─── LLM factory ─────────────────────────────────────────────────

    public function testDirectOllamaGetsConfiguredFormat(): void
    {
        $service = (new LlmServiceFactory())($this->container([
            'search_assistant_llm_provider' => 'ollama',
            'search_assistant_ollama_url' => 'http://vllm:8000/v1',
            'search_assistant_ollama_model' => 'google/gemma-3-12b-it',
            'search_assistant_llm_api_format' => 'openai',
        ]), 'x');

        self::assertInstanceOf(OllamaService::class, $service);
        self::assertSame('openai', $this->prop($service, 'apiFormat'));
        self::assertSame('http://vllm:8000', $this->prop($service, 'ollamaUrl'));
    }

    public function testDirectOllamaStaysNativeByDefault(): void
    {
        $service = (new LlmServiceFactory())($this->container([
            'search_assistant_llm_provider' => 'ollama',
            'search_assistant_ollama_url' => 'http://ollama:11434',
        ]), 'x');

        self::assertInstanceOf(OllamaService::class, $service);
        self::assertSame('ollama', $this->prop($service, 'apiFormat'));
    }

    // ─── Embeddings factory ──────────────────────────────────────────

    public function testDirectEmbeddingsGetFormatModelAndNoBearer(): void
    {
        $service = (new EmbeddingServiceFactory())($this->container([
            'search_assistant_embeddings_url' => 'http://tei:8080',
            'search_assistant_embeddings_api_format' => 'openai',
            'search_assistant_embeddings_model' => 'BAAI/bge-m3',
        ]), 'x');

        self::assertSame('openai', $this->prop($service, 'apiFormat'));
        self::assertSame('BAAI/bge-m3', $this->prop($service, 'model'));
        self::assertSame('/embed', $this->prop($service, 'endpointPath'));
        $headers = $this->prop($service, 'client')->getConfig('headers');
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testDirectEmbeddingsStayNativeByDefault(): void
    {
        $service = (new EmbeddingServiceFactory())($this->container([]), 'x');

        self::assertInstanceOf(EmbeddingService::class, $service);
        self::assertSame('native', $this->prop($service, 'apiFormat'));
        self::assertSame('/embed', $this->prop($service, 'endpointPath'));
    }
}
