<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Permissions;

use SearchAssistantAI\Permissions\AllowAllPolicy;
use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use SearchAssistantAI\View\Helper\SearchAssistantWidget;
use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use Laminas\Authentication\AuthenticationServiceInterface;
use PHPUnit\Framework\TestCase;

class ChatAccessPolicyTest extends TestCase
{
    use ArraySettingsTrait;

    private function auth(bool $signedIn): AuthenticationServiceInterface
    {
        $auth = $this->createMock(AuthenticationServiceInterface::class);
        $auth->method('hasIdentity')->willReturn($signedIn);
        return $auth;
    }

    public function testAllowAllIsAlwaysOpen(): void
    {
        self::assertTrue((new AllowAllPolicy())->canChat());
    }

    public function testWidgetHelperFollowsThePolicy(): void
    {
        $settings = $this->arraySettings(['search_assistant_widget_enabled' => true]);
        $deny = $this->createMock(ChatAccessPolicyInterface::class);
        $deny->method('canChat')->willReturn(false);

        self::assertFalse((new SearchAssistantWidget($settings, null, $deny))->isEnabled());
        self::assertTrue((new SearchAssistantWidget($settings, null, new AllowAllPolicy()))->isEnabled());
        self::assertFalse((new SearchAssistantWidget($this->arraySettings([]), null, new AllowAllPolicy()))->isEnabled());
    }

    public function testDefaultPolicyLetsEveryoneIn(): void
    {
        $services = ModuleServices::withSettings($this->arraySettings(['search_assistant_widget_authenticated_only' => true]));

        self::assertInstanceOf(AllowAllPolicy::class, $services->get(ChatAccessPolicyInterface::class));
    }

}
