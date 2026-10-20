<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Search;

use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAI\Service\Search\CriteriaExtractorInterface;
use SearchAssistantAI\Service\Search\IdentityScorer;
use SearchAssistantAI\Service\Search\PassThroughCriteriaExtractor;
use SearchAssistantAI\Service\Search\QueryProcessorService;
use SearchAssistantAI\Service\Search\ResultScorerInterface;
use SearchAssistantAI\Service\Search\SemanticSearchInterface;
use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use Laminas\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Criteria extraction and re-ranking are pipeline steps behind service
 * aliases; the pass-through and identity implementations disable them.
 */
class PipelineHooksTest extends TestCase
{
    use ArraySettingsTrait;

    private const PROMPTS = [
        'search_assistant_prompt_criteria_extraction' => "system_prompt: Extract criteria.\n",
        'search_assistant_prompt_response_generation' => "system_prompt: Answer.\n",
    ];

    public function testPassThroughSearchesTheRawQuestionWithoutAnLlmCall(): void
    {
        $llm = $this->createMock(LlmInterface::class);
        $llm->expects(self::never())->method('extractCriteria');
        $llm->method('query')->willReturn('Answer');

        $search = $this->createMock(SemanticSearchInterface::class);
        $search->expects(self::once())
            ->method('search')
            ->with('Livres de Victor Hugo', [], self::anything())
            ->willReturn([]);

        $processor = new QueryProcessorService(
            $llm,
            $search,
            $this->createMock(LoggerInterface::class),
            ['response_generation' => ['system_prompt' => 'Answer.']],
            null,
            new PassThroughCriteriaExtractor()
        );

        $result = $processor->processQuery('  Livres de Victor Hugo ');
        self::assertArrayHasKey('status', $result);
    }

    public function testPassThroughRejectsAnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PassThroughCriteriaExtractor())->extract('   ');
    }

    public function testIdentityScorerKeepsTheVectorOrder(): void
    {
        $candidates = [['id' => 2, 'score' => 0.4], ['id' => 1, 'score' => 0.9]];
        self::assertSame($candidates, (new IdentityScorer())->rank($candidates));
    }

    public function testAliasesCanBeRepointedByAnotherModule(): void
    {
        $services = ModuleServices::withSettings($this->arraySettings(self::PROMPTS), [], [
            'service_manager' => ['aliases' => [
                CriteriaExtractorInterface::class => PassThroughCriteriaExtractor::class,
                ResultScorerInterface::class => IdentityScorer::class,
            ]],
        ]);

        self::assertInstanceOf(PassThroughCriteriaExtractor::class, $services->get(CriteriaExtractorInterface::class));
        self::assertInstanceOf(IdentityScorer::class, $services->get(ResultScorerInterface::class));
    }

    public function testPipelineStepsAreOffByDefault(): void
    {
        $services = ModuleServices::withSettings($this->arraySettings(self::PROMPTS));

        self::assertInstanceOf(PassThroughCriteriaExtractor::class, $services->get(CriteriaExtractorInterface::class));
        self::assertInstanceOf(IdentityScorer::class, $services->get(ResultScorerInterface::class));
    }

}
