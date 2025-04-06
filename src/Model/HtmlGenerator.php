<?php
/**
 * NeutromeLabs AiLand AI HTML Generator
 *
 * Generates HTML content based on a design or improves existing HTML.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model;

// Corrected Use Statements based on previous successful step
use Magento\Framework\Exception\LocalizedException;
use NeutromeLabs\AiLand\Model\Service\PromptService;
use NeutromeLabs\AiLand\Model\Service\ThemeService;
use Psr\Log\LoggerInterface;

/**
 * Service class responsible for generating/improving HTML content using AI.
 */
class HtmlGenerator
{
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

    /**
     * @var ThemeService
     */
    private $themeService;

    /**
     * Constructor
     *
     * @param ApiClient $apiClient
     * @param LoggerInterface $logger
     * @param PromptService $promptService
     * @param ThemeService $themeService
     */
    public function __construct(
        ApiClient       $apiClient,
        LoggerInterface $logger,
        PromptService   $promptService,
        ThemeService    $themeService
    )
    {
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->promptService = $promptService;
        $this->themeService = $themeService;
    }

    /**
     * Generate HTML from a technical design plan (Stage 2).
     *
     * @param string $apiKey
     * @param string $renderingModel
     * @param string $technicalDesign
     * @param array $contextData ['store_context' => string, 'data_source_context' => string]
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive
     * @return string Generated HTML
     * @throws LocalizedException
     */
    public function generateHtmlFromDesign(
        string  $apiKey,
        string  $renderingModel,
        string  $technicalDesign,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive
    ): string
    {
        $this->logger->info('Starting AI Generation Stage 2: HTML Generation', ['store_id' => $storeId]);
        $stageIdentifier = 'Stage 2 (HTML)'; // Define for logging

        // Use PromptService and ThemeService
        $htmlSystemPrompt = $this->promptService->getPromptFromFile('html_system_prompt.txt'); // Use PromptService
        if (!$htmlSystemPrompt) {
            throw new LocalizedException(__('Could not load HTML system prompt via PromptService.'));
        }
        $tailwindConfig = $this->themeService->getTailwindConfig($storeId); // Use ThemeService

        $htmlMessages = [
            ['role' => 'system', 'content' => $htmlSystemPrompt]
        ];
        if (!empty($contextData['store_context'])) {
            $htmlMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
        }
        if (!empty($contextData['data_source_context'])) {
            $htmlMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $contextData['data_source_context']];
        }

        // Add base prompt content goal
        $basePromptType = !empty($contextData['data_source_context']) ? $contextData['data_source_type'] ?? null : null;
        $contentGoal = $this->promptService->getBasePrompt($basePromptType, $storeId, $generateInteractive);
        $htmlMessages[] = ['role' => 'user', 'content' => "Content Goal: " . $contentGoal];
        $htmlMessages[] = ['role' => 'user', 'content' => "Technical Design Plan:\n" . $technicalDesign];
        if ($tailwindConfig) {
            $htmlMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration:\n```javascript\n" . $tailwindConfig . "\n```"];
        }

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($htmlMessages, $referenceImageUrl, $stageIdentifier);

        // Call API
        $generatedHtml = $this->apiClient->callOpenRouterApi($apiKey, $renderingModel, $htmlMessages, $stageIdentifier);
        $this->logger->info('Completed AI Generation Stage 2.');

