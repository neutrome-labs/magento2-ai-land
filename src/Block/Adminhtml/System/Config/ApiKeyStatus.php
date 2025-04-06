<?php
/**
 * NeutromeLabs AiLand Adminhtml System Config Field for API Status Display
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Store\Model\ScopeInterface;
use NeutromeLabs\AiLand\Model\ApiClient;
use Psr\Log\LoggerInterface; // Added for logging within the block
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Renders the API status and model pricing information in system configuration.
 */
class ApiKeyStatus extends Field
{
    const CONFIG_PATH_API_KEY = 'ailand/openrouter/api_key';
    const CONFIG_PATH_THINKING_MODEL = 'ailand/openrouter/thinking_model';
    const CONFIG_PATH_RENDERING_MODEL = 'ailand/openrouter/rendering_model';

    /**
     * @var ApiClient
     */
    private $apiClient;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var LoggerInterface
     */
    private $logger; // Added logger

    /**
     * Constructor
     *
     * @param Context $context
     * @param ApiClient $apiClient
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger // Added logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        ApiClient $apiClient,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger, // Added logger
        array $data = []
    ) {
        $this->apiClient = $apiClient;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->escaper = $context->getEscaper();
        $this->logger = $logger; // Added logger
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label and render the status block.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
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
            self::CONFIG_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $key ? $this->encryptor->decrypt($key) : null;
    }

    /**
     * Get the HTML for the element.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $apiKey = $this->getApiKey((int)$this->_storeManager->getStore()->getId());
        $openRouterKeysUrl = 'https://openrouter.ai/keys';
        $openRouterCreditsUrl = 'https://openrouter.ai/credits';

        if (empty($apiKey)) {
            return sprintf(
                '<div>%s <a href="%s" target="_blank">%s</a></div>',
                $this->escaper->escapeHtml(__('Please obtain an API key from OpenRouter to use the service.')),
                $this->escaper->escapeUrl($openRouterKeysUrl),
                $this->escaper->escapeHtml(__('Get API Key'))
            );
        }

        // API Key exists, try to fetch status and pricing
        $html = '<div>';
        $statusData = $this->apiClient->getAccountStatus($apiKey);
        $thinkingModelId = $this->scopeConfig->getValue(self::CONFIG_PATH_THINKING_MODEL);
        $renderingModelId = $this->scopeConfig->getValue(self::CONFIG_PATH_RENDERING_MODEL);

        // Display Balance/Status, Free Tier, and Rate Limits
        if ($statusData !== null) {
            // Balance
            $balanceValue = $statusData['limit_remaining'] ?? null;
            $balance = (is_numeric($balanceValue))
                ? number_format((float)$balanceValue, 4)
                : '0.0000'; // Default to 0.0000 if null or not numeric
            $html .= sprintf('<div><strong>%s:</strong> $%s', $this->escaper->escapeHtml(__('Current Balance')), $this->escaper->escapeHtml($balance));

            // Free Tier
            if (isset($statusData['is_free_tier']) && $statusData['is_free_tier'] === true) {
                $html .= sprintf(' <span style="color: green;">(%s)</span>', $this->escaper->escapeHtml(__('Free Tier')));
            }
            $html .= '</div>'; // Close balance div

            // Rate Limit
            if (isset($statusData['rate_limit']) && is_array($statusData['rate_limit'])) {
                $requests = $statusData['rate_limit']['requests'] ?? 'N/A';
                $interval = $statusData['rate_limit']['interval'] ?? 'N/A';
                $html .= sprintf(
                    '<div><strong>%s:</strong> %s / %s</div>',
                    $this->escaper->escapeHtml(__('Rate Limit')),
                    $this->escaper->escapeHtml((string)$requests),
                    $this->escaper->escapeHtml((string)$interval)
                );
            }

        } elseif ($statusData === null) {
             // This could mean invalid key or API error (API call failed or returned null)
             $html .= sprintf('<div style="color: orange;"><strong>%s</strong> %s</div>',
                 $this->escaper->escapeHtml(__('Warning:')),
                 $this->escaper->escapeHtml(__('Could not verify API key or fetch balance. Please check the key and API status.'))
             );
        } else {
            $html .= sprintf('<div><strong>%s:</strong> %s</div>', $this->escaper->escapeHtml(__('Current Balance')), $this->escaper->escapeHtml(__('Could not fetch balance.')));
        }

        // Display Model Pricing
        $html .= $this->renderModelPricing($thinkingModelId, __('Thinking Model'));
        $html .= $this->renderModelPricing($renderingModelId, __('Rendering Model'));

        // Add Top-up Link
        $html .= sprintf(
            '<div style="margin-top: 10px;">%s <a href="%s" target="_blank">%s</a></div>',
            $this->escaper->escapeHtml(__('Manage your API key and balance on the OpenRouter website.')),
            $this->escaper->escapeUrl($openRouterCreditsUrl), // Use credits URL for top-up/management
            $this->escaper->escapeHtml(__('Manage Account / Top Up'))
        );

        $html .= '</div>';
        return $html;
    }

    /**
     * Helper to render pricing details for a single model.
     *
     * @param string|null $modelId
     * @param \Magento\Framework\Phrase $label
     * @return string
     */
    private function renderModelPricing(?string $modelId, \Magento\Framework\Phrase $label): string
    {
        if (empty($modelId)) {
            return sprintf('<div><strong>%s:</strong> %s</div>', $this->escaper->escapeHtml($label), $this->escaper->escapeHtml(__('No model configured.')));
        }

        $modelDetails = $this->apiClient->getModelDetails($modelId);
        $pricingHtml = '';

        if ($modelDetails && isset($modelDetails['pricing'])) {
            $promptPrice = $modelDetails['pricing']['prompt'] ?? 'N/A';
            $completionPrice = $modelDetails['pricing']['completion'] ?? 'N/A';

            // Format prices if they are numeric (assuming price per million tokens)
            $promptPriceFormatted = is_numeric($promptPrice) ? '$' . number_format((float)$promptPrice, 2) . '/Mtk' : $promptPrice;
            $completionPriceFormatted = is_numeric($completionPrice) ? '$' . number_format((float)$completionPrice, 2) . '/Mtk' : $completionPrice;

            $pricingHtml = sprintf(
                '%s (Input) / %s (Output)',
                $this->escaper->escapeHtml($promptPriceFormatted),
                $this->escaper->escapeHtml($completionPriceFormatted)
            );
        } else {
            $pricingHtml = $this->escaper->escapeHtml(__('Could not fetch pricing.'));
            $this->logger->warning('Could not fetch pricing details for model: ' . $modelId); // Log failure
        }

        return sprintf(
            '<div><strong>%s (%s):</strong><br/>%s</div>',
            $this->escaper->escapeHtml($label),
            $this->escaper->escapeHtml($modelId),
            $pricingHtml // Already escaped within the logic
        );
    }
}
