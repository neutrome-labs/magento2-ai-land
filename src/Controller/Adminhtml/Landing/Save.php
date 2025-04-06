<?php
/**
 * NeutromeLabs AiLand Admin Controller for Saving Generated Landing as CMS Block
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Controller\Adminhtml\Landing;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Model\Block;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Controller to handle saving the generated content as a CMS Block.
 */
class Save extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'NeutromeLabs_AiLand::generate'; // Reuse the same resource for saving

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var BlockRepositoryInterface
     */
    protected $blockRepository;

    /**
     * @var BlockInterfaceFactory
     */
    protected $blockFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;


    /**
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param BlockRepositoryInterface $blockRepository
     * @param BlockInterfaceFactory $blockFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        BlockRepositoryInterface $blockRepository,
        BlockInterfaceFactory $blockFactory,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->dataPersistor = $dataPersistor;
        $this->blockRepository = $blockRepository;
        $this->blockFactory = $blockFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * Save action
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if ($data) {
            // Basic validation - more robust validation can be added
            if (empty($data['title']) || empty($data['identifier']) || empty($data['generated_content'])) {
                $this->messageManager->addErrorMessage(__('Title, Identifier, and Generated Content are required.'));
                $this->dataPersistor->set('ailand_landing_form', $data);
                return $resultRedirect->setPath('*/*/new');
            }

            // Validate identifier format (basic example)
            if (!preg_match('/^[a-z0-9_]+$/', $data['identifier'])) {
                 $this->messageManager->addErrorMessage(__('Identifier can only contain lowercase letters, numbers, and underscores.'));
                 $this->dataPersistor->set('ailand_landing_form', $data);
                 return $resultRedirect->setPath('*/*/new');
            }

            /** @var Block $model */
            $model = $this->blockFactory->create();

            try {
                // Check if identifier already exists
                // Note: getById throws NoSuchEntityException if not found, which is not ideal here.
                // A custom check might be better, but this works for a basic implementation.
                try {
                     $existingBlock = $this->blockRepository->getById($data['identifier']);
                     if ($existingBlock->getId()) {
                         throw new LocalizedException(__('A CMS Block with the identifier "%1" already exists.', $data['identifier']));
                     }
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    // Identifier is unique, proceed
                }


                $model->setData($data); // Set title, identifier, generated_content
                $model->setContent($data['generated_content']); // Ensure content is set correctly
                $model->setIsActive(true); // Activate by default

                // Assign to all stores by default, or get from form if a store selector is added
                 if (empty($data['store_id'])) {
                    $stores = array_keys($this->storeManager->getStores());
                    if (!in_array(0, $stores)) {
                        array_unshift($stores, 0); // Add 'All Store Views'
                    }
                    $model->setStores($stores);
                } else {
                     $model->setStores($data['store_id']);
                }


                $this->blockRepository->save($model);
                $this->messageManager->addSuccessMessage(__('You saved the AI-generated landing page as CMS Block "%1".', $data['title']));
                $this->dataPersistor->clear('ailand_landing_form');

                // Redirect to CMS Block grid or edit page
                return $resultRedirect->setPath('cms/block/index'); // Redirect to grid

            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                $this->dataPersistor->set('ailand_landing_form', $data);
                return $resultRedirect->setPath('*/*/new'); // Redirect back to the form
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the CMS Block.'));
                $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical($e);
                $this->dataPersistor->set('ailand_landing_form', $data);
                return $resultRedirect->setPath('*/*/new'); // Redirect back to the form
            }
        }

        // If no data, redirect back to form
        return $resultRedirect->setPath('*/*/new');
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
