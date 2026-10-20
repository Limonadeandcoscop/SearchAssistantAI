<?php
declare(strict_types=1);
namespace SearchAssistantAI\Controller\Admin;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * @return ViewModel|Response
     */
    public function indexAction()
    {
        // Redirect to dashboard as the main admin page
        return $this->redirect()->toRoute('admin/search-assistant/dashboard');
    }
}
