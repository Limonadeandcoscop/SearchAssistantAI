<?php
declare(strict_types=1);
namespace SearchAssistantAI\Controller\Admin;

use SearchAssistantAI\Form\ConfigPageHelper;
use SearchAssistantAI\Form\ConfigForm;
use SearchAssistantAI\Service\VectorStore\QdrantService;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Admin configuration controller for Search Assistant AI module
 *
 * Handles the configuration form for setting up:
 * - Qdrant vector database connection
 * - Embeddings service connection
 * - Claude API settings
 * - Interface customization options
 * - Advanced settings
 *
 * Settings are persisted using Omeka S Settings API
 */
class ConfigController extends AbstractActionController
{
    /**
     * Interface page: the fields tagged PAGE_INTERFACE (widget visibility,
     * display scope, colour, welcome message...). Fields of other pages are
     * removed from the form and its input filter, so they are not saved here.
     *
     * @return ViewModel|Response
     */
    public function indexAction()
    {
        return $this->renderConfigPage('admin/search-assistant/config', ConfigPageHelper::PAGE_INTERFACE, false);
    }

    /**
     * Shared form load/save/render logic for the split config pages.
     *
     * @param string $redirectRoute Route to redirect to after successful save.
     * @param string $page ConfigPageHelper::PAGE_* constant: fields shown and saved on this page.
     * @param bool $loadTaxonomy Whether to load vocabularies + item sets for the indexing UI.
     * @return ViewModel|Response
     */
    protected function renderConfigPage(string $redirectRoute, string $page, bool $loadTaxonomy)
    {
        $settings = $this->settings();
        $form = $this->getForm(ConfigForm::class);
        ConfigPageHelper::filterForPage($form, $page);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                ConfigPageHelper::persist($settings, $form, $form->getData());
                $this->messenger()->addSuccess($this->translate('Configuration saved successfully.'));
                return $this->redirect()->toRoute($redirectRoute);
            }
            $this->messenger()->addError($this->translate('Please fix the validation errors below.'));
        } else {
            $form->setData(ConfigPageHelper::load($settings, $form));
        }

        $viewVars = ['form' => $form];
        if ($loadTaxonomy) {
            $entityManager = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\EntityManager');
            $viewVars['vocabularies'] = $entityManager->getRepository('Omeka\Entity\Vocabulary')->findAll();
            $viewVars['itemSets'] = $entityManager->getRepository('Omeka\Entity\ItemSet')->findAll();
        }
        return new ViewModel($viewVars);
    }

    /**
     * AJAX endpoint to test Qdrant connection
     *
     * POST: Tests connection to Qdrant server with provided URL
     * Returns JSON: {success: bool, message: string}
     *
     * @return JsonModel
     */
    public function testQdrantAction()
    {
        // Disable layout for JSON response
        $this->layout(false);

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ]);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // Get the Qdrant URL from POST data
        $url = $this->params()->fromPost('url');

        // Validate URL is provided
        if (empty($url)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Qdrant URL is required'
            ]);
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid URL format'
            ]);
        }

        try {
            // Create QdrantService instance with provided URL
            // Use shorter timeout for connection test (5 seconds)
            $qdrantService = new QdrantService($url, 5.0);

            // Test connection by attempting to get collections list
            // This is a lightweight operation that verifies the connection
            $response = $qdrantService->getCollectionInfo('_test');

            // If we get here without exception, connection works
            // Note: getCollectionInfo returns null if collection doesn't exist,
            // but that's okay - we just want to verify we can reach Qdrant
            return new JsonModel([
                'success' => true,
                'message' => 'Successfully connected to Qdrant server'
            ]);
        } catch (\RuntimeException $e) {
            // Connection failed after retry attempts
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to connect to Qdrant server: ' . $e->getMessage()
            ]);
        } catch (\InvalidArgumentException $e) {
            // Invalid arguments (shouldn't happen with our hardcoded test collection name)
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid request: ' . $e->getMessage()
            ]);
        } catch (\Exception $e) {
            // Catch any other unexpected errors
            return new JsonModel([
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ]);
        }
    }
}
