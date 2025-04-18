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
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory; // Added

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
     * @var AttributeCollectionFactory // Added
     */
    private $attributeCollectionFactory;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param DesignGenerator $designGenerator
     * @param HtmlGenerator $htmlGenerator
     * @param AttributeCollectionFactory $attributeCollectionFactory // Added
     */
    public function __construct(
        ScopeConfigInterface                             $scopeConfig,
        EncryptorInterface $encryptor,
        ProductRepositoryInterface                       $productRepository,
        CategoryRepositoryInterface                      $categoryRepository,
        LoggerInterface                                  $logger,
        StoreManagerInterface                            $storeManager,
        DesignGenerator                                  $designGenerator,
        HtmlGenerator                                    $htmlGenerator,
        AttributeCollectionFactory                       $attributeCollectionFactory // Added
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->designGenerator = $designGenerator;
        $this->htmlGenerator = $htmlGenerator;
        $this->attributeCollectionFactory = $attributeCollectionFactory; // Added
    }

    /**
     * Generate or improve content based on the provided prompt and optional context data.
     *
     * @param string $customPrompt The user-provided prompt.
     * @param string $actionType ('generate' or 'improve')
     * @param int $storeId The ID of the store context.
     * @param string|null $designPlan The existing design plan if actionType is 'improve'
     * @param string|null $currentContent The current HTML content if actionType is 'improve'
     * @param string|null $referenceImageUrl Optional URL for a reference image.
     * @param bool $generateInteractive Whether to instruct the AI to use GraphQL.
     * @param string|null $dataSourceType Optional context type ('product', 'category').
     * @param string|int|null $sourceId Optional context ID (Product ID or Category ID).
     * @param string|null $stylingReferenceUrl Optional URL for styling reference.
     * @return array ['design' => string|null, 'html' => string]
     * @throws LocalizedException
     */
    public function generate(
        string  $customPrompt,
        string  $actionType = 'generate',
        int     $storeId = 0,
        ?string $designPlan = null,
        ?string $currentContent = null,
        ?string $referenceImageUrl = null,
        bool    $generateInteractive = false,
        ?string $dataSourceType = null, // Re-added
                $sourceId = null,       // Re-added
        ?string $stylingReferenceUrl = null // Added
    ): array
    {
        $apiKey = $this->getApiKey($storeId);
        if (!$apiKey) {
            throw new LocalizedException(__('OpenRouter API Key is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
        }

        // Custom prompt is always required now (context comes from goal)
        if (empty($customPrompt)) {
            throw new LocalizedException(__('A custom prompt is required for generation.'));
        }

        $technicalDesign = null;
        $generatedHtml = '';
        // Build context data, now including optional product/category info again
        $contextData = $this->buildContextData($dataSourceType, $sourceId, $storeId);

        if ($actionType === 'generate') {
            // --- Stage 1: Generate Technical Design ---
            try {
                // Pass generationGoal instead of dataSourceType
                $technicalDesign = $this->designGenerator->generateDesign(
                    $customPrompt,
                    $contextData, // Contains only store context now
                    // $generationGoal, // Removed goal parameter
                    $storeId,
                    $referenceImageUrl,
                    $generateInteractive,
                    $stylingReferenceUrl // Added
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
            try {
                // Pass generationGoal for context if needed by HtmlGenerator
                $generatedHtml = $this->htmlGenerator->generateHtmlFromDesign(
                    $technicalDesign, // Pass the generated design
                    $contextData, // Contains only store context now
                    // $generationGoal, // Removed goal parameter
                    $storeId,
                    $referenceImageUrl,
                    $generateInteractive,
                    $stylingReferenceUrl // Added
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
            // Determine if it's a retry (has design plan, short/no current content) or standard improvement
            if (!empty($designPlan) && strlen((string)$currentContent) < 300) {
                // Scenario: Retry Stage 2 using existing design plan
                try {
                    $generatedHtml = $this->htmlGenerator->retryHtmlFromDesign(
                        $designPlan,
                        $customPrompt, // Pass custom prompt as additional instructions
                        $contextData, // Contains only store context now
                        // $generationGoal, // Removed goal parameter
                        $storeId,
                        $referenceImageUrl,
                        $generateInteractive,
                        $stylingReferenceUrl // Added
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
                        $customPrompt,
                        $currentContent,
                        $contextData, // Contains only store context now
                        // $generationGoal, // Removed goal parameter
                        $storeId,
                        $referenceImageUrl,
                        $generateInteractive,
                        $stylingReferenceUrl // Added
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

                        // Load all visible attributes
                        $attributes = $this->attributeCollectionFactory->create()
                            ->addVisibleFilter(); // Filters for is_visible_on_front = 1

                        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
                        foreach ($attributes as $attribute) {
                            $attributeCode = $attribute->getAttributeCode();
                            $value = $product->getData($attributeCode);

                            // Try to get text value for select/multiselect
                            if ($attribute->usesSource()) {
                                $valueText = $product->getAttributeText($attributeCode);
                                if ($valueText) {
                                    $value = is_array($valueText) ? implode(', ', $valueText) : $valueText;
                                }
                            }

                            // Format boolean values
                            if ($attribute->getFrontendInput() === 'boolean') {
                                $value = $value ? 'Yes' : 'No';
                            }

                            if (!empty($value) && !is_array($value)) { // Ensure value is scalar and not empty
                                $label = $attribute->getStoreLabel($storeId) ?: $attribute->getFrontendLabel() ?: $attributeCode;
                                $dataSourceContext .= $label . ": " . strip_tags((string)$value) . "\n";
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
}
