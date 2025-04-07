<?php
/**
 * NeutromeLabs AiLand AI Design Generator
 *
 * Generates the technical design plan for content.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model;

use Magento\Framework\Exception\LocalizedException;
use NeutromeLabs\AiLand\Model\Service\PromptService;
use Psr\Log\LoggerInterface;

// Removed: ScopeConfigInterface, ScopeInterface, FileSystemException, ModuleDirReader, FileDriver

// Ensure ApiClient is used

// Added

/**
 * Service class responsible for generating the technical design using AI.
 */
class DesignGenerator
{
    // Config paths for base prompts
    const XML_PATH_PRODUCT_PROMPT = 'ailand/openrouter/product_base_prompt';
    const XML_PATH_CATEGORY_PROMPT = 'ailand/openrouter/category_base_prompt';
    const MODULE_NAME = 'NeutromeLabs_AiLand'; // Module name for directory reading

    /**
     * @var ApiClient
     */
    private $apiClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PromptService
     */
    private $promptService;

    // Removed: moduleDirReader, fileDriver, scopeConfig properties

    /**
     * Constructor
     *
     * @param ApiClient $apiClient
     * @param LoggerInterface $logger
     * @param PromptService $promptService // Added
     */
    public function __construct(
        ApiClient       $apiClient,
        LoggerInterface $logger,
        PromptService   $promptService // Added
    )
    {
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->promptService = $promptService; // Added
    }

    /**
     * Generate the technical design plan.
     *
     * @param string $apiKey
     * @param string $thinkingModel
     * @param string $customPrompt
     * @param array $contextData ['store_context' => string, 'data_source_context' => string] (data_source_context might be empty)
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive
     * @return string The generated technical design.
     * @throws LocalizedException
     */
    public function generateDesign(
        string  $apiKey,
        string  $thinkingModel,
        string  $customPrompt,
        array   $contextData,
        // string  $generationGoal, // Removed parameter
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive
    ): string
    {
        $this->logger->info('Starting AI Generation Stage 1: Technical Design', ['store_id' => $storeId]);
        $stageIdentifier = 'Stage 1 (Design)'; // Define for logging in helpers

        // Fetch the generic system prompt for design stage
        $designSystemPrompt = $this->promptService->getPromptFromFile('design_system_prompt.txt');
        if (!$designSystemPrompt) {
            throw new LocalizedException(__('Could not load the generic design system prompt.'));
        }

        // Prepare messages for the API call
        $designMessages = [
            ['role' => 'system', 'content' => $designSystemPrompt]
        ];
        // Add store context if available
        if (!empty($contextData['store_context'])) {
            $designMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
        }
        // Add the main user prompt (which now contains all instructions)
        $designMessages[] = ['role' => 'user', 'content' => $customPrompt];

        // Add reference image if provided
        $this->promptService->addReferenceImageToMessages($designMessages, $referenceImageUrl, $stageIdentifier);

        // Call the API
        $technicalDesign = $this->apiClient->callOpenRouterApi($apiKey, $thinkingModel, $designMessages, $stageIdentifier);
        $this->logger->info('Completed AI Generation Stage 1.');

        return $technicalDesign;
    }

    // Removed buildDesignUserPrompt method as it's no longer needed
    // Removed getBasePrompt method
    // Removed getPromptFromFile method
}
