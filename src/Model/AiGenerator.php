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
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Exception\FileSystemException;

/**
 * Service class responsible for generating content using AI (OpenRouter).
 */
class AiGenerator
{
    // Config paths for OpenRouter
    const XML_PATH_API_KEY = 'ailand/openrouter/api_key';
    const XML_PATH_MODEL = 'ailand/openrouter/model';
    const XML_PATH_PRODUCT_PROMPT = 'ailand/openrouter/product_base_prompt'; // Added
    const XML_PATH_CATEGORY_PROMPT = 'ailand/openrouter/category_base_prompt'; // Added
    const OPENROUTER_API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    const MODULE_NAME = 'NeutromeLabs_AiLand'; // Module name for directory reading

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private $encryptor; // Added encryptor to decrypt API key

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
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor // Added type hint
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param Curl $httpClient
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     * @param ModuleDirReader $moduleDirReader
     * @param FileDriver $fileDriver
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor, // Added parameter
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        Curl $httpClient,
        JsonSerializer $jsonSerializer,
        LoggerInterface $logger,
        ModuleDirReader $moduleDirReader,
        FileDriver $fileDriver
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
    }

    /**
     * Generate or improve content based on the provided prompt and optional data source.
     *
     * @param string $customPrompt The user-provided prompt.
     * @param string $actionType ('generate' or 'improve')
     * @param string|null $dataSourceType ('product', 'category', or null/empty for none)
     * @param string|int|null $sourceId (Product ID or Category ID if $dataSourceType is set)
     * @param string|null $designPlan The existing design plan if actionType is 'improve'
     * @param string|null $currentContent The current HTML content if actionType is 'improve'
     * @return array ['design' => string|null, 'html' => string]
     * @throws LocalizedException
     */
    public function generate(
        string $customPrompt,
        string $actionType = 'generate',
        ?string $dataSourceType = null,
        $sourceId = null,
        ?string $designPlan = null,
        ?string $currentContent = null
    ): array {
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        if (!$apiKey) {
            throw new LocalizedException(__('OpenRouter API Key is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
        }
        if (!$model) {
            // Use fallback default if not configured
            $model = 'qwen/qwq-32b:free';
            $this->logger->info('OpenRouter Model not configured, using default: ' . $model);
            // Alternatively, throw exception:
             // throw new LocalizedException(__('OpenRouter Model is not configured. Please configure it in Stores > Configuration > NeutromeLabs > AI Landings.'));
        }

        // Allow empty custom prompt if a data source is provided for automatic generation
        if (empty($customPrompt) && empty($dataSourceType)) {
             throw new LocalizedException(__('A custom prompt is required for generation if no Product or Category is selected.'));
        }

        $technicalDesign = null;
        $generatedHtml = '';

        if ($actionType === 'generate') {
            // --- Stage 1: Generate Technical Design ---
            $this->logger->info('Starting AI Generation Stage 1: Technical Design');
            $designContext = $this->buildContextData($dataSourceType, $sourceId);
            $designUserPrompt = $this->buildDesignUserPrompt($customPrompt, $designContext, $dataSourceType);
            $designSystemPrompt = $this->getPromptFromFile('design_system_prompt.txt');
            if (!$designSystemPrompt) {
                throw new LocalizedException(__('Could not load design system prompt.'));
            }
            $designMessages = [
                ['role' => 'system', 'content' => $designSystemPrompt],
                ['role' => 'user', 'content' => $designUserPrompt]
            ];
            $technicalDesign = $this->callOpenRouterApi($apiKey, $model, $designMessages, 'Stage 1 (Design)');
            $this->logger->info('Completed AI Generation Stage 1.');

            // --- Stage 2: Generate HTML from Design ---
            $this->logger->info('Starting AI Generation Stage 2: HTML Generation');
            $htmlSystemPrompt = $this->getPromptFromFile('html_system_prompt.txt');
            if (!$htmlSystemPrompt) {
                throw new LocalizedException(__('Could not load HTML system prompt.'));
            }
            // Note: designContext is reused from Stage 1
            $htmlMessages = [
                ['role' => 'system', 'content' => $htmlSystemPrompt],
                // Provide original context AND the generated design
                ['role' => 'user', 'content' => "Context Data:\n" . $designContext . "\n\nTechnical Design Plan:\n" . $technicalDesign]
            ];

            $generatedHtml = null; // Initialize
            try {
                $generatedHtml = $this->callOpenRouterApi($apiKey, $model, $htmlMessages, 'Stage 2 (HTML)');
                $this->logger->info('Completed AI Generation Stage 2.');
            } catch (LocalizedException $e) {
                // Keep technicalDesign, return error for HTML part
                $this->logger->error('Error during Stage 2 HTML generation: ' . $e->getMessage());
                return [
                    'design' => $technicalDesign, // Return the successful design
                    'html' => __('Error generating HTML (Stage 2): %1', $e->getMessage()),
                ];
                // Note: We don't throw the exception here, allowing the controller to get the design
            }
        } elseif ($actionType === 'improve') {
            $this->logger->info('Starting AI Generation: Improve/Retry HTML');
            $improveContext = $this->buildContextData($dataSourceType, $sourceId); // Get context again if needed

            if (!empty($designPlan) && strlen($currentContent) < 300) {
                // Scenario: Retry Stage 2 using existing design plan
                $this->logger->info('Retrying Stage 2 HTML generation using existing design plan.');
                if (empty($customPrompt)) {
                     $this->logger->info('No specific improvement prompt provided for retry, generating based on design.');
                } else {
                     $this->logger->info('Improvement prompt provided for retry: ' . $customPrompt);
                      // Optional: Could potentially incorporate the customPrompt into the retry message if desired
                 }
                 $htmlSystemPrompt = $this->getPromptFromFile('html_system_prompt.txt'); // Re-use HTML prompt for retry
                 if (!$htmlSystemPrompt) {
                     throw new LocalizedException(__('Could not load HTML system prompt for retry.'));
                 }
                 $htmlMessages = [
                     ['role' => 'system', 'content' => $htmlSystemPrompt],
                     ['role' => 'user', 'content' => "Context Data:\n" . $improveContext
                         . "\n\nTechnical Design Plan:\n" . $designPlan
                         . "\n\nAdditional User Instructions for this attempt:\n" . $customPrompt]
                ];
                try {
                    $generatedHtml = $this->callOpenRouterApi($apiKey, $model, $htmlMessages, 'Retry Stage 2 (HTML)');
                    $this->logger->info('Completed Retry Stage 2.');
                    // Keep the original design plan to return
                    $technicalDesign = $designPlan;
                } catch (LocalizedException $e) {
                     $this->logger->error('Error during Retry Stage 2 HTML generation: ' . $e->getMessage());
                     // Return original design and new error
                     return [
                        'design' => $designPlan,
                        'html' => __('Error retrying HTML generation (Stage 2): %1', $e->getMessage()),
                     ];
                }
            } else {
                // Scenario: Standard Improvement (HTML exists, or no design plan provided)
                $this->logger->info('Performing standard HTML improvement.');
                 if (empty($customPrompt)) {
                     throw new LocalizedException(__('An improvement instruction is required in the prompt field when improving content.'));
                 }
                 $improveSystemPrompt = $this->getPromptFromFile('improve_system_prompt.txt');
                 if (!$improveSystemPrompt) {
                     throw new LocalizedException(__('Could not load improve system prompt.'));
                 }
                 $improveMessages = [
                     ['role' => 'system', 'content' => $improveSystemPrompt],
                     // Provide original context, existing HTML (even if empty), and the improvement request
                     ['role' => 'user', 'content' => "Original Context Data:\n" . $improveContext . "\n\nCurrent HTML Block:\n" . ($currentContent ?: '(empty)') . "\n\nUser's Improvement Request:\n" . $customPrompt]
                 ];
                 try {
                    $generatedHtml = $this->callOpenRouterApi($apiKey, $model, $improveMessages, 'Improve Stage');
                    $this->logger->info('Completed AI Generation: Improve HTML.');
                    // Design is null when improving this way
                    $technicalDesign = null;
                 } catch (LocalizedException $e) {
                     $this->logger->error('Error during Improve Stage HTML generation: ' . $e->getMessage());
                     // Return null design and error
                     return [
                        'design' => null,
                        'html' => __('Error improving HTML: %1', $e->getMessage()),
                     ];
                 }
            }
        }

        // Check if $generatedHtml is still null (shouldn't happen with try/catch returning)
        if ($generatedHtml === null) {
             $this->logger->error('HTML generation resulted in null unexpectedly.');
             // Return the design if available, otherwise indicate a general failure
             return [
                'design' => $technicalDesign, // May be null if improve failed early
                'html' => __('An unexpected error occurred during HTML processing.'),
             ];
        }

        // Basic cleanup - remove potential markdown code blocks if AI wraps HTML in them
        $generatedHtml = preg_replace('/^```(html)?\s*/i', '', $generatedHtml);
        $generatedHtml = preg_replace('/\s*```$/i', '', $generatedHtml);

        return [
            'design' => $technicalDesign,
            'html' => trim($generatedHtml)
        ];
    }

    /**
     * Build the context data string from product/category.
     *
     * @param string|null $dataSourceType
     * @param int|string|null $sourceId
     * @return string
     */
    private function buildContextData(?string $dataSourceType, $sourceId): string
    {
        $contextData = '';
        if (!empty($dataSourceType) && !empty($sourceId)) {
            try {
                switch ($dataSourceType) {
                    case 'product':
                        $product = $this->productRepository->getById((int)$sourceId);
                        $promptData = [
                            'Product Name' => $product->getName(),
                            'Price' => $product->getPriceInfo()->getPrice('final_price')->getValue(),
                            'Short Description' => $product->getShortDescription(),
                            'Full Description' => $product->getDescription(),
                            'Meta Description' => $product->getMetaDescription()
                        ];
                        $contextData .= "Context from Product:\n";
                        foreach ($promptData as $key => $value) {
                            if (!empty($value)) {
                                $contextData .= $key . ": " . strip_tags((string)$value) . "\n";
                            }
                        }
                        break;

                    case 'category':
                        $category = $this->categoryRepository->get((int)$sourceId);
                        $products = $category->getProductCollection()
                                             ->addAttributeToSelect('name')
                                             ->setPageSize(10) // Limit context size
                                             ->setCurPage(1);
                        $productNames = [];
                        foreach ($products as $product) {
                            $productNames[] = $product->getName();
                        }
                        $promptData = [
                            'Category Name' => $category->getName(),
                            'Description' => $category->getDescription(),
                            'Products in this category include' => implode(', ', $productNames)
                        ];
                        $contextData .= "Context from Category:\n";
                        foreach ($promptData as $key => $value) {
                            if (!empty($value)) {
                                $contextData .= $key . ": " . strip_tags((string)$value) . "\n";
                            }
                        }
                        break;
                }
            } catch (NoSuchEntityException $e) {
                $this->logger->warning('Could not load context data for AiLand generation.', ['type' => $dataSourceType, 'id' => $sourceId]);
                $contextData .= "(Note: Could not retrieve context data for " . $dataSourceType . " ID " . $sourceId . ")\n";
            }
        }
        return trim($contextData);
    }

    /**
     * Build the user prompt for the design stage.
     *
     * @param string $customPrompt
     * @param string $contextData
     * @param string|null $dataSourceType
     * @return string
     */
    private function buildDesignUserPrompt(string $customPrompt, string $contextData, ?string $dataSourceType): string
    {
        // Get the high-level content goal
        $contentGoal = $this->getBasePrompt($dataSourceType); // Use the simplified content prompt

        $userPrompt = "Content Goal: " . $contentGoal . "\n";

        if (!empty($customPrompt)) {
            $userPrompt .= "User's Custom Instructions: " . $customPrompt . "\n";
        }

        if (!empty($contextData)) {
            $userPrompt .= "\n" . $contextData;
        }

        return trim($userPrompt);
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
            // Add other parameters like temperature, max_tokens if needed
            // 'temperature' => 0.7,
            // 'max_tokens' => 1500, // Adjust as needed
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Increased timeout for potentially longer calls

            $responseBody = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->logger->debug("OpenRouter Response [$stageIdentifier] Status: " . $statusCode);
            $this->logger->debug("OpenRouter Response [$stageIdentifier] Body: " . $responseBody);

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
            throw $e; // Re-throw known exceptions
        } catch (\Exception $e) {
            $this->logger->critical("Error calling OpenRouter API [$stageIdentifier]: " . $e->getMessage(), ['exception' => $e]);
            throw new LocalizedException(__("An unexpected error occurred while calling the AI service [$stageIdentifier]: %1", $e->getMessage()));
        }
    }

    /**
     * Get the configured API key.
     *
     * @return string|null
     */
    private function getApiKey(): ?string
    {
        $key = $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $key ? $this->encryptor->decrypt($key) : null;
    }

     /**
     * Get the configured Model name.
     *
     * @return string|null
     */
    private function getModel(): ?string
    {
        // Read model from config, provide fallback default
        return $this->scopeConfig->getValue(
            self::XML_PATH_MODEL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'qwen/qwq-32b:free'; // Keep fallback, consider if different models are better for design vs html
    }

    /**
     * Get the CONTENT-focused base prompt instruction from config based on data source type.
     *
     * @param string|null $dataSourceType
     * @return string
     */
     private function getBasePrompt(?string $dataSourceType): string
     {
         $configPath = null;
         $defaultPromptFile = 'default_generic_prompt.txt';

         // Try reading from config first
         $prompt = $configPath ? $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE) : null;

         // If config is empty, read from the corresponding default file
         if (empty($prompt)) {
             $prompt = $this->getPromptFromFile($defaultPromptFile);
             if (empty($prompt)) {
                 // Log an error if the default file couldn't be read either
                 $this->logger->error('Could not load default prompt from file: ' . $defaultPromptFile);
                 // Return a hardcoded fallback to prevent complete failure
                 return 'Generate content based on the provided context.';
             }
         }

         return trim($prompt);
     }

     /**
      * Reads a prompt string from a file within the module's etc/prompts directory.
      *
      * @param string $filename The name of the file (e.g., 'design_system_prompt.txt')
      * @return string The file content or an empty string if reading fails.
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
         return ''; // Return empty string on failure
     }
 }
