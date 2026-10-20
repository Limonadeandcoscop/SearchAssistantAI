<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

/**
 * Providers that trim the prompt to a token budget (search_assistant_max_* settings).
 * LlmServiceFactory applies the configured budgets to any provider
 * implementing this interface.
 */
interface TokenBudgetAwareInterface
{
    public function setMaxContextTokens(int $maxTokens): void;

    public function setMaxFieldChars(int $maxChars): void;

    public function setMaxProperties(int $maxProps): void;
}
