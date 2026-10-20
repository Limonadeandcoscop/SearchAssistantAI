<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Prompt;

use RuntimeException;

/**
 * Parses the two YAML prompt templates (criteria extraction, response
 * generation) of a PromptProviderInterface into arrays.
 *
 * Shared by the local query pipeline and the hosted gateway, which both
 * send the module's prompts.
 */
class PromptTemplateLoader
{
    /**
     * Load the YAML prompt templates of a prompt provider.
     *
     * The YAML strings are parsed into associative arrays for the
     * QueryProcessorService.
     *
     * @return array<string, mixed> Associative array with 'criteria_extraction' and 'response_generation' keys
     * @throws RuntimeException If a YAML template is empty or cannot be parsed
     */
    public function load(PromptProviderInterface $promptService): array
    {
        $criteriaYaml = $promptService->getCriteriaExtractionYaml();
        $responseYaml = $promptService->getResponseGenerationYaml();

        if ($criteriaYaml === '') {
            throw new RuntimeException('Criteria extraction template is empty (no override and no default file).');
        }
        if ($responseYaml === '') {
            throw new RuntimeException('Response generation template is empty (no override and no default file).');
        }

        $criteriaTemplate = $this->parseYamlString($criteriaYaml);
        $responseTemplate = $this->parseYamlString($responseYaml);

        if ($criteriaTemplate === false) {
            throw new RuntimeException('Failed to parse criteria extraction template.');
        }
        if ($responseTemplate === false) {
            throw new RuntimeException('Failed to parse response generation template.');
        }

        return [
            'criteria_extraction' => $criteriaTemplate,
            'response_generation' => $responseTemplate,
        ];
    }

    /**
     * Parse a YAML string using native yaml_parse or the bundled fallback.
     *
     * @return array<string, mixed>|false Parsed YAML as associative array, or false on failure
     */
    private function parseYamlString(string $yaml)
    {
        if (function_exists('yaml_parse')) {
            $result = @yaml_parse($yaml);
            return $result === null ? false : $result;
        }
        return $this->parseYaml($yaml);
    }

    /**
     * Simple YAML parser for basic YAML structures.
     *
     * Handles:
     * - Simple key-value pairs
     * - Multi-line strings with pipe (|) syntax
     * - Nested objects
     * - Arrays
     *
     * @param string $yaml YAML content string
     * @return array<string, mixed> Parsed YAML as associative array
     */
    private function parseYaml(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [&$result];
        $indentStack = [-1];
        $multilineKey = null;
        $multilineContent = [];
        $multilineIndent = 0;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                if ($multilineKey !== null) {
                    $multilineContent[] = '';
                }
                continue;
            }

            // Calculate indentation
            preg_match('/^(\s*)/', $line, $matches);
            $indent = strlen($matches[1]);
            $trimmedLine = trim($line);

            // Handle multi-line content. Block lines sit at exactly
            // $multilineIndent (or deeper for nested bullets), so the
            // comparison must be inclusive — `>` ended every block at its
            // first line, yielding empty system_prompt values.
            if ($multilineKey !== null) {
                if ($indent >= $multilineIndent) {
                    // Continue multi-line content
                    $multilineContent[] = substr($line, $multilineIndent);
                    continue;
                } else {
                    // End multi-line content
                    $current = &$stack[count($stack) - 1];
                    $current[$multilineKey] = implode("\n", $multilineContent);
                    $multilineKey = null;
                    $multilineContent = [];
                }
            }

            // Check for multi-line string indicator (|)
            if (preg_match('/^(.+?):\s*\|(.*)$/', $trimmedLine, $matches)) {
                $key = trim($matches[1]);
                $multilineKey = $key;
                $multilineIndent = $indent + 2; // Standard YAML multi-line indent
                $multilineContent = [];
                continue;
            }

            // Handle key-value pairs
            if (preg_match('/^(.+?):\s*(.*)$/', $trimmedLine, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                // Pop stack if indentation decreased
                while (count($indentStack) > 1 && $indent <= $indentStack[count($indentStack) - 1]) {
                    array_pop($stack);
                    array_pop($indentStack);
                }

                $current = &$stack[count($stack) - 1];

                if ($value === '' || $value === '{}' || $value === '[]') {
                    // Empty value or start of nested structure
                    $current[$key] = [];
                    $stack[] = &$current[$key];
                    $indentStack[] = $indent;
                } elseif (preg_match('/^\[(.+)\]$/', $value, $arrayMatches)) {
                    // Inline array
                    $items = array_map('trim', explode(',', $arrayMatches[1]));
                    $current[$key] = array_map(function($item) {
                        return trim($item, '"\'');
                    }, $items);
                } elseif ($value === 'true' || $value === 'false') {
                    // Boolean
                    $current[$key] = ($value === 'true');
                } elseif (is_numeric($value)) {
                    // Number
                    $current[$key] = strpos($value, '.') !== false ? (float)$value : (int)$value;
                } else {
                    // String value
                    $current[$key] = trim($value, '"\'');
                }
            } elseif (preg_match('/^-\s+(.+)$/', $trimmedLine, $matches)) {
                // Array item
                $current = &$stack[count($stack) - 1];

                // Check if this is an object in an array
                if (preg_match('/^(.+?):\s*(.*)$/', trim($matches[1]), $itemMatches)) {
                    $itemKey = trim($itemMatches[1]);
                    $itemValue = trim($itemMatches[2]);

                    $newItem = [$itemKey => $itemValue];
                    $current[] = $newItem;
                } else {
                    $current[] = trim($matches[1], '"\'');
                }
            }
        }

        // Handle any remaining multi-line content
        if ($multilineKey !== null) {
            $current = &$stack[count($stack) - 1];
            $current[$multilineKey] = implode("\n", $multilineContent);
        }

        return $result;
    }
}
