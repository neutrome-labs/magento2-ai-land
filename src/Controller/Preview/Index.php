<?php
/**
 * NeutromeLabs AiLand Frontend Preview Controller
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Controller\Preview;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface; // Added
use Magento\Framework\App\Request\InvalidRequestException; // Added
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\View\Element\Text as TextBlock;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use NeutromeLabs\AiLand\Model\Service\ThemeService; // Added

class Index implements HttpPostActionInterface, CsrfAwareActionInterface // Added CsrfAwareActionInterface
{
    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ThemeService
     */
    private ThemeService $themeService; // Added property

    /**
     * @param ResultFactory $resultFactory
     * @param RequestInterface $request
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param ThemeService $themeService // Added parameter
     */
    public function __construct(
        ResultFactory $resultFactory,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ThemeService $themeService // Added parameter
    ) {
        $this->resultFactory = $resultFactory;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->themeService = $themeService; // Added assignment
    }

    /**
     * Execute action based on request and return result
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $htmlContent = (string)$this->request->getParam('content', '');
        $storeId = (int)$this->request->getParam('store_id');

        // Basic check: Ensure content is not empty and store ID is provided
        if (empty($htmlContent) || empty($storeId)) {
            /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setContents('Preview content or Store ID missing.');
            $resultRaw->setHttpResponseCode(400); // Bad Request
            return $resultRaw;
        }

        try {
            // Set the current store view context
            $this->storeManager->setCurrentStore($storeId);

            /** @var Page $resultPage */
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);

            // Check if theme has Tailwind config and add CDNs if not
            if ($this->themeService->getTailwindConfig($storeId) === null) {
                $this->logger->info('AiLand Preview: No Tailwind config found for theme, adding CDN scripts.', ['store_id' => $storeId]);
                $pageConfig = $resultPage->getConfig();
                // Add Tailwind Play CDN (loaded via script tag)
                $pageConfig->addRemotePageAsset(
                    'https://cdn.tailwindcss.com',
                    'js' // Asset type is 'js' as it's loaded via <script>
                );
                // Add Alpine.js CDN (defer recommended)
                $pageConfig->addRemotePageAsset(
                    'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
                    'js',
                    ['attributes' => ['defer' => 'defer']] // Add defer attribute
                );
            }

            // Get the block responsible for rendering the content
            $layout = $resultPage->getLayout();
            /** @var TextBlock|false $contentBlock */
            $contentBlock = $layout->getBlock('preview.content');

            if ($contentBlock) {
                // Assign the HTML content to the block
                // Note: This is potentially unsafe. In a real scenario, sanitize/validate HTML.
                $contentBlock->setText($htmlContent);
            } else {
                // Log error if block not found
                $this->logger->error('AiLand Preview: Block "preview.content" not found in layout handle "ailand_preview_index".');
                // Optionally return an error page or message
                /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
                $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                $resultRaw->setContents('Preview layout configuration error.');
                $resultRaw->setHttpResponseCode(500);
                return $resultRaw;
            }

            return $resultPage;

        } catch (NoSuchEntityException $e) {
            $this->logger->error('AiLand Preview: Invalid Store ID provided: ' . $storeId, ['exception' => $e]);
            /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setContents('Invalid Store ID for preview.');
            $resultRaw->setHttpResponseCode(400);
            return $resultRaw;
        } catch (LocalizedException $e) {
            $this->logger->error('AiLand Preview: Error setting store context.', ['exception' => $e]);
            /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setContents('Error setting preview context.');
            $resultRaw->setHttpResponseCode(500);
            return $resultRaw;
        } catch (\Exception $e) {
            $this->logger->critical('AiLand Preview: Unexpected error.', ['exception' => $e]);
            /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setContents('An unexpected error occurred during preview generation.');
            $resultRaw->setHttpResponseCode(500);
            return $resultRaw;
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // Return null to disable CSRF validation exception handling
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Return true to skip CSRF validation
        return true;
    }
}
