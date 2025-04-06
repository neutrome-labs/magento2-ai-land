<?php
/**
 * NeutromeLabs AiLand AI Generation Service
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

// Removed: Curl, JsonSerializer

// Removed: ThemeCollectionFactory, ComponentRegistrarInterface, ComponentRegistrar, DesignInterface

// Added: New Generator classes

/**
 * Service class responsible for orchestrating content generation using AI.
 */
class AiGenerator
{
    // Config paths
    const XML_PATH_API_KEY = 'ailand/openrouter/api_key';
    const XML_PATH_THINKING_MODEL = 'ailand/openrouter/thinking_model'; // New path
    const XML_PATH_RENDERING_MODEL = 'ailand/openrouter/rendering_model'; // New path

    // Default Models (Keep)
    const DEFAULT_THINKING_MODEL = 'deepseek/deepseek-r1:free';
    const DEFAULT_RENDERING_MODEL = 'deepseek/deepseek-chat-v3-0324:free';


    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var DesignGenerator
     */
    private $designGenerator;

    /**
     * @var HtmlGenerator
     */
    private $htmlGenerator;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param DesignGenerator $designGenerator // Added
     * @param HtmlGenerator $htmlGenerator // Added
     */
    public function __construct(
        ScopeConfigInterface                             $scopeConfig,
        EncryptorInterface $encryptor,
        ProductRepositoryInterface                       $productRepository,
        CategoryRepositoryInterface                      $categoryRepository,
        LoggerInterface                                  $logger,
        StoreManagerInterface                            $storeManager,
        DesignGenerator                                  $designGenerator, // Added
        HtmlGenerator                                    $htmlGenerator      // Added
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->designGenerator = $designGenerator; // Added
        $this->htmlGenerator = $htmlGenerator;     // Added
    }

