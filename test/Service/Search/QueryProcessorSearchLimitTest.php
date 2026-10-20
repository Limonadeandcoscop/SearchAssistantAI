<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Search;

use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAI\Service\Search\QueryProcessorService;
use SearchAssistantAI\Service\Search\SemanticSearchInterface;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Laminas\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * search_assistant_search_limit must reach the vector search (it used to be ignored).
 */
class QueryProcessorSearchLimitTest extends TestCase
{
    private function processor(SemanticSearchInterface $search, ?int $limit): QueryProcessorService
    {
        $llm = $this->createMock(LlmInterface::class);
        $llm->method('extractCriteria')->willReturn(['keywords' => ['hugo']]);
        $llm->method('query')->willReturn('Answer');

        $templates = [
            'criteria_extraction' => ['system_prompt' => 'Extract criteria.'],
            'response_generation' => ['system_prompt' => 'Answer.'],
        ];
        $logger = $this->createMock(LoggerInterface::class);

        return $limit === null
            ? new QueryProcessorService($llm, $search, $logger, $templates)
            : new QueryProcessorService($llm, $search, $logger, $templates, null, null, null, $limit);
    }

    public function testConfiguredLimitIsPassedToSearch(): void
    {
        $search = $this->createMock(SemanticSearchInterface::class);
        $search->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), 25)
            ->willReturn([]);

        $this->processor($search, 25)->processQuery('Livres de Victor Hugo');
    }

    public function testDefaultLimitMatchesTheSettingDefault(): void
    {
        $search = $this->createMock(SemanticSearchInterface::class);
        $search->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), SearchAssistantSettings::DEFAULT_SEARCH_LIMIT)
            ->willReturn([]);

        $this->processor($search, null)->processQuery('Livres de Victor Hugo');
    }
}
