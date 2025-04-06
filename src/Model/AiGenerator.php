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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader; // Keep for prompt reading
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Exception\FileSystemException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory; // To load theme model
use Magento\Framework\Component\ComponentRegistrarInterface; // To get theme directory path
use Magento\Framework\Component\ComponentRegistrar; // For component type constants
use Magento\Framework\View\DesignInterface; // Needed for XML_PATH_THEME_ID constant

/**
 * Service class responsible for generating content using AI (OpenRouter).
 */
class AiGenerator
{
    // Config paths
    const XML_PATH_API_KEY = 'ailand/openrouter/api_key';
    const XML_PATH_THINKING_MODEL = 'ailand/openrouter/thinking_model'; // New path
    const XML_PATH_RENDERING_MODEL = 'ailand/openrouter/rendering_model'; // New path
    const XML_PATH_PRODUCT_PROMPT = 'ailand/openrouter/product_base_prompt';
    const XML_PATH_CATEGORY_PROMPT = 'ailand/openrouter/category_base_prompt';
    const OPENROUTER_API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    const MODULE_NAME = 'NeutromeLabs_AiLand'; // Module name for directory reading

    // Default Models
    const DEFAULT_THINKING_MODEL = 'deepseek/deepseek-r1:free';
    const DEFAULT_RENDERING_MODEL = 'deepseek/deepseek-chat-v3-0324:free';


    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
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
     * @var Curl
     */
    private $httpClient;

    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ModuleDirReader
     */
    private $moduleDirReader;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ThemeCollectionFactory
     */
    private $themeCollectionFactory;

