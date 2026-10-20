<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Prompt;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FilePromptProviderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): FilePromptProvider
    {
        $locale = (string) $services->get('Omeka\Settings')->get('locale', '');
        return new FilePromptProvider(FilePromptProvider::shippedDir(), $locale);
    }
}
