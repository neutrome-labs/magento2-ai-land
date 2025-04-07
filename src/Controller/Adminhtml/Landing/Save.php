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

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\BlockFactory; // Corrected
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory; // Corrected
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory; // Added for uniqueness check
use Magento\Cms\Model\Block;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

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

    /** @var DataPersistorInterface */
    protected $dataPersistor;

    /** @var BlockRepositoryInterface */
    protected $blockRepository;

    /** @var BlockFactory */ // Corrected
    protected $blockFactory;

    /** @var PageRepositoryInterface */
    protected $pageRepository;

    /** @var PageFactory */ // Corrected
    protected $pageFactory;

    /** @var PageCollectionFactory */ // Added
    protected $pageCollectionFactory;

    /** @var StoreManagerInterface */
    private $storeManager;


    /**
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param BlockRepositoryInterface $blockRepository
     * @param BlockFactory $blockFactory // Corrected
     * @param PageRepositoryInterface $pageRepository
     * @param PageFactory $pageFactory // Corrected
     * @param PageCollectionFactory $pageCollectionFactory // Added
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context                  $context,
        DataPersistorInterface   $dataPersistor,
        BlockRepositoryInterface $blockRepository,
        BlockFactory             $blockFactory, // Corrected
        PageRepositoryInterface  $pageRepository,
        PageFactory              $pageFactory, // Corrected
        PageCollectionFactory    $pageCollectionFactory, // Added
        StoreManagerInterface    $storeManager
    ) {
        parent::__construct($context);
        $this->dataPersistor = $dataPersistor;
        $this->blockRepository = $blockRepository;
        $this->blockFactory = $blockFactory;
        $this->pageRepository = $pageRepository;
        $this->pageFactory = $pageFactory;
        $this->pageCollectionFactory = $pageCollectionFactory; // Added
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
        $saveType = $this->getRequest()->getParam('save_as_type'); // Get the save type

        if ($data && $saveType) {
            // Basic validation
            if (empty($data['title']) || empty($data['identifier']) || empty($data['generated_content'])) {
                $this->messageManager->addErrorMessage(__('Title, Identifier, and Generated Content are required.'));
                $this->dataPersistor->set('ailand_landing_form', $data);
                return $resultRedirect->setPath('*/*/new');
            }

            // Validate identifier format
            if (!preg_match('/^[a-z0-9_]+$/', $data['identifier'])) {
                $this->messageManager->addErrorMessage(__('Identifier can only contain lowercase letters, numbers, and underscores.'));
                $this->dataPersistor->set('ailand_landing_form', $data);
                return $resultRedirect->setPath('*/*/new');
            }

            // Determine stores
            $stores = [0]; // Default to All Store Views
            if (!empty($data['store_id'])) {
                 // If a single store is selected from the form
                 $stores = is_array($data['store_id']) ? $data['store_id'] : [$data['store_id']];
                 // Ensure 'All Store Views' (0) is included if multiple specific stores are selected,
                 // or if only one specific store is selected, depending on desired behavior.
                 // For simplicity, let's assume the form provides the exact store IDs needed.
                 // If the form only allows single select, $stores will be [$selectedStoreId].
            } else {
                 // If no store_id is passed (e.g., selector removed or issue), default to all
                 $allStoreIds = array_keys($this->storeManager->getStores());
                 $stores = array_merge([0], $allStoreIds); // Include 0 and all specific stores
            }
            // Ensure store IDs are integers
            $stores = array_map('intval', $stores);


            try {
                if ($saveType === 'block') {
                    /** @var Block $model */
                    $model = $this->blockFactory->create();

                    // Check if block identifier already exists
                    try {
                        $existingBlock = $this->blockRepository->getById($data['identifier']);
                        if ($existingBlock->getId()) {
                            throw new LocalizedException(__('A CMS Block with the identifier "%1" already exists.', $data['identifier']));
                        }
                    } catch (NoSuchEntityException $e) {
                        // Identifier is unique, proceed
                    }

                    $model->setData($data); // Set title, identifier
                    $model->setContent($data['generated_content']);
                    $model->setIsActive(true);
                    $model->setStores($stores); // Use determined stores

                    $this->blockRepository->save($model);
                    $this->messageManager->addSuccessMessage(__('You saved the AI content as CMS Block "%1".', $data['title']));
                    $this->dataPersistor->clear('ailand_landing_form');
                    return $resultRedirect->setPath('cms/block/index');

                } elseif ($saveType === 'page') {
                    /** @var Page $model */
                    $model = $this->pageFactory->create();

                    // Check if page identifier already exists for the selected store(s) using Collection
                    $collection = $this->pageCollectionFactory->create();
                    $collection->addFieldToFilter('identifier', $data['identifier'])
                               ->addStoreFilter($stores, false) // Check across specified stores
                               ->setPageSize(1); // We only need to know if at least one exists

                    if ($collection->getSize() > 0) {
                         throw new LocalizedException(
                             __('A CMS Page with the identifier "%1" already exists for the selected store view(s).', $data['identifier'])
                         );
                    }
                    // Identifier is unique for the selected stores, proceed

                    $model->setData($data); // Set title, identifier
                    $model->setContent($data['generated_content']);
                    $model->setIsActive(true);
                    $model->setStores($stores); // Use determined stores
                    // Optional: Set other page defaults if needed
                    $model->setPageLayout('1column'); // Example default layout

                    $this->pageRepository->save($model);
                    $this->messageManager->addSuccessMessage(__('You saved the AI content as CMS Page "%1".', $data['title']));
                    $this->dataPersistor->clear('ailand_landing_form');
                    return $resultRedirect->setPath('cms/page/index');

                } else {
                    throw new LocalizedException(__('Invalid save type specified.'));
                }

            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the content.'));
                $this->_objectManager->get(LoggerInterface::class)->critical($e);
            }

            // If save failed, persist data and redirect back
            $this->dataPersistor->set('ailand_landing_form', $data);
            return $resultRedirect->setPath('*/*/new');
        }

        // If no data or save type, redirect back to form
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
