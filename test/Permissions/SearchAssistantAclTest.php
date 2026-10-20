<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Permissions;

use SearchAssistantAI\Permissions\SearchAssistantAcl;
use Omeka\Permissions\Acl;
use PHPUnit\Framework\TestCase;

class SearchAssistantAclTest extends TestCase
{
    /** @var Acl */
    private $acl;

    protected function setUp(): void
    {
        // Same roles and global_admin rule as Omeka's AclFactory.
        $this->acl = new Acl();
        $this->acl->addRole(Acl::ROLE_RESEARCHER)
            ->addRole(Acl::ROLE_AUTHOR)
            ->addRole(Acl::ROLE_REVIEWER)
            ->addRole(Acl::ROLE_EDITOR)
            ->addRole(Acl::ROLE_SITE_ADMIN)
            ->addRole(Acl::ROLE_GLOBAL_ADMIN);
        $this->acl->allow(Acl::ROLE_GLOBAL_ADMIN);

        SearchAssistantAcl::configure($this->acl);
    }

    public function testOnlyGlobalAdminOpensAdminPages(): void
    {
        foreach (SearchAssistantAcl::ADMIN_RESOURCES as $resource) {
            self::assertTrue($this->acl->isAllowed(Acl::ROLE_GLOBAL_ADMIN, $resource, 'index'), $resource);
            foreach ([Acl::ROLE_SITE_ADMIN, Acl::ROLE_EDITOR, Acl::ROLE_REVIEWER, Acl::ROLE_AUTHOR, Acl::ROLE_RESEARCHER, null] as $role) {
                self::assertFalse($this->acl->isAllowed($role, $resource, 'index'), "{$role} on {$resource}");
            }
        }
    }

    public function testPublicControllersAreOpenToEveryone(): void
    {
        foreach (SearchAssistantAcl::PUBLIC_RESOURCES as $resource) {
            self::assertTrue($this->acl->isAllowed(null, $resource, 'query'), $resource);
            self::assertTrue($this->acl->isAllowed(Acl::ROLE_RESEARCHER, $resource, 'query'), $resource);
        }
    }

    public function testEveryRegisteredControllerIsAnAclResource(): void
    {
        // Omeka allows controllers that are not ACL resources, so a missing
        // or misspelled entry silently opens the page to every role.
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $controllers = array_merge(
            array_keys($config['controllers']['invokables'] ?? []),
            array_keys($config['controllers']['factories'] ?? [])
        );

        self::assertNotEmpty($controllers);
        foreach ($controllers as $controller) {
            self::assertTrue($this->acl->hasResource($controller), "{$controller} has no ACL rule");
        }
    }

    public function testConfigureIsIdempotent(): void
    {
        SearchAssistantAcl::configure($this->acl);
        self::assertFalse($this->acl->isAllowed(Acl::ROLE_EDITOR, 'SearchAssistantAI\Controller\Admin\Reindex', 'launch'));
    }
}
