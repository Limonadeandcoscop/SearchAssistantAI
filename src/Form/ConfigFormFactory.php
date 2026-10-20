<?php
declare(strict_types=1);

namespace SearchAssistantAI\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * ConfigForm with the sections listed in `search_assistant.config_form.sections`.
 */
class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): ConfigForm
    {
        $searchAssistant = $services->get('Config')['search_assistant'] ?? [];
        $sections = $searchAssistant['config_form']['sections'] ?? [];
        // Same name as Laminas' default ElementFactory gives it: Omeka's CSRF
        // initializer names the token "configform_csrf", which the views use.
        return new ConfigForm('configform', ($options ?? []) + [
            ConfigForm::OPTION_SECTIONS => $sections,
            ConfigForm::OPTION_CHOICES => ConfigForm::choicesFromConfig($searchAssistant),
        ]);
    }
}
