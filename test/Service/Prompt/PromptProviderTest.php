<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Prompt;

use SearchAssistantAI\Service\Llm\OllamaService;
use SearchAssistantAI\Service\Prompt\FilePromptProvider;
use SearchAssistantAI\Service\Prompt\PromptProviderInterface;
use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class PromptProviderTest extends TestCase
{
    use ArraySettingsTrait;

    private function shippedFile(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/config/prompts/' . $name);
    }

    public function testFileProviderReadsTheShippedPrompts(): void
    {
        $provider = new FilePromptProvider(dirname(__DIR__, 3) . '/config/prompts');

        self::assertSame($this->shippedFile(FilePromptProvider::FILE_INSTRUCTIONS), $provider->getInstructions());
        self::assertSame($this->shippedFile(FilePromptProvider::FILE_CRITERIA_EXTRACTION), $provider->getCriteriaExtractionYaml());
        self::assertSame($this->shippedFile(FilePromptProvider::FILE_RESPONSE_GENERATION), $provider->getResponseGenerationYaml());
        self::assertNotSame('', $provider->getInstructions());
    }

    public function testMissingFileGivesAnEmptyPrompt(): void
    {
        self::assertSame('', (new FilePromptProvider(sys_get_temp_dir() . '/no-such-dir'))->getInstructions());
    }

    public function testLlmGetsItsInstructionsFromTheConfiguredProvider(): void
    {
        $services = ModuleServices::withSettings(
            $this->arraySettings(['search_assistant_llm_provider' => 'ollama', 'search_assistant_prompt_instructions' => 'From settings']),
            [],
            ['service_manager' => ['aliases' => [PromptProviderInterface::class => FilePromptProvider::class]]]
        );

        $llm = $services->get(OllamaService::class);
        $prop = new ReflectionProperty(OllamaService::class, 'systemInstructionsOverride');
        $prop->setAccessible(true);
        self::assertSame($this->shippedFile(FilePromptProvider::FILE_INSTRUCTIONS), $prop->getValue($llm));
    }

    public function testDefaultProviderReadsTheShippedFiles(): void
    {
        $services = ModuleServices::withSettings($this->arraySettings(['search_assistant_prompt_instructions' => 'From settings']));

        $provider = $services->get(PromptProviderInterface::class);
        self::assertInstanceOf(FilePromptProvider::class, $provider);
        self::assertSame($this->shippedFile(FilePromptProvider::FILE_INSTRUCTIONS), $provider->getInstructions());
    }


    public function testEnglishLocaleReadsTheEnglishPrompts(): void
    {
        $provider = new FilePromptProvider(FilePromptProvider::shippedDir(), 'en_US');

        self::assertSame($this->shippedFile('search_assistant_instructions_en.example.txt'), $provider->getInstructions());
        self::assertSame($this->shippedFile('response_generation_en.example.yaml'), $provider->getResponseGenerationYaml());
        // No English version shipped: the French one is used.
        self::assertSame($this->shippedFile(FilePromptProvider::FILE_CRITERIA_EXTRACTION), $provider->getCriteriaExtractionYaml());
    }

    public function testFrenchOrUnsetLocaleReadsTheFrenchPrompts(): void
    {
        foreach (['', 'fr', 'fr_CA'] as $locale) {
            $provider = new FilePromptProvider(FilePromptProvider::shippedDir(), $locale);
            self::assertSame($this->shippedFile(FilePromptProvider::FILE_INSTRUCTIONS), $provider->getInstructions(), $locale);
        }
    }

    public function testShippedResponsePromptsParseWithTheUsedKeys(): void
    {
        foreach (['fr' => "Question de l'utilisateur :", 'en_US' => 'User question:'] as $locale => $label) {
            $templates = (new \SearchAssistantAI\Service\Prompt\PromptTemplateLoader())
                ->load(new FilePromptProvider(FilePromptProvider::shippedDir(), $locale));
            self::assertStringContainsString('[', (string) $templates['response_generation']['system_prompt'], $locale);
            self::assertSame($label, $templates['response_generation']['question_label'], $locale);
        }
    }
}
