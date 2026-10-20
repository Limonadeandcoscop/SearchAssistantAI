<?php
declare(strict_types=1);
namespace SearchAssistantAI\InputFilter;

use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\Input;
use Laminas\Validator;
use Laminas\Filter;

/**
 * Input filter for validating search assistant query API requests
 *
 * This filter validates POST /search-assistant/api/query request data:
 * - query: Required string (1-1000 characters)
 * - context: Optional array (max 10 items) for conversation history
 * - filters: Optional object for search filtering (type, dateRange)
 *
 * Follows Laminas InputFilter patterns for security and validation.
 */
class QueryInputFilter extends InputFilter
{
    /**
     * Initialize input filter with validation rules
     *
     * Called after instantiation to configure all validation rules
     * for the query API endpoint following spec requirements.
     */
    public function __construct()
    {
        // Query field - Required string with length validation
        $query = new Input('query');
        $query->setRequired(true);
        $query->getFilterChain()
            ->attach(new Filter\StringTrim())
            ->attach(new Filter\StripTags());
        $query->getValidatorChain()
            ->attach(new Validator\NotEmpty([
                'messages' => [
                    Validator\NotEmpty::IS_EMPTY => 'Query field is required and cannot be empty',
                ],
            ]))
            ->attach(new Validator\StringLength([
                'min' => 1,
                'max' => 1000,
                'messages' => [
                    Validator\StringLength::TOO_SHORT => 'Query must be at least 1 character long',
                    Validator\StringLength::TOO_LONG => 'Query must not exceed 1000 characters',
                ],
            ]));
        $this->add($query);

        // Context field - Optional array with max 10 items
        $context = new Input('context');
        $context->setRequired(false);
        $context->setAllowEmpty(true);
        $context->getValidatorChain()
            ->attach(new Validator\Callback([
                'callback' => function ($value) {
                    // Allow null or empty values
                    if (empty($value)) {
                        return true;
                    }
                    // Must be an array
                    if (!is_array($value)) {
                        return false;
                    }
                    // Max 10 items
                    if (count($value) > 10) {
                        return false;
                    }
                    return true;
                },
                'messages' => [
                    Validator\Callback::INVALID_VALUE => 'Context must be an array with a maximum of 10 items',
                ],
            ]));
        $this->add($context);

        // Filters field - Optional object for search filtering
        $filters = new Input('filters');
        $filters->setRequired(false);
        $filters->setAllowEmpty(true);
        $filters->getValidatorChain()
            ->attach(new Validator\Callback([
                'callback' => function ($value) {
                    // Allow null or empty values
                    if (empty($value)) {
                        return true;
                    }
                    // Must be an array or object (will be parsed as array from JSON)
                    if (!is_array($value)) {
                        return false;
                    }
                    // Validate 'type' if present
                    if (isset($value['type'])) {
                        $validTypes = ['item', 'media', 'item-set'];
                        if (!in_array($value['type'], $validTypes, true)) {
                            return false;
                        }
                    }
                    // Validate 'dateRange' if present
                    if (isset($value['dateRange'])) {
                        if (!is_array($value['dateRange'])) {
                            return false;
                        }
                        // dateRange should have 'start' and/or 'end' keys
                        if (isset($value['dateRange']['start'])) {
                            // Validate ISO 8601 date format (YYYY-MM-DD or full ISO datetime)
                            if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})?)?$/', $value['dateRange']['start'])) {
                                return false;
                            }
                        }
                        if (isset($value['dateRange']['end'])) {
                            // Validate ISO 8601 date format
                            if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})?)?$/', $value['dateRange']['end'])) {
                                return false;
                            }
                        }
                    }
                    return true;
                },
                'messages' => [
                    Validator\Callback::INVALID_VALUE => 'Filters must be a valid object with optional type (item|media|item-set) and dateRange (ISO 8601 format)',
                ],
            ]));
        $this->add($filters);
    }
}
