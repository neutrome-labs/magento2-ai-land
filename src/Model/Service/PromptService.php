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
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\HTTP\Client\Curl; // Added HTTP Client

/**
 * Service class for prompt-related operations.
 */
class PromptService
{
    // Removed config path constants for prompts
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
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Curl // Added HTTP Client property
     */
    private $curlClient;

    /**
     * Constructor
     *
     * @param ModuleDirReader $moduleDirReader
     * @param FileDriver $fileDriver
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param Curl $curlClient // Added HTTP Client injection
     */
    public function __construct(
        ModuleDirReader      $moduleDirReader,
        FileDriver           $fileDriver,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger,
        StoreManagerInterface $storeManager,
        Curl $curlClient // Added
    )
    {
        $this->moduleDirReader = $moduleDirReader;
        $this->fileDriver = $fileDriver;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->curlClient = $curlClient; // Added
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
                \Magento\Framework\Module\Dir::MODULE_ETC_DIR,
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
     * Enriches context data with the styling reference URL, using store base URL as default.
     *
     * @param array $contextData The context data array (passed by reference).
     * @param string|null $stylingReferenceUrl The user-provided URL.
     * @param int $storeId The store ID for fetching the default URL.
     * @return void
     */
    public function enrichContextWithStylingHtml(array &$contextData, ?string $stylingReferenceUrl, int $storeId): void
    {
        $finalUrl = trim((string)$stylingReferenceUrl);
        $fetchedHtml = '';
        $maxHtmlLength = 15000; // Limit fetched HTML size

        // Determine the URL to fetch
        if (empty($finalUrl)) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $finalUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_WEB);
                $this->logger->info('Using default store base URL for styling reference fetch.', ['store_id' => $storeId, 'url' => $finalUrl]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->error('Could not get store base URL for default styling reference fetch.', ['store_id' => $storeId, 'exception' => $e]);
                $finalUrl = ''; // Ensure it's empty if store fetch fails
            }
        }

        // Fetch HTML if we have a valid URL
        if (!empty($finalUrl) && filter_var($finalUrl, FILTER_VALIDATE_URL)) {
            try {
                $this->curlClient->setOption(CURLOPT_TIMEOUT, 10); // Set timeout
                $this->curlClient->setOption(CURLOPT_FOLLOWLOCATION, true); // Follow redirects
                $this->curlClient->setOption(CURLOPT_USERAGENT, 'Magento AiLand Module Fetcher'); // Set user agent
                $this->curlClient->get($finalUrl);
                $status = $this->curlClient->getStatus();

                if ($status === 200) {
                    $fetchedHtml = $this->curlClient->getBody();
                    // Basic HTML cleanup (remove scripts, styles, head)
                    $fetchedHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $fetchedHtml);
                    $fetchedHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $fetchedHtml);
                    $fetchedHtml = preg_replace('/<head\b[^>]*>(.*?)<\/head>/is', '', $fetchedHtml);
                    $fetchedHtml = strip_tags($fetchedHtml, '<div><span><p><a><img><h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><section><article><header><footer><nav><main><aside><button><input><form><label>'); // Keep basic structural/content tags
                    $fetchedHtml = trim($fetchedHtml);

                    if (mb_strlen($fetchedHtml) > $maxHtmlLength) {
                        $fetchedHtml = mb_substr($fetchedHtml, 0, $maxHtmlLength) . '... [truncated]';
                        $this->logger->info('Fetched styling reference HTML truncated.', ['url' => $finalUrl, 'length' => $maxHtmlLength]);
                    }
                    $this->logger->info('Successfully fetched styling reference HTML.', ['url' => $finalUrl, 'length' => mb_strlen($fetchedHtml)]);
                } else {
                    $this->logger->warning('Failed to fetch styling reference URL. Status: ' . $status, ['url' => $finalUrl]);
                    $fetchedHtml = '(Error fetching styling reference URL: Status ' . $status . ')';
                }
            } catch (\Exception $e) {
                $this->logger->error('Exception during styling reference URL fetch.', ['url' => $finalUrl, 'exception' => $e->getMessage()]);
                $fetchedHtml = '(Exception fetching styling reference URL)';
            }
        } elseif (!empty($finalUrl)) {
             $this->logger->warning('Invalid URL provided for styling reference.', ['url' => $finalUrl]);
             $fetchedHtml = '(Invalid styling reference URL provided)';
        }

        // Add the fetched HTML (or error message) to the context data
        if (!empty($fetchedHtml)) {
            $contextData['styling_reference_html'] = $fetchedHtml;
        }
        // We no longer add the URL itself to the store_context
    }

    // Removed getBasePrompt method

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
