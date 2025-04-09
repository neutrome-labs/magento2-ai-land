<?php
/**
 * NeutromeLabs AiLand Admin Controller for AJAX Content Generation
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Controller\Adminhtml\Landing;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use NeutromeLabs\AiLand\Model\AiGenerator;
use Psr\Log\LoggerInterface;

/**
 * Controller to handle AJAX requests for generating AI content.
 */
class Generate extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'NeutromeLabs_AiLand::generate';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var AiGenerator
     */
    protected $aiGenerator;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param AiGenerator $aiGenerator
     */
    public function __construct(
        Context     $context,
        JsonFactory $resultJsonFactory,
        AiGenerator $aiGenerator
    )
    {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->aiGenerator = $aiGenerator;
    }

    /**
     * Execute action based on request parameters
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $response = ['success' => false, 'message' => __('An error occurred.')];

        // Use isXmlHttpRequest() for AJAX check
        if (!$this->getRequest()->isPost() || !$this->getRequest()->isXmlHttpRequest()) {
            $response['message'] = __('Invalid request type.');
            return $result->setData($response);
        }

        try {
            // Retrieve parameters from POST request
            $customPrompt = $this->getRequest()->getParam('custom_prompt');
            $dataSourceType = $this->getRequest()->getParam('data_source_type'); // Re-added
            $productId = $this->getRequest()->getParam('product_id'); // Re-added
            $categoryId = $this->getRequest()->getParam('category_id'); // Re-added
            $designPlan = $this->getRequest()->getParam('generated_design_plan');
            $currentContent = $this->getRequest()->getParam('generated_content');
            $actionType = $this->getRequest()->getParam('action_type', 'generate');
            $storeId = $this->getRequest()->getParam('store_id');
            $referenceImageUrl = $this->getRequest()->getParam('reference_image_url'); // Get image URL
            $stylingReferenceUrl = $this->getRequest()->getParam('styling_reference_url'); // Get styling URL
            $generateInteractive = (bool)$this->getRequest()->getParam('generate_interactive', false); // Get checkbox state

            // Validate store_id
            if (empty($storeId) || !is_numeric($storeId) || (int)$storeId <= 0) {
                throw new LocalizedException(__('A valid Store View must be selected.'));
            }
            $storeId = (int)$storeId; // Cast to integer

            // Removed generation_goal validation

            $response['design'] = $designPlan; // Pass design plan to the response
            $response['html'] = $currentContent; // Pass current content to the response

            // Determine sourceId based on dataSourceType (Re-added)
            $sourceId = null;
            if ($dataSourceType === 'product' && !empty($productId)) {
                $sourceId = $productId;
            } elseif ($dataSourceType === 'category' && !empty($categoryId)) {
                $sourceId = $categoryId;
            }
            // If dataSourceType is empty or 'none', sourceId remains null.

            // Pass the design plan and context data to the generator
            $generationResult = $this->aiGenerator->generate(
                $customPrompt,
                $actionType,
                $storeId,
                $designPlan,
                $currentContent,
                $referenceImageUrl,
                $generateInteractive,
                $dataSourceType, // Pass context type
                $sourceId,       // Pass context ID
                $stylingReferenceUrl // Pass styling URL
            );

            $response = [
                'success' => true,
                'design' => $generationResult['design'], // Pass design plan
                'html' => $generationResult['html'],     // Pass generated HTML
                'message' => __('Content generated successfully.')
            ];

        } catch (LocalizedException $e) {
            $response['message'] = $e->getMessage();
            $this->_objectManager->get(LoggerInterface::class)->error($e->getMessage());
        } catch (Exception $e) {
            $response['message'] = __('An unexpected error occurred while generating content.');
            $this->_objectManager->get(LoggerInterface::class)->critical($e);
        }

        return $result->setData($response);
    }

    /**
     * Check permission
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
