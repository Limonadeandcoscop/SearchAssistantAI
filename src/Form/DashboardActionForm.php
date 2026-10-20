<?php
declare(strict_types=1);
namespace SearchAssistantAI\Form;

use Laminas\Form\Form;
use Laminas\Form\Element\Csrf;

/**
 * Form for dashboard quick actions with CSRF protection.
 *
 * Omeka's global Csrf form initializer does not reliably fire for this
 * form in this deployment, so the CSRF element is added manually here.
 * Timeout matches Omeka's framework default (12h) so long-idle admin
 * tabs don't fail clear/reindex/sync actions with "Invalid security
 * token".
 */
class DashboardActionForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => Csrf::class,
            'name' => 'csrf',
            'options' => [
                'csrf_options' => [
                    'timeout' => 43200,
                ],
            ],
        ]);
    }
}
