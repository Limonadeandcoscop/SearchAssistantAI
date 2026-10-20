<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The free module works alone: its code never uses the SearchAssistantAIPro module
 * (comments may mention it). SearchAssistantAIPro plugs in through the extension
 * points (registries, service aliases, events, delegators) instead.
 */
class FreeDoesNotReferenceProTest extends TestCase
{
    public function testFreeCodeDoesNotUseSearchAssistantAIPro(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/Module.php', $root . '/config/module.config.php'];
        foreach (['src', 'view'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (preg_match('/\.(php|phtml)$/', $file->getPathname())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $found = [];
        foreach ($files as $file) {
            if (preg_match('/\bSearchAssistantAIPro\b/', $this->codeWithoutComments((string) file_get_contents($file)))) {
                $found[] = substr($file, strlen($root) + 1);
            }
        }

        self::assertSame([], $found, 'Free code uses SearchAssistantAIPro; go through an extension point instead.');
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        return $code;
    }
}
