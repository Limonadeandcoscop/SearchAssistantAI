<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Llm;

use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAI\Service\Llm\LlmServiceFactory;
use SearchAssistantAI\Service\Llm\OllamaService;
use SearchAssistantAI\Service\Llm\TokenBudgetAwareInterface;
use Interop\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The `search_assistant.llm_providers` registry drives which LLM the module uses.
 */
class LlmServiceFactoryTest extends TestCase
{
    use ArraySettingsTrait;

    /**
     * @param array<string, mixed> $stored Omeka settings
     * @param array<string, object> $extraServices services available besides the module's own
     * @param array<string, mixed> $extraConfig merged into the module config
     */
    private function container(array $stored, array $extraServices = [], array $extraConfig = []): ContainerInterface
    {
        return ModuleServices::withSettings($this->arraySettings($stored), $extraServices, $extraConfig);
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

    public function testShippedProvidersAreRegistered(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';
        $registry = $config['search_assistant']['llm_providers'];

        self::assertSame(['ollama'], array_keys($registry));
        self::assertSame(OllamaService::class, $registry['ollama']['service']);
        foreach ($registry as $key => $entry) {
            self::assertNotEmpty($entry['label'], "{$key} needs a label");
        }
    }

    public function testProviderAddedByAnotherModuleIsUsed(): void
    {
        $custom = $this->createMock(LlmInterface::class);
        $service = (new LlmServiceFactory())($this->container(
            ['search_assistant_llm_provider' => 'custom'],
            ['Custom\Llm' => $custom],
            ['search_assistant' => ['llm_providers' => ['custom' => ['label' => 'Custom', 'service' => 'Custom\Llm']]]]
        ), LlmInterface::class);

        self::assertSame($custom, $service);
    }

    public function testBudgetsAreAppliedToAnyTokenBudgetAwareProvider(): void
    {
        $custom = $this->createMock(TokenBudgetAwareLlm::class);
        $custom->expects(self::once())->method('setMaxContextTokens')->with(999);

        (new LlmServiceFactory())($this->container(
            ['search_assistant_llm_provider' => 'custom', 'search_assistant_max_context_tokens' => 999],
            ['Custom\Llm' => $custom],
            ['search_assistant' => ['llm_providers' => ['custom' => ['label' => 'Custom', 'service' => 'Custom\Llm']]]]
        ), LlmInterface::class);
    }

    public function testUnknownProviderFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM provider "nope" is not available');

        (new LlmServiceFactory())($this->container(['search_assistant_llm_provider' => 'nope']), LlmInterface::class);
    }

    public function testDefaultProviderIsSelfHosted(): void
    {
        $service = (new LlmServiceFactory())($this->container([]), LlmInterface::class);

        self::assertInstanceOf(OllamaService::class, $service);
    }
}

interface TokenBudgetAwareLlm extends LlmInterface, TokenBudgetAwareInterface
{

}
