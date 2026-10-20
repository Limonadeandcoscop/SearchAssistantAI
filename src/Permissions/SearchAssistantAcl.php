<?php
declare(strict_types=1);

namespace SearchAssistantAI\Permissions;

use Laminas\Permissions\Acl\Acl;

/**
 * Access rules for the module's controllers.
 *
 * Admin pages hold API keys and infrastructure settings: they are
 * registered as resources without any allow rule, so only global_admin
 * (allowed everything by Omeka's AclFactory) can open them. Omeka treats
 * unregistered controllers as allowed, which is why every admin
 * controller must be listed here.
 *
 * The public chat API stays open to every role, including anonymous
 * visitors.
 */
final class SearchAssistantAcl
{
    /** Admin controllers, as registered under `controllers` in module.config.php. */
    public const ADMIN_RESOURCES = [
        'SearchAssistantAI\Controller\Admin\Index',
        'SearchAssistantAI\Controller\Admin\Dashboard',
        'SearchAssistantAI\Controller\Admin\Config',
        'SearchAssistantAI\Controller\Admin\Reindex',
    ];

    public const PUBLIC_RESOURCES = [
        'SearchAssistantAI\Controller\Site\Chat',
    ];

    public static function configure(Acl $acl): void
    {
        self::addResources($acl, self::ADMIN_RESOURCES, self::PUBLIC_RESOURCES);
    }

    /**
     * Admin resources get no allow rule (global_admin only); public ones are
     * open to every role. Also used by add-on modules for their controllers.
     *
     * @param list<string> $adminResources
     * @param list<string> $publicResources
     */
    public static function addResources(Acl $acl, array $adminResources, array $publicResources = []): void
    {
        foreach ($adminResources as $resource) {
            if (!$acl->hasResource($resource)) {
                $acl->addResource($resource);
            }
        }

        foreach ($publicResources as $resource) {
            if (!$acl->hasResource($resource)) {
                $acl->addResource($resource);
            }
            $acl->allow(null, $resource);
        }
    }
}
