<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service;

use SearchAssistantAI\Permissions\SearchAssistantAcl;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * Delegator factory for Omeka ACL.
 *
 * Adds the Search Assistant AI access rules (see SearchAssistantAcl) after the core ACL
 * is created by AclFactory.
 */
class AclDelegatorFactory implements DelegatorFactoryInterface
{
    /**
     * Wrap the ACL service to add Search Assistant AI permissions.
     *
     * @param ContainerInterface $container
     * @param string $name
     * @param callable $callback The original factory callback
     * @param array<string, mixed>|null $options
     * @return \Omeka\Permissions\Acl
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        // Get the ACL created by the core AclFactory
        $acl = $callback();

        SearchAssistantAcl::configure($acl);

        return $acl;
    }
}
