<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Prompt;

/**
 * Prompts read from the files shipped under config/prompts.
 *
 * French and English versions are shipped; the language follows the Omeka
 * locale (English for any locale that is not French). A file without an
 * English version falls back to the French one.
 */
class FilePromptProvider implements PromptProviderInterface
{
    public const FILE_INSTRUCTIONS = 'search_assistant_instructions_fr.example.txt';
    public const FILE_CRITERIA_EXTRACTION = 'criteria_extraction.example.yaml';
    public const FILE_RESPONSE_GENERATION = 'response_generation.example.yaml';

    private string $promptDir;

    private string $language;

    /**
     * Directory of the prompts shipped with this module (also read by other
     * modules that seed or fall back to them).
     */
    public static function shippedDir(): string
    {
        return dirname(__DIR__, 3) . '/config/prompts';
    }

    /**
     * @param string $promptDir Absolute path to the module's config/prompts directory.
     * @param string $locale Omeka locale (e.g. "fr", "fr_FR", "en_US"); '' = French.
     */
    public function __construct(string $promptDir, string $locale = '')
    {
        $this->promptDir = rtrim($promptDir, '/');
        $this->language = self::languageOf($locale);
    }

    /** "fr" or "en": the shipped prompt language for an Omeka locale. */
    public static function languageOf(string $locale): string
    {
        return $locale === '' || strncasecmp($locale, 'fr', 2) === 0 ? 'fr' : 'en';
    }

    /**
     * File name of a shipped prompt in the given language: the English
     * version of "name.example.ext" is "name_en.example.ext" (the
     * instructions file carries its language: "..._fr" / "..._en").
     */
    public static function fileFor(string $fileName, string $language): string
    {
        if ($language === 'fr') {
            return $fileName;
        }
        if (strpos($fileName, '_fr.') !== false) {
            return str_replace('_fr.', '_' . $language . '.', $fileName);
        }
        return preg_replace('/\.example\./', '_' . $language . '.example.', $fileName, 1) ?? $fileName;
    }

    public function getInstructions(): string
    {
        return $this->read(self::FILE_INSTRUCTIONS);
    }

    public function getCriteriaExtractionYaml(): string
    {
        return $this->read(self::FILE_CRITERIA_EXTRACTION);
    }

    public function getResponseGenerationYaml(): string
    {
        return $this->read(self::FILE_RESPONSE_GENERATION);
    }

    /** Contents of one shipped prompt file in the configured language, '' when missing. */
    public function read(string $fileName): string
    {
        $path = $this->promptDir . '/' . self::fileFor($fileName, $this->language);
        if (!is_readable($path)) {
            $path = $this->promptDir . '/' . $fileName;
        }
        if (!is_readable($path)) {
            return '';
        }
        $contents = file_get_contents($path);
        return $contents === false ? '' : $contents;
    }
}