    /**
     * Generate or improve content based on the provided prompt and optional data source.
     *
     * @param string $customPrompt The user-provided prompt.
     * @param string $actionType ('generate' or 'improve')
     * @param string|null $dataSourceType ('product', 'category', or null/empty for none)
     * @param string|int|null $sourceId (Product ID or Category ID if $dataSourceType is set)
     * @param int $storeId The ID of the store context.
     * @param string|null $designPlan The existing design plan if actionType is 'improve'
     * @param string|null $currentContent The current HTML content if actionType is 'improve'
     * @param string|null $referenceImageUrl Optional URL for a reference image.
     * @param bool $generateInteractive Whether to instruct the AI to use GraphQL.
     * @return array ['design' => string|null, 'html' => string]
     * @throws LocalizedException
     */
    public function generate(
        string  $customPrompt,
        string  $actionType = 'generate',
        ?string $dataSourceType = null,
                $sourceId = null,
        int     $storeId = 0,
        ?string $designPlan = null,
        ?string $currentContent = null,
        ?string $referenceImageUrl = null, // Added parameter
        bool    $generateInteractive = false // Added parameter with default
    ): array
    {
        $apiKey = $this->getApiKey($storeId);
        if (!$apiKey) {
            throw new LocalizedException(__('OpenRouter API Key is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
        }

        // Allow empty custom prompt if a data source is provided for automatic generation
        if (empty($customPrompt) && empty($dataSourceType)) {
            throw new LocalizedException(__('A custom prompt is required for generation if no Product or Category is selected.'));
        }

        $technicalDesign = null;
        $generatedHtml = '';
        $contextData = $this->buildContextData($dataSourceType, $sourceId, $storeId); // Build context once

        if ($actionType === 'generate') {
            // --- Stage 1: Generate Technical Design ---
            $thinkingModel = $this->getThinkingModel($storeId);
            if (!$thinkingModel) {
                throw new LocalizedException(__('OpenRouter Thinking Model is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
            }

            try {
                $technicalDesign = $this->designGenerator->generateDesign(
                    $apiKey,
                    $thinkingModel,
                    $customPrompt,
                    $contextData,
                    $dataSourceType,
                    $storeId,
                    $referenceImageUrl,
                    $generateInteractive
                );
            } catch (LocalizedException $e) {
                $this->logger->error('Error during Stage 1 Design generation: ' . $e->getMessage());
                // Return error in HTML field for consistency, design is null
                return [
                    'design' => null,
                    'html' => __('Error generating design (Stage 1): %1', $e->getMessage()),
                ];
            }

            // --- Stage 2: Generate HTML from Design ---
            $renderingModel = $this->getRenderingModel($storeId);
            if (!$renderingModel) {
                throw new LocalizedException(__('OpenRouter Rendering Model is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
            }

            try {
                $generatedHtml = $this->htmlGenerator->generateHtmlFromDesign(
                    $apiKey,
                    $renderingModel,
                    $technicalDesign, // Pass the generated design
                    $contextData,
                    $storeId,
                    $referenceImageUrl,
                    $generateInteractive
                );
            } catch (LocalizedException $e) {
                $this->logger->error('Error during Stage 2 HTML generation: ' . $e->getMessage());
                // Return the design plan along with the HTML error
                return [
                    'design' => $technicalDesign,
                    'html' => __('Error generating HTML (Stage 2): %1', $e->getMessage()),
                ];
            }

        } elseif ($actionType === 'improve') {
            $renderingModel = $this->getRenderingModel($storeId); // Get rendering model once for improve/retry
            if (!$renderingModel) {
                throw new LocalizedException(__('OpenRouter Rendering Model is not configured for improvement/retry.'));
            }

            // Determine if it's a retry (has design plan, short/no current content) or standard improvement
            if (!empty($designPlan) && strlen((string)$currentContent) < 300) {
                // Scenario: Retry Stage 2 using existing design plan
                try {
                    $generatedHtml = $this->htmlGenerator->retryHtmlFromDesign(
                        $apiKey,
                        $renderingModel,
                        $designPlan,
                        $customPrompt, // Pass custom prompt as additional instructions
                        $contextData,
                        $storeId,
                        $referenceImageUrl,
                        $generateInteractive
                    );
                    $technicalDesign = $designPlan; // Keep the original design plan
                } catch (LocalizedException $e) {
                    $this->logger->error('Error during Retry Stage 2 HTML generation: ' . $e->getMessage());
                    return [
                        'design' => $designPlan, // Return original design plan on retry error
                        'html' => __('Error retrying HTML generation (Stage 2): %1', $e->getMessage()),
                    ];
                }
            } else {
                // Scenario: Standard Improvement
                try {
                    $generatedHtml = $this->htmlGenerator->improveHtml(
                        $apiKey,
                        $renderingModel,
                        $customPrompt,
                        $currentContent,
                        $contextData,
                        $storeId,
                        $referenceImageUrl,
                        $generateInteractive
                    );
                    $technicalDesign = null; // No design plan for standard improvement
                } catch (LocalizedException $e) {
                    $this->logger->error('Error during Improve Stage HTML generation: ' . $e->getMessage());
                    return [
                        'design' => null,
                        'html' => __('Error improving HTML: %1', $e->getMessage()),
                    ];
                }
            }
        } else {
            throw new LocalizedException(__('Invalid action type specified: %1', $actionType));
        }

        // Check if $generatedHtml is still null (shouldn't happen if exceptions are caught)
        if ($generatedHtml === null) {
            $this->logger->error('HTML generation resulted in null unexpectedly.');
            return [
                'design' => $technicalDesign,
                'html' => __('An unexpected error occurred during HTML processing.'),
            ];
        }

        // Basic cleanup (remains here)
        $generatedHtml = preg_replace('/^```(html)?\s*/i', '', $generatedHtml);
        $generatedHtml = preg_replace('/\s*```$/i', '', $generatedHtml);

        return [
            'design' => $technicalDesign,
            'html' => trim($generatedHtml)
        ];
    }

    /**
     * Get the configured API key for a specific store scope.
     *
     * @param int $storeId
     * @return string|null
     */
    private function getApiKey(int $storeId): ?string
    {
        $key = $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $key ? $this->encryptor->decrypt($key) : null;
    }

    /**
     * Build the structured context data array from store and product/category.
     *
     * @param string|null $dataSourceType
     * @param int|string|null $sourceId
     * @param int $storeId
     * @return array ['store_context' => string, 'data_source_context' => string]
     */
    private function buildContextData(?string $dataSourceType, $sourceId, int $storeId): array
    {
        $storeContext = '';
        $dataSourceContext = '';

        // --- Build Store Context ---
        try {
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            $locale = $this->scopeConfig->getValue(
                Data::XML_PATH_DEFAULT_LOCALE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $storeContext .= "Store Name: " . $store->getName() . "\n";
            $storeContext .= "Base URL: " . $baseUrl . "\n";
            if ($locale) {
                $storeContext .= "Locale: " . $locale . "\n";
            }
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Could not load store context for AiLand generation.', ['store_id' => $storeId]);
            $storeContext .= "(Note: Could not retrieve context data for Store ID " . $storeId . ")\n";
        }

        // --- Build Data Source Context ---
        if (!empty($dataSourceType) && !empty($sourceId)) {
            try {
                switch ($dataSourceType) {
                    case 'product':
                        $product = $this->productRepository->getById((int)$sourceId, false, $storeId);
                        $promptData = [
                            'SKU' => $product->getSku(),
                            'Product Name' => $product->getName(),
                            'Price' => $product->getPriceInfo()->getPrice('final_price')->getValue(),
                            'Short Description' => $product->getShortDescription(),
                            'Full Description' => $product->getDescription(),
                            'Meta Description' => $product->getMetaDescription()
                        ];
                        foreach ($promptData as $key => $value) {
                            if (!empty($value)) {
                                $dataSourceContext .= $key . ": " . strip_tags((string)$value) . "\n";
                            }
                        }
                        break;

                    case 'category':
                        $category = $this->categoryRepository->get((int)$sourceId, $storeId);
                        $products = $category->getProductCollection()
                            ->setStoreId($storeId)
                            ->addAttributeToSelect('name')
                            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                            ->addAttributeToFilter('visibility', ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]])
                            ->setPageSize(10)
                            ->setCurPage(1);
                        $productNames = [];
                        foreach ($products as $product) {
                            $productNames[] = $product->getName();
                        }
                        $promptData = [
                            'Category ID' => $category->getId(),
                            'Category Name' => $category->getName(),
                            'Description' => $category->getDescription(),
                            'Products in this category include' => implode(', ', $productNames)
                        ];
                        foreach ($promptData as $key => $value) {
                            if (!empty($value)) {
                                $dataSourceContext .= $key . ": " . strip_tags((string)$value) . "\n";
                            }
                        }
                        break;
                }
            } catch (NoSuchEntityException $e) {
                $this->logger->warning('Could not load data source context for AiLand generation.', ['type' => $dataSourceType, 'id' => $sourceId, 'store_id' => $storeId]);
                $dataSourceContext .= "(Note: Could not retrieve context data for " . $dataSourceType . " ID " . $sourceId . " in Store ID " . $storeId . ")\n";
            }
        }

        return [
            'store_context' => trim($storeContext),
            'data_source_context' => trim($dataSourceContext)
        ];
    }

    /**
     * Get the configured Thinking Model name for a specific store scope.
     *
     * @param int $storeId
     * @return string|null
     */
    private function getThinkingModel(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_THINKING_MODEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: self::DEFAULT_THINKING_MODEL;
    }

    /**
     * Get the configured Rendering Model name for a specific store scope.
     *
     * @param int $storeId
     * @return string|null
     */
    private function getRenderingModel(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_RENDERING_MODEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: self::DEFAULT_RENDERING_MODEL;
    }
}