        return $generatedHtml;
    }

    /**
     * Improve existing HTML content based on user instructions.
     *
     * @param string $apiKey
     * @param string $renderingModel
     * @param string $customPrompt User's improvement request.
     * @param string|null $currentContent The existing HTML.
     * @param array $contextData ['store_context' => string, 'data_source_context' => string]
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive
     * @return string Improved HTML
     * @throws LocalizedException
     */
    public function improveHtml(
        string  $apiKey,
        string  $renderingModel,
        string  $customPrompt,
        ?string $currentContent,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive
    ): string
    {
        $this->logger->info('Performing standard HTML improvement.', ['store_id' => $storeId]);
        $stageIdentifier = 'Improve Stage'; // Define for logging

        if (empty($customPrompt)) {
            throw new LocalizedException(__('An improvement instruction is required in the prompt field when improving content.'));
        }
        // Use PromptService and ThemeService
        $improveSystemPrompt = $this->promptService->getPromptFromFile('improve_system_prompt.txt'); // Use PromptService
        if (!$improveSystemPrompt) {
            throw new LocalizedException(__('Could not load improve system prompt via PromptService.'));
        }
        $tailwindConfig = $this->themeService->getTailwindConfig($storeId); // Use ThemeService

        $improveMessages = [
            ['role' => 'system', 'content' => $improveSystemPrompt]
        ];
        if (!empty($contextData['store_context'])) {
            $improveMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
        }
        if (!empty($contextData['data_source_context'])) {
            $improveMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $contextData['data_source_context']];
        }
        $improveMessages[] = ['role' => 'user', 'content' => "Current HTML Block:\n" . ($currentContent ?: '(empty)')];
        if ($tailwindConfig) {
            $improveMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration (NOTE: this are not available on preview. Use for reference only):\n```javascript\n" . $tailwindConfig . "\n```"];
        }

        // Add base prompt content goal
        $basePromptType = !empty($contextData['data_source_context']) ? $contextData['data_source_type'] ?? null : null;
        $contentGoal = $this->promptService->getBasePrompt($basePromptType, $storeId, $generateInteractive);
        $improveMessages[] = ['role' => 'user', 'content' => "Content Goal: " . $contentGoal];

        $improveMessages[] = ['role' => 'user', 'content' => "User's Improvement Request:\n" . $customPrompt];

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($improveMessages, $referenceImageUrl, $stageIdentifier);

        // Call API
        $generatedHtml = $this->apiClient->callOpenRouterApi($apiKey, $renderingModel, $improveMessages, $stageIdentifier);
        $this->logger->info('Completed AI Generation: Improve HTML.');

        return $generatedHtml;
    }

    /**
     * Retry HTML generation using an existing design plan (Retry Stage 2).
     *
     * @param string $apiKey
     * @param string $renderingModel
     * @param string $designPlan The existing technical design.
     * @param string $customPrompt Additional instructions for this attempt.
     * @param array $contextData ['store_context' => string, 'data_source_context' => string]
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive
     * @return string Generated HTML
     * @throws LocalizedException
     */
    public function retryHtmlFromDesign(
        string  $apiKey,
        string  $renderingModel,
        string  $designPlan,
        string  $customPrompt,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive
    ): string
    {
        $this->logger->info('Retrying Stage 2 HTML generation using existing design plan.', ['store_id' => $storeId]);
        $stageIdentifier = 'Retry Stage 2 (HTML)'; // Define for logging

        if (empty($customPrompt)) {
            $this->logger->info('No specific improvement prompt provided for retry, generating based on design.');
        } else {
            $this->logger->info('Improvement prompt provided for retry: ' . $customPrompt);
        }
        // Use PromptService and ThemeService
        $htmlSystemPrompt = $this->promptService->getPromptFromFile('html_system_prompt.txt'); // Use PromptService
        if (!$htmlSystemPrompt) {
            throw new LocalizedException(__('Could not load HTML system prompt for retry via PromptService.'));
        }
        $tailwindConfig = $this->themeService->getTailwindConfig($storeId); // Use ThemeService

        $retryHtmlMessages = [
            ['role' => 'system', 'content' => $htmlSystemPrompt]
        ];
        if (!empty($contextData['store_context'])) {
            $retryHtmlMessages[] = ['role' => 'user', 'content' => "Store Context:\n" . $contextData['store_context']];
        }
        if (!empty($contextData['data_source_context'])) {
            $retryHtmlMessages[] = ['role' => 'user', 'content' => "Data Source Context:\n" . $contextData['data_source_context']];
        }
        $retryHtmlMessages[] = ['role' => 'user', 'content' => "Technical Design Plan:\n" . $designPlan];
        if ($tailwindConfig) {
            $retryHtmlMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration (NOTE: this are not available on preview. Use for reference only):\n```javascript\n" . $tailwindConfig . "\n```"];
        }

        // Add base prompt content goal
        $basePromptType = !empty($contextData['data_source_context']) ? $contextData['data_source_type'] ?? null : null;
        $contentGoal = $this->promptService->getBasePrompt($basePromptType, $storeId, $generateInteractive);
        $retryHtmlMessages[] = ['role' => 'user', 'content' => "Content Goal: " . $contentGoal];

        if (!empty($customPrompt)) {
            $retryHtmlMessages[] = ['role' => 'user', 'content' => "Additional User Instructions for this attempt:\n" . $customPrompt];
        }

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($retryHtmlMessages, $referenceImageUrl, $stageIdentifier);

        // Call API
        $generatedHtml = $this->apiClient->callOpenRouterApi($apiKey, $renderingModel, $retryHtmlMessages, $stageIdentifier);
        $this->logger->info('Completed Retry Stage 2.');

        return $generatedHtml;
    }

    // Removed getTailwindConfig method
    // Removed getPromptFromFile method
}
