<?php
/**
 * NeutromeLabs AiLand AI API Client
 *
 * Handles communication with the OpenRouter API.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * Client class for interacting with the OpenRouter API.
 */
class ApiClient
{
    const OPENROUTER_API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

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
     * Constructor
     *
     * @param Curl $httpClient
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl            $httpClient,
        JsonSerializer  $jsonSerializer,
        LoggerInterface $logger
    )
    {
        $this->httpClient = $httpClient;
        $this->jsonSerializer = $jsonSerializer;
        $this->logger = $logger;
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
    public function callOpenRouterApi(string $apiKey, string $model, array $messages, string $stageIdentifier): string
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout

            $responseBody = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->logger->debug("OpenRouter Response [$stageIdentifier] Status: " . $statusCode);
            // Log only first 1000 chars of response body for brevity
            $this->logger->debug("OpenRouter Response [$stageIdentifier] Body: " . substr(trim($responseBody), 0, 1000));

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
                } catch (Exception $e) { /* Ignore unserialize errors */
                }
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
        } catch (Exception $e) {
            $this->logger->critical("Error calling OpenRouter API [$stageIdentifier]: " . $e->getMessage(), ['exception' => $e]);
            throw new LocalizedException(__("An unexpected error occurred while calling the AI service [$stageIdentifier]: %1", $e->getMessage()));
        }
    }
}
