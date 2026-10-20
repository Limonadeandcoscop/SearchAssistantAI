<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Support;

use Omeka\Settings\Settings;

/**
 * Omeka settings mock backed by an array, for TestCase subclasses.
 */
trait ArraySettingsTrait
{
    /**
     * @param array<string, mixed> $stored
     */
    private function arraySettings(array $stored): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function ($id, $default = null) use ($stored) {
            return array_key_exists($id, $stored) ? $stored[$id] : $default;
        });
        return $settings;
    }
}
