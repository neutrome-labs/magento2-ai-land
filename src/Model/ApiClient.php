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
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * Client class for interacting with the OpenRouter API.
 */
class ApiClient
{
    const API_BASE_URL = 'https://openrouter.ai/api/v1';
    const CHAT_COMPLETIONS_ENDPOINT = '/chat/completions';
    const AUTH_KEY_ENDPOINT = '/key';
    const MODELS_ENDPOINT = '/models';

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
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        JsonSerializer       $jsonSerializer,
        LoggerInterface      $logger
    ) {
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

        $url = self::API_BASE_URL . self::CHAT_COMPLETIONS_ENDPOINT;
        $headers = [
            'Content-Type: application/json',
        ];

        try {
            $this->logger->debug("OpenRouter Request [$stageIdentifier] to $url");
            $response = $this->makeRequest($apiKey, $url, 'POST', $headers, $this->jsonSerializer->serialize($payload), 300);
            $this->logger->debug("OpenRouter Response [$stageIdentifier] from $url");

            $responseData = $this->jsonSerializer->unserialize($response);

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

    /**
      * Fetches the account status (limit, usage, remaining balance, free tier, rate limits) for the given API key.
      * Returns null if the API key is invalid or an error occurs.
      *
      * @param string $apiKey
      * @return array|null ['limit_remaining' => float|null, 'limit' => float|null, 'usage' => float|null, 'is_free_tier' => bool|null, 'rate_limit' => array|null] or null on error/invalid key
      */
     public function getAccountStatus(string $apiKey): ?array
    {
        $url = self::API_BASE_URL . self::AUTH_KEY_ENDPOINT;
        $logIdentifier = 'AccountStatus';

        try {
            $this->logger->debug("OpenRouter Request [$logIdentifier] to $url");
            $response = $this->makeRequest($apiKey, $url, 'GET');
            $this->logger->debug("OpenRouter Response [$logIdentifier] from $url");

            $responseData = $this->jsonSerializer->unserialize($response);

            // Check for specific error indicating invalid key (adjust based on actual API response)
            if (isset($responseData['error'])) {
                 $this->logger->warning("OpenRouter API Error [$logIdentifier]: " . ($responseData['error']['message'] ?? 'Unknown error'), ['response' => $responseData]);
                 return null; // Treat API errors as potentially invalid key or temporary issue
            }

             // Assuming the response data structure contains 'data' with account info
             if (isset($responseData['data']) && is_array($responseData['data'])) {
                  // Extract relevant fields based on the provided OpenRouter /key response structure
                  return [
                      'limit_remaining' => $responseData['data']['limit_remaining'] ?? null,
                      'limit' => $responseData['data']['limit'] ?? null,
                      'usage' => $responseData['data']['usage'] ?? null,
                      'is_free_tier' => $responseData['data']['is_free_tier'] ?? null,
                      'rate_limit' => $responseData['data']['rate_limit'] ?? null, // e.g., ['requests' => 60, 'interval' => '1m']
                  ];
            } else {
                 $this->logger->error("Could not extract account status data from OpenRouter response [$logIdentifier].", ['response' => $responseData]);
                 return null;
            }

        } catch (LocalizedException $e) {
            // Log specific localized exceptions (like 401 Unauthorized) differently if needed
            if (strpos($e->getMessage(), 'Status 401') !== false) {
                 $this->logger->info("OpenRouter API key seems invalid [$logIdentifier].");
            } else {
                 $this->logger->error("OpenRouter API Error [$logIdentifier]: " . $e->getMessage());
            }
            return null;
        } catch (Exception $e) {
            $this->logger->critical("Error fetching OpenRouter Account Status [$logIdentifier]: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Fetches details for a specific model from OpenRouter.
     * Returns null if the model is not found or an error occurs.
     *
     * @param string $modelId e.g., "deepseek/deepseek-r1:free"
     * @return array|null Model details including pricing, or null on error/not found
     */
    public function getModelDetails(string $modelId): ?array
    {
        $url = self::API_BASE_URL . self::MODELS_ENDPOINT;
        $logIdentifier = 'ModelDetails';

        try {
            $this->logger->debug("OpenRouter Request [$logIdentifier] to $url");
            // No API key needed for public model list usually, but check OpenRouter docs if required
            $response = $this->makeRequest('', $url, 'GET');
            $this->logger->debug("OpenRouter Response [$logIdentifier] from $url");

            $responseData = $this->jsonSerializer->unserialize($response);

            if (isset($responseData['data']) && is_array($responseData['data'])) {
                foreach ($responseData['data'] as $model) {
                    if (isset($model['id']) && $model['id'] === $modelId) {
                        $this->logger->debug("Found details for model [$logIdentifier]: $modelId");
                        // Extract relevant pricing info - adjust keys based on actual OpenRouter response
                        return [
                            'id' => $model['id'],
                            'name' => $model['name'] ?? $modelId,
                            'pricing' => [
                                'prompt' => $model['pricing']['prompt'] ?? null, // Price per prompt token (e.g., in USD per million tokens)
                                'completion' => $model['pricing']['completion'] ?? null, // Price per completion token
                            ],
                            // Add other relevant details if needed
                        ];
                    }
                }
                $this->logger->warning("Model not found in OpenRouter response [$logIdentifier]: $modelId");
                return null; // Model ID not found in the list
            } else {
                $this->logger->error("Could not extract models data from OpenRouter response [$logIdentifier].", ['response' => $responseData]);
                return null;
            }

        } catch (LocalizedException $e) {
            $this->logger->error("OpenRouter API Error [$logIdentifier]: " . $e->getMessage());
            return null;
        } catch (Exception $e) {
            $this->logger->critical("Error fetching OpenRouter Model Details [$logIdentifier]: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Helper function to make cURL requests.
     *
     * @param string $url
     * @param string $method 'GET' or 'POST'
     * @param array $headers
     * @param string|null $payload
     * @param int $timeout
     * @return string Response body
     * @throws LocalizedException on cURL or HTTP errors
     */
    private function makeRequest(
        string $apiKey,
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $payload = null,
        int $timeout = 60 // Reduced default timeout for status/info calls
    ): string {
        $headers[] = 'Authorization: Bearer ' . $apiKey;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        } elseif (strtoupper($method) !== 'GET') {
             // Add support for other methods if needed (PUT, DELETE, etc.)
             curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
             if ($payload !== null) {
                 curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
             }
        }

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log only first 1000 chars of response body for brevity
        $this->logger->debug("API Response Status: $statusCode, Body: " . substr(trim((string)$responseBody), 0, 1000));


        if ($curlError) {
            throw new LocalizedException(__("cURL Error calling API [%1]: %2", $url, $curlError));
        }

        // Consider 4xx/5xx as errors, but allow specific handling (like 401 in getAccountStatus)
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorDetails = $responseBody;
            try {
                $decodedError = $this->jsonSerializer->unserialize((string)$responseBody);
                if (isset($decodedError['error']['message'])) {
                    $errorDetails = $decodedError['error']['message'];
                }
            } catch (Exception $e) { /* Ignore unserialize errors */
            }
            throw new LocalizedException(__("Error communicating with API [%1]: Status %2 - %3", $url, $statusCode, $errorDetails));
        }

        if ($responseBody === false || $responseBody === null) {
             throw new LocalizedException(__("Empty response received from API [%1]", $url));
        }


        return (string)$responseBody;
    }
}
