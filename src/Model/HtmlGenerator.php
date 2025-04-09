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
     * @param string $technicalDesign
     * @param array $contextData ['store_context' => string, 'data_source_context' => string] (data_source_context might be empty)
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive (Passed for context, but doesn't select prompt)
     * @return string Generated HTML
     * @throws LocalizedException
     */
    public function generateHtmlFromDesign(
        string  $technicalDesign,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive // Keep for potential context within prompt
    ): string
    {
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

        // Removed base prompt / content goal logic
        $htmlMessages[] = ['role' => 'user', 'content' => "Technical Design Plan:\n" . $technicalDesign];
        if ($tailwindConfig) {
            $htmlMessages[] = ['role' => 'user', 'content' => "Tailwind Configuration:\n```javascript\n" . $tailwindConfig . "\n```"];
        }
        // Add interactive flag as context if needed
        if ($generateInteractive) {
             $htmlMessages[] = ['role' => 'user', 'content' => "Note: Generate interactive elements using Magento GraphQL where appropriate based on the design plan."];
        }

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($htmlMessages, $referenceImageUrl, 'HTML Generation'); // Pass simple context for logging if needed

        // Call API - Pass 'rendering' as modelKind
        $generatedHtml = $this->apiClient->getCompletion(
            $htmlMessages,
            'rendering', // Specify model kind
            [], // No tools expected at this stage
            $storeId
        );

        return $generatedHtml;
    }

    /**
     * Improve existing HTML content based on user instructions.
     *
     * @param string $renderingModel
     * @param string $customPrompt User's improvement request.
     * @param string|null $currentContent The existing HTML.
     * @param array $contextData ['store_context' => string, 'data_source_context' => string] (data_source_context might be empty)
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive (Passed for context)
     * @return string Improved HTML
     * @throws LocalizedException
     */
    public function improveHtml(
        string  $customPrompt,
        ?string $currentContent,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive // Keep for potential context within prompt
    ): string
    {
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

        // Removed base prompt / content goal logic
        $improveMessages[] = ['role' => 'user', 'content' => "User's Improvement Request:\n" . $customPrompt];
        // Add interactive flag as context if needed
        if ($generateInteractive) {
             $improveMessages[] = ['role' => 'user', 'content' => "Note: Ensure any interactive elements use Magento GraphQL where appropriate."];
        }

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($improveMessages, $referenceImageUrl, 'Improve HTML'); // Pass simple context for logging if needed

        // Call API - Pass 'rendering' as modelKind
        $generatedHtml = $this->apiClient->getCompletion(
            $improveMessages,
            'rendering', // Specify model kind
            [], // No tools expected at this stage
            $storeId
        );

        return $generatedHtml;
    }

    /**
     * Retry HTML generation using an existing design plan (Retry Stage 2).
     *
     * @param string $renderingModel
     * @param string $designPlan The existing technical design.
     * @param string $customPrompt Additional instructions for this attempt.
     * @param array $contextData ['store_context' => string, 'data_source_context' => string] (data_source_context might be empty)
     * @param int $storeId
     * @param string|null $referenceImageUrl
     * @param bool $generateInteractive (Passed for context)
     * @return string Generated HTML
     * @throws LocalizedException
     */
    public function retryHtmlFromDesign(
        string  $designPlan,
        string  $customPrompt,
        array   $contextData,
        int     $storeId,
        ?string $referenceImageUrl,
        bool    $generateInteractive // Keep for potential context within prompt
    ): string
    {
        if (empty($customPrompt)) {
            $this->logger->info('No specific improvement prompt provided for retry, generating based on design.'); // Keep this info log
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

        // Removed base prompt / content goal logic
        if (!empty($customPrompt)) {
            $retryHtmlMessages[] = ['role' => 'user', 'content' => "Additional User Instructions for this attempt:\n" . $customPrompt];
        }
        // Add interactive flag as context if needed
        if ($generateInteractive) {
             $retryHtmlMessages[] = ['role' => 'user', 'content' => "Note: Generate interactive elements using Magento GraphQL where appropriate based on the design plan."];
        }

        // Use PromptService to add reference image
        $this->promptService->addReferenceImageToMessages($retryHtmlMessages, $referenceImageUrl, 'Retry HTML'); // Pass simple context for logging if needed

        // Call API - Pass 'rendering' as modelKind
        $generatedHtml = $this->apiClient->getCompletion(
            $retryHtmlMessages,
            'rendering', // Specify model kind
            [], // No tools expected at this stage
            $storeId
        );

        return $generatedHtml;
    }

    // Removed getTailwindConfig method
    // Removed getPromptFromFile method
}
