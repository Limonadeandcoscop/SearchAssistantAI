<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The module has always been Search Assistant AI. The working name it had
 * before its first release was never published and must not appear anywhere
 * in the repository: not in code, comments, config, translations or docs.
 */
class NoLegacyNameTest extends TestCase
{
    public function testRepositoryDoesNotMentionTheLegacyName(): void
    {
        // Spelled in pieces so this file does not fail its own check.
        $legacyName = 'chat' . 'bot';
        $root = dirname(__DIR__, 2);
        $skip = ['.git', 'vendor', 'node_modules', 'build', 'cache', 'logs', '.phpunit.cache'];

        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($skip): bool {
                    return !($file->isDir() && in_array($file->getFilename(), $skip, true));
                }
            )
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($path === __FILE__) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (strpos($content, "\0") !== false) {
                continue; // binary (images, .mo files)
            }
            if (stripos($content, $legacyName) !== false) {
                $found[] = substr($path, strlen($root) + 1);
            }
        }
        sort($found);

        self::assertSame([], $found, sprintf('The legacy name "%s" must not appear in the repository.', $legacyName));
    }
}
