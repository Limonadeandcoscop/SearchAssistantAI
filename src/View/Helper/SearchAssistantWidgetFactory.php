<?php
declare(strict_types=1);

namespace SearchAssistantAI\View\Helper;

use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SearchAssistantWidgetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        return new SearchAssistantWidget($settings, null, $services->get(ChatAccessPolicyInterface::class));
    }
}
