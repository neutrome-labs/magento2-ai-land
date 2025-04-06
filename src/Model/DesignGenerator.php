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
// Removed: ScopeConfigInterface, ScopeInterface, FileSystemException, ModuleDirReader, FileDriver
use Psr\Log\LoggerInterface;
use NeutromeLabs\AiLand\Model\ApiClient; // Ensure ApiClient is used
use NeutromeLabs\AiLand\Model\Service\PromptService; // Added

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
        ApiClient $apiClient,
        LoggerInterface $logger,
        PromptService $promptService // Added
    ) {
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
     * @param array $contextData ['store_context' => string, 'data_source_context' => string]
     * @param string|null $dataSourceType ('product', 'category', or null)
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive
     * @return string The generated technical design.
     * @throws LocalizedException
     */
    public function generateDesign(
        string $apiKey,
        string $thinkingModel,
        string $customPrompt,
        array $contextData,
        ?string $dataSourceType,
        int $storeId,
        ?string $referenceImageUrl,
        bool $generateInteractive
    ): string {
        $this->logger->info('Starting AI Generation Stage 1: Technical Design', ['store_id' => $storeId]);
        $stageIdentifier = 'Stage 1 (Design)'; // Define for logging in helpers

        // Use PromptService to get prompts
        $designUserPrompt = $this->buildDesignUserPrompt($customPrompt, $contextData, $dataSourceType, $storeId, $generateInteractive); // Keep internal build method for now
        $designSystemPrompt = $this->promptService->getPromptFromFile('design_system_prompt.txt'); // Use PromptService
        if (!$designSystemPrompt) {
            throw new LocalizedException(__('Could not load design system prompt via PromptService.'));
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

        // Add the final user prompt (goal + custom instructions)
        $designMessages[] = ['role' => 'user', 'content' => $designUserPrompt];
        $this->promptService->addReferenceImageToMessages($designMessages, $referenceImageUrl, $stageIdentifier);

        $technicalDesign = $this->apiClient->callOpenRouterApi($apiKey, $thinkingModel, $designMessages, 'Stage 1 (Design)');
        $this->logger->info('Completed AI Generation Stage 1.');

        return $technicalDesign;
    }

    /**
     * Build the user instruction part of the prompt for the design stage.
     *
     * @param string $customPrompt
     * @param array $contextData
     * @param string|null $dataSourceType
     * @param int $storeId
     * @return string
     */
    private function buildDesignUserPrompt(string $customPrompt, array $contextData, ?string $dataSourceType, int $storeId, bool $generateInteractive = false): string
    {
        $basePromptType = !empty($contextData['data_source_context']) ? $dataSourceType : null;
        // Use PromptService to get base prompt
        $contentGoal = $this->promptService->getBasePrompt($basePromptType, $storeId, $generateInteractive); // Use PromptService

        $userInstructionPrompt = "Content Goal: " . $contentGoal . "\n";

        if (!empty($customPrompt)) {
            $userInstructionPrompt .= "User's Custom Instructions: " . $customPrompt . "\n";
        }

        return trim($userInstructionPrompt);
    }

    // Removed getBasePrompt method
    // Removed getPromptFromFile method
}
