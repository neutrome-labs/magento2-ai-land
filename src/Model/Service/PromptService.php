<?php
/**
 * NeutromeLabs AiLand Prompt Service
 *
 * Provides helper methods for building and retrieving prompts.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service class for prompt-related operations.
 */
class PromptService
{
    // Config paths for base prompts
    const XML_PATH_PRODUCT_PROMPT = 'ailand/openrouter/product_base_prompt';
    const XML_PATH_CATEGORY_PROMPT = 'ailand/openrouter/category_base_prompt';
    const XML_PATH_GENERIC_PROMPT = 'ailand/openrouter/generic_base_prompt';
    const XML_PATH_PRODUCT_INTERACTIVE_PROMPT = 'ailand/openrouter/product_interactive_prompt';
    const XML_PATH_CATEGORY_INTERACTIVE_PROMPT = 'ailand/openrouter/category_interactive_prompt';
    const XML_PATH_GENERIC_INTERACTIVE_PROMPT = 'ailand/openrouter/generic_interactive_prompt';
    const MODULE_NAME = 'NeutromeLabs_AiLand'; // Module name for directory reading

    /**
     * @var ModuleDirReader
     */
    private $moduleDirReader;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param ModuleDirReader $moduleDirReader
     * @param FileDriver $fileDriver
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        ModuleDirReader      $moduleDirReader,
        FileDriver           $fileDriver,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger
    )
    {
        $this->moduleDirReader = $moduleDirReader;
        $this->fileDriver = $fileDriver;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Reads a prompt string from a file within the module's etc/prompts directory.
     *
     * @param string $filename
     * @return string
     */
    public function getPromptFromFile(string $filename): string
    {
        try {
            $promptDir = $this->moduleDirReader->getModuleDir(
                    Dir::MODULE_ETC_DIR,
                    self::MODULE_NAME
                ) . '/prompts';
            $filePath = $promptDir . '/' . $filename;

            if ($this->fileDriver->isExists($filePath) && $this->fileDriver->isReadable($filePath)) {
                return trim($this->fileDriver->fileGetContents($filePath));
            } else {
                $this->logger->warning('Prompt file not found or not readable: ' . $filePath);
            }
        } catch (FileSystemException|LocalizedException $e) {
            $this->logger->error('Error reading prompt file: ' . $filename . ' - ' . $e->getMessage());
        }
        return '';
    }

    /**
     * Get the CONTENT-focused base prompt instruction based on data source type.
     *
     * @param string|null $dataSourceType
     * @param int $storeId
     * @return string
     */
    public function getBasePrompt(?string $dataSourceType, int $storeId, bool $generateInteractive = false): string
    {
        $configPath = null;
        $defaultPrompt = 'Generate content based on the provided context.';

        switch ($dataSourceType) {
            case 'product':
                $configPath = $generateInteractive ? self::XML_PATH_PRODUCT_INTERACTIVE_PROMPT : self::XML_PATH_PRODUCT_PROMPT;
                break;
            case 'category':
                $configPath = $generateInteractive ? self::XML_PATH_CATEGORY_INTERACTIVE_PROMPT : self::XML_PATH_CATEGORY_PROMPT;
                break;
            default:
                $configPath = $generateInteractive ? self::XML_PATH_GENERIC_INTERACTIVE_PROMPT : self::XML_PATH_GENERIC_PROMPT;
                break;
        }

        $prompt = $configPath ? $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $storeId) : null;

        if (empty($prompt)) {
            $this->logger->error('Missing required prompt configuration for: ' . ($dataSourceType ?? 'generic'));
            return $defaultPrompt;
        }

        return trim($prompt);
    }

    /**
     * Adds a reference image URL to the API message array if provided.
     * Modifies the last user message or adds a new one.
     *
     * @param array $messages The current message array (passed by reference).
     * @param string|null $referenceImageUrl The URL of the reference image.
     * @param string $stageIdentifier For logging purposes.
     * @return void
     */
    public function addReferenceImageToMessages(array &$messages, ?string $referenceImageUrl, string $stageIdentifier): void
    {
        if ($referenceImageUrl && !empty(trim($referenceImageUrl))) {
            $trimmedUrl = trim($referenceImageUrl);
            $lastMessageIndex = count($messages) - 1;

            // Try to append to the last user message for better context
            if ($lastMessageIndex >= 0 && $messages[$lastMessageIndex]['role'] === 'user') {
                // Ensure the existing content is treated as text
                $originalContent = $messages[$lastMessageIndex]['content'];
                if (is_string($originalContent)) {
                    $textContent = $originalContent;
                    $newContent = [['type' => 'text', 'text' => $textContent]];
                } elseif (is_array($originalContent) && isset($originalContent[0]['type']) && $originalContent[0]['type'] === 'text') {
                    // Already in multimodal format, just append image
                    $newContent = $originalContent;
                } else {
                    // Unexpected format, log warning and create basic text part
                    $this->logger->warning('Unexpected content format in last user message for image addition.', ['stage' => $stageIdentifier]);
                    $newContent = [['type' => 'text', 'text' => '(Previous content)']];
                }

                // Add the image part
                $newContent[] = ['type' => 'image_url', 'image_url' => ['url' => $trimmedUrl]];
                $messages[$lastMessageIndex]['content'] = $newContent;

                $this->logger->info('Added reference image to last user message.', ['stage' => $stageIdentifier, 'url' => $trimmedUrl]);

            } else {
                // Fallback: Add as a new user message if the last wasn't 'user' or array is empty
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Reference Image:'],
                        ['type' => 'image_url', 'image_url' => ['url' => $trimmedUrl]]
                    ]
                ];
                $this->logger->info('Added reference image as a separate message.', ['stage' => $stageIdentifier, 'url' => $trimmedUrl]);
            }
        }
    }
}