    /**
     * @var ComponentRegistrarInterface
     */
    private $componentRegistrar;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param Curl $httpClient
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     * @param ModuleDirReader $moduleDirReader
     * @param FileDriver $fileDriver
     * @param StoreManagerInterface $storeManager
     * @param ThemeCollectionFactory $themeCollectionFactory
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        Curl $httpClient,
        JsonSerializer $jsonSerializer,
         LoggerInterface $logger,
         ModuleDirReader $moduleDirReader,
         FileDriver $fileDriver,
         StoreManagerInterface $storeManager,
         ThemeCollectionFactory $themeCollectionFactory,
         ComponentRegistrarInterface $componentRegistrar
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
         $this->httpClient = $httpClient;
         $this->jsonSerializer = $jsonSerializer;
         $this->logger = $logger;
         $this->moduleDirReader = $moduleDirReader;
         $this->fileDriver = $fileDriver;
         $this->storeManager = $storeManager;
         $this->themeCollectionFactory = $themeCollectionFactory;
         $this->componentRegistrar = $componentRegistrar;
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
        string $customPrompt,
        string $actionType = 'generate',
        ?string $dataSourceType = null,
        $sourceId = null,
        int $storeId = 0,
        ?string $designPlan = null,
        ?string $currentContent = null,
        ?string $referenceImageUrl = null, // Added parameter
        bool $generateInteractive = false // Added parameter with default
    ): array {
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

        if ($actionType === 'generate') {
            // --- Stage 1: Generate Technical Design ---
            $this->logger->info('Starting AI Generation Stage 1: Technical Design', ['store_id' => $storeId]);
            $contextData = $this->buildContextData($dataSourceType, $sourceId, $storeId);
            $designUserPrompt = $this->buildDesignUserPrompt($customPrompt, $contextData, $dataSourceType);
            $designSystemPrompt = $this->getPromptFromFile('design_system_prompt.txt');
            if (!$designSystemPrompt) {
                throw new LocalizedException(__('Could not load design system prompt.'));
            }
            $designMessages = [
                ['role' => 'system', 'content' => $designSystemPrompt]
            ];
            if (!empty($contextData['store_context'])) {
                $designMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
            }
            if (!empty($contextData['data_source_context'])) {
                $designMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $contextData['data_source_context']];
            }
            // Conditionally add instruction to use GraphQL
            if ($generateInteractive) {
                $htmlMessages[] = [
                    'role' => 'user',
                    'content' => "IMPORTANT IMPLEMENTATION NOTE: When generating the HTML and JavaScript, DO NOT hardcode dynamic data (like product names, prices, descriptions, category lists, etc.) or actions (like add to cart). Instead, implement the necessary logic using Magento 2's GraphQL API. Assume the GraphQL endpoint is available at '/graphql'. Use appropriate queries and mutations for data fetching and actions."
                ];
                $this->logger->info('Adding GraphQL instruction for interactive page generation.');
            } else {
                 $this->logger->info('Skipping GraphQL instruction for static page generation.');
            }
            // Add the final user prompt (goal + custom instructions)
            // This is the message we'll potentially modify for multimodal input
            $designMessages[] = ['role' => 'user', 'content' => $designUserPrompt];

            // --- Add Image URL to the last user message if provided ---
            if ($referenceImageUrl && !empty(trim($referenceImageUrl))) {
                $designMessages[] = [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Reference Image:'],
                        ['type' => 'image_url', 'image_url' => ['url' => trim($referenceImageUrl)]]
                    ]
                ];
                $this->logger->info('Added reference image to Design stage request.', ['url' => trim($referenceImageUrl)]);
            }
            // --- End Image URL addition ---

            $thinkingModel = $this->getThinkingModel($storeId);
            if (!$thinkingModel) {
                 throw new LocalizedException(__('OpenRouter Thinking Model is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
            }
            $technicalDesign = $this->callOpenRouterApi($apiKey, $thinkingModel, $designMessages, 'Stage 1 (Design)');
            $this->logger->info('Completed AI Generation Stage 1.');

            // --- Stage 2: Generate HTML from Design ---
            $this->logger->info('Starting AI Generation Stage 2: HTML Generation', ['store_id' => $storeId]);
            $htmlSystemPrompt = $this->getPromptFromFile('html_system_prompt.txt');
            if (!$htmlSystemPrompt) {
                throw new LocalizedException(__('Could not load HTML system prompt.'));
            }
            $tailwindConfig = $this->getTailwindConfig($storeId);

            $htmlMessages = [
                ['role' => 'system', 'content' => $htmlSystemPrompt]
            ];
            if (!empty($contextData['store_context'])) {
                $htmlMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
            }
            if (!empty($contextData['data_source_context'])) {
                $htmlMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $contextData['data_source_context']];
            }
            $htmlMessages[] = ['role' => 'user', 'content' => "Technical Design Plan:\n" . $technicalDesign];
            if ($tailwindConfig) {
                $htmlMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration:\n```javascript\n" . $tailwindConfig . "\n```"];
            }
            // Conditionally add instruction to use GraphQL
            if ($generateInteractive) {
                $htmlMessages[] = [
                    'role' => 'user',
                    'content' => "IMPORTANT IMPLEMENTATION NOTE: When generating the HTML and JavaScript, DO NOT hardcode dynamic data (like product names, prices, descriptions, category lists, etc.) or actions (like add to cart). Instead, implement the necessary logic using Magento 2's GraphQL API. Assume the GraphQL endpoint is available at '/graphql'. Use appropriate queries and mutations for data fetching and actions."
                ];
                $this->logger->info('Adding GraphQL instruction for interactive page generation.');
            } else {
                 $this->logger->info('Skipping GraphQL instruction for static page generation.');
            }
            if ($referenceImageUrl && !empty(trim($referenceImageUrl))) {
                $htmlMessages[] = [
                    'role' => 'user', 
                    'content' => [
                        ['type' => 'text', 'text' => 'Reference Image:'],
                        ['type' => 'image_url', 'image_url' => ['url' => trim($referenceImageUrl)]]
                    ],
                ];
                 $this->logger->info('Added reference image to HTML stage request.', ['url' => trim($referenceImageUrl)]);
            }
             // --- End Image URL addition ---

            $generatedHtml = null;
            try {
                $renderingModel = $this->getRenderingModel($storeId);
                if (!$renderingModel) {
                    throw new LocalizedException(__('OpenRouter Rendering Model is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
                }
                $generatedHtml = $this->callOpenRouterApi($apiKey, $renderingModel, $htmlMessages, 'Stage 2 (HTML)');
                $this->logger->info('Completed AI Generation Stage 2.');
            } catch (LocalizedException $e) {
                $this->logger->error('Error during Stage 2 HTML generation: ' . $e->getMessage());
                return [
                    'design' => $technicalDesign,
                    'html' => __('Error generating HTML (Stage 2): %1', $e->getMessage()),
                ];
            }
        } elseif ($actionType === 'improve') {
            $this->logger->info('Starting AI Generation: Improve/Retry HTML', ['store_id' => $storeId]);
            $improveContextData = $this->buildContextData($dataSourceType, $sourceId, $storeId);
            $tailwindConfig = $this->getTailwindConfig($storeId);
            $renderingModel = $this->getRenderingModel($storeId); // Get rendering model once for improve/retry
             if (!$renderingModel) {
                throw new LocalizedException(__('OpenRouter Rendering Model is not configured for improvement/retry.'));
            }


            if (!empty($designPlan) && strlen((string)$currentContent) < 300) {
                // Scenario: Retry Stage 2 using existing design plan
                $this->logger->info('Retrying Stage 2 HTML generation using existing design plan.');
                 if (empty($customPrompt)) {
                     $this->logger->info('No specific improvement prompt provided for retry, generating based on design.');
                 } else {
                     $this->logger->info('Improvement prompt provided for retry: ' . $customPrompt);
                 }
                 $htmlSystemPrompt = $this->getPromptFromFile('html_system_prompt.txt');
                 if (!$htmlSystemPrompt) {
                     throw new LocalizedException(__('Could not load HTML system prompt for retry.'));
                 }
                 $retryHtmlMessages = [
                     ['role' => 'system', 'content' => $htmlSystemPrompt]
                 ];
                 if (!empty($improveContextData['store_context'])) {
                     $retryHtmlMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $improveContextData['store_context']];
                 }
                 if (!empty($improveContextData['data_source_context'])) {
                     $retryHtmlMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $improveContextData['data_source_context']];
                 }
                 $retryHtmlMessages[] = ['role' => 'user', 'content' => "Technical Design Plan:\n" . $designPlan];
                 if ($tailwindConfig) {
                     $retryHtmlMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration (NOTE: this are not available on preview. Use for reference only):\n```javascript\n" . $tailwindConfig . "\n```"];
                 }
                 if (!empty($customPrompt)) {
                    $retryHtmlMessages[] = ['role' => 'user', 'content' => "Additional User Instructions for this attempt:\n" . $customPrompt];
                 }

                 // --- Add Image URL to the last user message if provided ---
                 if ($referenceImageUrl && !empty(trim($referenceImageUrl))) {
                     $lastMessageIndex = count($retryHtmlMessages) - 1;
                     if ($retryHtmlMessages[$lastMessageIndex]['role'] === 'user') {
                         $originalText = $retryHtmlMessages[$lastMessageIndex]['content'];
                         $retryHtmlMessages[$lastMessageIndex]['content'] = [
                             ['type' => 'text', 'text' => $originalText],
                             ['type' => 'image_url', 'image_url' => ['url' => trim($referenceImageUrl)]]
                         ];
                         $this->logger->info('Added reference image to Retry stage request.', ['url' => trim($referenceImageUrl)]);
                     }
                 }
                 // --- End Image URL addition ---

                try {
                    // Conditionally add instruction to use GraphQL for retry
                    if ($generateInteractive) {
                        $retryHtmlMessages[] = [
                            'role' => 'user',
                            'content' => "IMPORTANT IMPLEMENTATION NOTE: When generating the HTML and JavaScript, DO NOT hardcode dynamic data (like product names, prices, descriptions, category lists, etc.) or actions (like add to cart). Instead, implement the necessary logic using Magento 2's GraphQL API. Assume the GraphQL endpoint is available at '/graphql'. Use appropriate queries and mutations for data fetching and actions."
                        ];
                        $this->logger->info('Adding GraphQL instruction for interactive page retry.');
                    } else {
                        $this->logger->info('Skipping GraphQL instruction for static page retry.');
                    }
                    $generatedHtml = $this->callOpenRouterApi($apiKey, $renderingModel, $retryHtmlMessages, 'Retry Stage 2 (HTML)');
                    $this->logger->info('Completed Retry Stage 2.');
                    $technicalDesign = $designPlan;
                } catch (LocalizedException $e) {
                     $this->logger->error('Error during Retry Stage 2 HTML generation: ' . $e->getMessage());
                     return [
                        'design' => $designPlan,
                        'html' => __('Error retrying HTML generation (Stage 2): %1', $e->getMessage()),
                     ];
                }
            } else {
                // Scenario: Standard Improvement
                $this->logger->info('Performing standard HTML improvement.');
                 if (empty($customPrompt)) {
                     throw new LocalizedException(__('An improvement instruction is required in the prompt field when improving content.'));
                 }
                 $improveSystemPrompt = $this->getPromptFromFile('improve_system_prompt.txt');
                 if (!$improveSystemPrompt) {
                     throw new LocalizedException(__('Could not load improve system prompt.'));
                 }
                 $improveMessages = [
                     ['role' => 'system', 'content' => $improveSystemPrompt]
                 ];
                 if (!empty($improveContextData['store_context'])) {
                     $improveMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $improveContextData['store_context']];
                 }
                 if (!empty($improveContextData['data_source_context'])) {
                     $improveMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $improveContextData['data_source_context']];
                 }
                 $improveMessages[] = ['role' => 'user', 'content' => "Current HTML Block:\n" . ($currentContent ?: '(empty)')];
                 if ($tailwindConfig) {
                     $improveMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration (NOTE: this are not available on preview. Use for reference only):\n```javascript\n" . $tailwindConfig . "\n```"];
                 }

                // Conditionally add instruction to use GraphQL for improvement
                if ($generateInteractive) {
                    $improveMessages[] = [
                        'role' => 'user',
                        'content' => "IMPORTANT IMPLEMENTATION NOTE: When generating the HTML and JavaScript, DO NOT hardcode dynamic data (like product names, prices, descriptions, category lists, etc.) or actions (like add to cart). Instead, implement the necessary logic using Magento 2's GraphQL API. Assume the GraphQL endpoint is available at '/graphql'. Use appropriate queries and mutations for data fetching and actions."
                    ];
                    $this->logger->info('Adding GraphQL instruction for interactive page improvement.');
                } else {
                    $this->logger->info('Skipping GraphQL instruction for static page improvement.');
                }

                 $improveMessages[] = ['role' => 'user', 'content' => "User's Improvement Request:\n" . $customPrompt];

                 // --- Add Image URL to the last user message if provided ---
                 if ($referenceImageUrl && !empty(trim($referenceImageUrl))) {
                     $lastMessageIndex = count($improveMessages) - 1;
                     if ($improveMessages[$lastMessageIndex]['role'] === 'user') {
                         $originalText = $improveMessages[$lastMessageIndex]['content'];
                         $improveMessages[$lastMessageIndex]['content'] = [
                             ['type' => 'text', 'text' => $originalText],
                             ['type' => 'image_url', 'image_url' => ['url' => trim($referenceImageUrl)]]
                         ];
                          $this->logger->info('Added reference image to Improve stage request.', ['url' => trim($referenceImageUrl)]);
                     }
                 }
                 // --- End Image URL addition ---

                 try {
                    
                    $generatedHtml = $this->callOpenRouterApi($apiKey, $renderingModel, $improveMessages, 'Improve Stage');
                    $this->logger->info('Completed AI Generation: Improve HTML.');
                    $technicalDesign = null;
                 } catch (LocalizedException $e) {
                     $this->logger->error('Error during Improve Stage HTML generation: ' . $e->getMessage());
                     return [
                        'design' => null,
                        'html' => __('Error improving HTML: %1', $e->getMessage()),
                     ];
                 }
            }
        }

        // Check if $generatedHtml is still null
        if ($generatedHtml === null) {
             $this->logger->error('HTML generation resulted in null unexpectedly.');
             return [
                'design' => $technicalDesign,
                'html' => __('An unexpected error occurred during HTML processing.'),
             ];
        }

        // Basic cleanup
        $generatedHtml = preg_replace('/^```(html)?\s*/i', '', $generatedHtml);
        $generatedHtml = preg_replace('/\s*```$/i', '', $generatedHtml);

        return [
            'design' => $technicalDesign,
            'html' => trim($generatedHtml)
        ];
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
            $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            $locale = $this->scopeConfig->getValue(
                \Magento\Directory\Helper\Data::XML_PATH_DEFAULT_LOCALE,
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
                                             ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                                             ->addAttributeToFilter('visibility', ['in' => [\Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG, \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH]])
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
     * Build the user instruction part of the prompt for the design stage.
     *
     * @param string $customPrompt
     * @param array $contextData
     * @param string|null $dataSourceType
     * @return string
     */
    private function buildDesignUserPrompt(string $customPrompt, array $contextData, ?string $dataSourceType): string
    {
        $basePromptType = !empty($contextData['data_source_context']) ? $dataSourceType : null;
        $contentGoal = $this->getBasePrompt($basePromptType);

        $userInstructionPrompt = "Content Goal: " . $contentGoal . "\n";

        if (!empty($customPrompt)) {
            $userInstructionPrompt .= "User's Custom Instructions: " . $customPrompt . "\n";
        }

        return trim($userInstructionPrompt);
    }


    /**
     * Call the OpenRouter API and handle response/errors.
     *
     * @param string $apiKey
     * @param string $model
     * @param array $messages
     * @param string $stageIdentifier For logging
     * @return string The content from the response
     * @throws LocalizedException
     */
    private function callOpenRouterApi(string $apiKey, string $model, array $messages, string $stageIdentifier): string
    {
        $payload = [
            'model' => $model,
            'messages' => $messages
        ];

        try {
             $this->logger->debug("OpenRouter Request [$stageIdentifier] Payload: " . $this->jsonSerializer->serialize($payload));
             $ch = curl_init();
             curl_setopt($ch, CURLOPT_URL, self::OPENROUTER_API_ENDPOINT);
             curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->jsonSerializer->serialize($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            $responseBody = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->logger->debug("OpenRouter Response [$stageIdentifier] Status: " . $statusCode);
            $this->logger->debug("OpenRouter Response [$stageIdentifier] Body: " . trim($responseBody));

            if ($curlError) {
                throw new LocalizedException(__("cURL Error calling OpenRouter API [$stageIdentifier]: %1", $curlError));
            }

            if ($statusCode !== 200) {
                $errorDetails = $responseBody;
                try {
                    $decodedError = $this->jsonSerializer->unserialize($responseBody);
                    if (isset($decodedError['error']['message'])) {
                        $errorDetails = $decodedError['error']['message'];
                    }
                } catch (\Exception $e) { /* Ignore unserialize errors */ }
                throw new LocalizedException(__("Error communicating with OpenRouter API [$stageIdentifier]: Status %1 - %2", $statusCode, $errorDetails));
            }

            $responseData = $this->jsonSerializer->unserialize($responseBody);

            if (isset($responseData['error']['message'])) {
                $providerErrorMessage = $responseData['error']['message'];
                $this->logger->error("OpenRouter returned an error in the response body [$stageIdentifier].", ['error' => $responseData['error']]);
                throw new LocalizedException(__("AI Service Error [$stageIdentifier]: %1", $providerErrorMessage));
            }

            $content = $responseData['choices'][0]['message']['content'] ?? null;

            if ($content === null) {
                $this->logger->error("Could not extract content from OpenRouter response [$stageIdentifier].", ['response' => $responseData]);
                throw new LocalizedException(__("OpenRouter API returned an unexpected response format or empty content [$stageIdentifier]."));
            }

            return trim($content);

        } catch (LocalizedException $e) {
            $this->logger->error("OpenRouter API Error [$stageIdentifier]: " . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->critical("Error calling OpenRouter API [$stageIdentifier]: " . $e->getMessage(), ['exception' => $e]);
            throw new LocalizedException(__("An unexpected error occurred while calling the AI service [$stageIdentifier]: %1", $e->getMessage()));
        }
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

    /**
     * Get the CONTENT-focused base prompt instruction based on data source type.
     *
     * @param string|null $dataSourceType
     * @return string
     */
     private function getBasePrompt(?string $dataSourceType): string
     {
         $configPath = null;
         $defaultPromptFile = 'default_generic_prompt.txt';

         switch ($dataSourceType) {
             case 'product':
                 $configPath = self::XML_PATH_PRODUCT_PROMPT;
                 $defaultPromptFile = 'default_product_prompt.txt';
                 break;
             case 'category':
                 $configPath = self::XML_PATH_CATEGORY_PROMPT;
                 $defaultPromptFile = 'default_category_prompt.txt';
                 break;
         }

         $prompt = $configPath ? $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE) : null;

         if (empty($prompt)) {
             $prompt = $this->getPromptFromFile($defaultPromptFile);
             if (empty($prompt)) {
                 $this->logger->error('Could not load default prompt from file: ' . $defaultPromptFile);
                 return 'Generate content based on the provided context.';
             }
         }

         return trim($prompt);
     }

     /**
      * Reads a prompt string from a file within the module's etc/prompts directory.
      *
      * @param string $filename
      * @return string
      */
     private function getPromptFromFile(string $filename): string
     {
         try {
             $promptDir = $this->moduleDirReader->getModuleDir(
                 \Magento\Framework\Module\Dir::MODULE_ETC_DIR,
                 self::MODULE_NAME
             ) . '/prompts';
             $filePath = $promptDir . '/' . $filename;

             if ($this->fileDriver->isExists($filePath) && $this->fileDriver->isReadable($filePath)) {
                 return trim($this->fileDriver->fileGetContents($filePath));
             } else {
                 $this->logger->warning('Prompt file not found or not readable: ' . $filePath);
             }
         } catch (FileSystemException | LocalizedException $e) {
             $this->logger->error('Error reading prompt file: ' . $filename . ' - ' . $e->getMessage());
          }
          return '';
      }

     /**
      * Get Tailwind config content for the given store's theme.
      *
      * @param int $storeId
      * @return string|null
      */
     private function getTailwindConfig(int $storeId): ?string
     {
         try {
             $themeId = $this->scopeConfig->getValue(
                 DesignInterface::XML_PATH_THEME_ID,
                 ScopeInterface::SCOPE_STORE,
                 $storeId
             );

             if (!$themeId) {
                 $this->logger->info('No theme ID configured for store.', ['store_id' => $storeId]);
                 return null;
             }

             $themeCollection = $this->themeCollectionFactory->create();
             $theme = $themeCollection->getItemById((int)$themeId);

             if (!$theme || !$theme->getId()) {
                 $this->logger->warning('Could not load theme model for configured ID.', ['theme_id' => $themeId, 'store_id' => $storeId]);
                 return null;
             }

             $themePathIdentifier = $theme->getFullPath();
             if (!$themePathIdentifier) {
                  $this->logger->warning('Theme model does not have a path identifier.', ['theme_id' => $themeId]);
                  return null;
             }
             $themeDir = $this->componentRegistrar->getPath(ComponentRegistrar::THEME, $themePathIdentifier);

             if (!$themeDir) {
                 $this->logger->warning('Could not resolve theme directory path using ComponentRegistrar.', ['theme_path_id' => $themePathIdentifier, 'store_id' => $storeId]);
                 return null;
             }

             $tailwindConfigPath = $themeDir . '/web/tailwind/tailwind.config.js';
             $tailwindConfigRelPath = 'web/tailwind/tailwind.config.js'; // Define for logging

             if ($this->fileDriver->isExists($tailwindConfigPath) && $this->fileDriver->isReadable($tailwindConfigPath)) {
                 $this->logger->info('Found Tailwind config for theme.', ['path' => $tailwindConfigPath, 'store_id' => $storeId]);
                 return $this->fileDriver->fileGetContents($tailwindConfigPath);
             } else {
                 $this->logger->info('Tailwind config not found or not readable for theme.', [
                     'expected_relative_path' => $tailwindConfigRelPath, // Use defined variable
                     'resolved_path' => $tailwindConfigPath ?: 'Not Resolved',
                     'theme_path' => $themeDir, // Log resolved theme dir
                     'store_id' => $storeId
                 ]);
                 return null;
             }
         } catch (LocalizedException $e) {
             $this->logger->error('Error resolving theme or Tailwind config path: ' . $e->getMessage(), ['store_id' => $storeId]);
         } catch (FileSystemException $e) {
             $this->logger->error('Filesystem error reading Tailwind config: ' . $e->getMessage(), ['store_id' => $storeId]);
         } catch (\Exception $e) {
             $this->logger->error('Unexpected error getting Tailwind config: ' . $e->getMessage(), ['store_id' => $storeId, 'exception' => $e]);
         }

         return null;
     }
 }
