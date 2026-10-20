<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Prompt;

/**
 * The three prompts the RAG pipeline uses.
 *
 * Resolved through the PromptProviderInterface::class service alias:
 * FilePromptProvider reads the files shipped in config/prompts, PromptService
 * reads admin-edited values from settings.
 */
interface PromptProviderInterface
{
    /** Plain-text system instructions sent with every LLM call. */
    public function getInstructions(): string;

    /** YAML template for criteria extraction (may be empty when the step is disabled). */
    public function getCriteriaExtractionYaml(): string;

    /** YAML template for response generation. */
    public function getResponseGenerationYaml(): string;
}
