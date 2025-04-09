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
use Magento\Framework\App\Config\ScopeConfigInterface; // Added
use Magento\Framework\Encryption\EncryptorInterface; // Added
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use NeutromeLabs\AiLand\Model\AiToolPool; // Added
use Psr\Log\LoggerInterface;
use Magento\Store\Model\ScopeInterface; // Added

/**
 * Client class for interacting with the OpenRouter API.
 * Handles tool definition passing and execution loop.
 */
class ApiClient
{
    // Config paths (moved here for API key fetching)
    const XML_PATH_API_KEY = 'ailand/openrouter/api_key';
    const XML_PATH_THINKING_MODEL = 'ailand/openrouter/thinking_model';
    const XML_PATH_RENDERING_MODEL = 'ailand/openrouter/rendering_model';
    const DEFAULT_THINKING_MODEL = 'deepseek/deepseek-r1:free';
    const DEFAULT_RENDERING_MODEL = 'deepseek/deepseek-chat-v3-0324:free';

    const API_BASE_URL = 'https://openrouter.ai/api/v1';
    const CHAT_COMPLETIONS_ENDPOINT = '/chat/completions';
    const AUTH_KEY_ENDPOINT = '/key';
    const MODELS_ENDPOINT = '/models';
    const MAX_TOOL_ITERATIONS = 5; // Maximum number of sequential tool calls allowed

    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeConfigInterface // Added
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface // Added
     */
    private $encryptor;

    /**
     * @var AiToolPool // Added
     */
    private $aiToolPool;

    /**
     * Constructor
     *
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param AiToolPool $aiToolPool
     */
    public function __construct(
        JsonSerializer       $jsonSerializer,
        LoggerInterface      $logger,
        ScopeConfigInterface $scopeConfig, // Added
        EncryptorInterface   $encryptor, // Added
        AiToolPool           $aiToolPool // Added
    ) {
        $this->jsonSerializer = $jsonSerializer;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig; // Added
        $this->encryptor = $encryptor; // Added
        $this->aiToolPool = $aiToolPool; // Added
    }

    /**
     * Get completion from the AI, handling potential tool calls.
     *
     * @param array $messages The conversation history.
     * @param string $modelKind The kind of model to use ('thinking' or 'rendering').
     * @param string[] $toolIdentifiers List of tool identifiers (keys from AiToolPool) available for this call.
     * @param int $storeId Store scope ID.
     * @return string The final content from the AI response.
     * @throws LocalizedException
     */
    public function getCompletion(
        array $messages,
        string $modelKind, // Changed parameter name
        array $toolIdentifiers = [],
        int $storeId = 0
    ): string {
        $apiKey = $this->getApiKey($storeId);
        if (!$apiKey) {
            throw new LocalizedException(__('OpenRouter API Key is not configured for store %1.', $storeId));
        }

        $model = $this->getModelForKind($modelKind, $storeId);
        if (!$model) {
            throw new LocalizedException(__('Could not determine AI model for kind "%1".', $modelKind));
        }

        $toolDefinitions = [];
        if (!empty($toolIdentifiers)) {
            foreach ($toolIdentifiers as $identifier) {
                try {
                    $tool = $this->aiToolPool->getTool($identifier);
                    $toolDefinitions[] = $tool->getToolDefinition();
                } catch (LocalizedException $e) {
                    $this->logger->warning("Could not get tool definition for identifier '$identifier': " . $e->getMessage());
                }
            }
        }

        $currentMessages = $messages;

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            // Pass tool definitions only if they were initially requested
            $toolsToPass = !empty($toolIdentifiers) ? $toolDefinitions : null;
            $logIdentifier = sprintf('%s_call%d', $modelKind, $i + 1);

            $response = $this->makeAiApiCall($apiKey, $model, $currentMessages, $logIdentifier, $toolsToPass);
            $responseData = $this->jsonSerializer->unserialize($response);

            if (isset($responseData['error']['message'])) {
                $providerErrorMessage = $responseData['error']['message'];
                $this->logger->error("OpenRouter API Error [$logIdentifier]: " . $providerErrorMessage, ['error' => $responseData['error']]);
                throw new LocalizedException(__("AI Service Error [$logIdentifier]: %1", $providerErrorMessage));
            }

            $aiResponseMessage = $responseData['choices'][0]['message'] ?? null;
            if (!$aiResponseMessage) {
                $this->logger->error("Could not extract AI message from OpenRouter response [$logIdentifier].", ['response' => $responseData]);
                throw new LocalizedException(__("OpenRouter API returned an unexpected response format [$logIdentifier]."));
            }

            // Always add the AI's response (even if it contains tool calls)
            $currentMessages[] = $aiResponseMessage;

            // Check for Tool Calls
            if (!empty($aiResponseMessage['tool_calls'])) {
                // Check if we are on the last iteration and still getting tool calls
                if ($i === self::MAX_TOOL_ITERATIONS - 1) {
                    $this->logger->error("Max tool iterations reached, but AI requested further tool calls.", ['messages' => $currentMessages]);
                    throw new LocalizedException(__('Maximum tool execution iterations (%1) reached.', self::MAX_TOOL_ITERATIONS));
                }

                // Process tool calls
                foreach ($aiResponseMessage['tool_calls'] as $toolCall) {
                    $toolCallId = $toolCall['id'] ?? null;
                    $functionName = $toolCall['function']['name'] ?? null;
                    $argumentsJson = $toolCall['function']['arguments'] ?? null;

                    if (!$toolCallId || !$functionName || $argumentsJson === null) {
                        $this->logger->error("Invalid tool call structure received from AI [$logIdentifier].", ['tool_call' => $toolCall]);
                        // Add a generic error message to the conversation history
                        $currentMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId ?? 'unknown',
                            'name' => $functionName ?? 'unknown',
                            'content' => "Error: AI returned an invalid tool call structure.",
                        ];
                        // Continue to the next tool call if any, or next iteration
                        continue;
                    }

                    try {
                        $tool = $this->aiToolPool->getTool($functionName);
                        $arguments = $this->jsonSerializer->unserialize($argumentsJson);
                        $toolResult = $tool->execute($arguments, $storeId);

                        $currentMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'name' => $functionName,
                            'content' => $toolResult,
                        ];
                    } catch (Exception $e) {
                        $this->logger->error("Error executing tool [$functionName] for [$logIdentifier]: " . $e->getMessage(), ['exception' => $e]);
                        $currentMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'name' => $functionName,
                            'content' => "Error executing tool: " . $e->getMessage(),
                        ];
                    }
                }
                // Continue to the next loop iteration to send tool results back
                continue;

            } else {
                // No Tool Calls - this is the final response
                $content = $aiResponseMessage['content'] ?? null;
                if ($content === null) {
                    $this->logger->error("Could not extract content from final OpenRouter response [$logIdentifier].", ['response' => $responseData]);
                    throw new LocalizedException(__("OpenRouter API returned empty content [$logIdentifier]."));
                }
                return trim((string)$content); // Exit loop and return content
            }
        }

        // If the loop finishes without returning, it means max iterations were hit
        $this->logger->error("Max tool iterations reached without a final AI response.", ['messages' => $currentMessages]);
        throw new LocalizedException(__('Maximum tool execution iterations (%1) reached without a final response.', self::MAX_TOOL_ITERATIONS));
    }

     /**
      * Internal helper to make the actual API request via makeRequest.
      *
      * @param string $apiKey
      * @param string $model
      * @param array $messages
      * @param string $logIdentifier
      * @param array|null $tools
      * @return string Raw response body
      * @throws LocalizedException
      */
     private function makeAiApiCall(string $apiKey, string $model, array $messages, string $logIdentifier, ?array $tools = null): string
     {
         $payload = [
             'model' => $model,
             'messages' => $messages
         ];

         if (!empty($tools)) {
             $payload['tools'] = $tools;
             $payload['tool_choice'] = 'auto'; // Let the AI decide
         }

         $url = self::API_BASE_URL . self::CHAT_COMPLETIONS_ENDPOINT;
         $headers = ['Content-Type: application/json'];
         $payloadJson = $this->jsonSerializer->serialize($payload); // Serialize payload once

         try {
             // Add debug logging for the request payload
             $this->logger->debug("OpenRouter API Request [$logIdentifier]: ", [
                 'url' => $url,
                 'model' => $model,
                 'messages_count' => count($messages),
                 'tools_provided' => !empty($tools),
                 'payload_preview' => substr($payloadJson, 0, 500) . (strlen($payloadJson) > 500 ? '...' : '') // Log preview
                 // Avoid logging full messages/payload by default unless absolutely needed for deep debugging
                 // 'full_payload' => $payload // Uncomment if full payload logging is required
             ]);

             $response = $this->makeRequest($apiKey, $url, 'POST', $headers, $payloadJson, 300);

             // Add debug logging for the raw response
             $this->logger->debug("OpenRouter API Raw Response [$logIdentifier]: ", [
                 'response_preview' => substr(trim($response), 0, 500) . (strlen(trim($response)) > 500 ? '...' : '') // Log preview
                 // 'full_response' => $response // Uncomment if full response logging is required
             ]);

             return $response;
         } catch (LocalizedException $e) {
            if (strpos($e->getMessage(), 'No endpoints found that support tool use') !== false) {
                $this->logger->error("OpenRouter API Error [$logIdentifier]: " . $e->getMessage() . " - Retrying without tools.");
                return $this->makeAiApiCall($apiKey, $model, $messages, $logIdentifier); // Retry without tools
            }
             // Keep error logging
             $this->logger->error("OpenRouter API Error [$logIdentifier]: " . $e->getMessage());
             throw $e; // Re-throw
         } catch (Exception $e) {
             $this->logger->critical("Critical Error calling OpenRouter API [$logIdentifier]: " . $e->getMessage(), ['exception' => $e]);
             throw new LocalizedException(__("An unexpected error occurred calling the AI service [$logIdentifier]: %1", $e->getMessage()));
         }
     }


    /**
      * Fetches the account status.
      * @param int $storeId
      * @return array|null Account status details or null on error.
      */
     public function getAccountStatus(int $storeId = 0): ?array
     {
         $apiKey = $this->getApiKey($storeId);
         if (!$apiKey) {
             $this->logger->warning('Cannot get account status: API Key not configured for store ' . $storeId);
             return null;
         }

         $url = self::API_BASE_URL . self::AUTH_KEY_ENDPOINT;

         try {
             // Removed debug logging
             $response = $this->makeRequest($apiKey, $url, 'GET');
             $responseData = $this->jsonSerializer->unserialize($response);

             // Check for API error in response body
             if (isset($responseData['error'])) {
                 $this->logger->warning("OpenRouter Account Status API Error: " . ($responseData['error']['message'] ?? 'Unknown error'), ['response' => $responseData]);
                 return null;
             }

             if (isset($responseData['data']) && is_array($responseData['data'])) {
                 return [
                     'limit_remaining' => $responseData['data']['limit_remaining'] ?? null,
                     'limit' => $responseData['data']['limit'] ?? null,
                     'usage' => $responseData['data']['usage'] ?? null,
                     'is_free_tier' => $responseData['data']['is_free_tier'] ?? null,
                     'rate_limit' => $responseData['data']['rate_limit'] ?? null,
                 ];
             } else {
                 $this->logger->error("Could not extract account status data from OpenRouter response.", ['response' => $responseData]);
                 return null;
             }

         } catch (LocalizedException $e) {
             // Keep error logging, simplified message
             $this->logger->error("OpenRouter Account Status API Error: " . $e->getMessage());
             return null;
         } catch (Exception $e) {
             $this->logger->critical("Error fetching OpenRouter Account Status: " . $e->getMessage(), ['exception' => $e]);
             return null;
         }
     }

     /**
     * Fetches details for a specific model.
     * @param string $modelId
     * @param int $storeId (Used to potentially fetch API key if needed by endpoint in future)
     * @return array|null Model details or null on error.
     */
     public function getModelDetails(string $modelId, int $storeId = 0): ?array
     {
         // Currently /models doesn't require auth, but pass key in case it changes
         $apiKey = $this->getApiKey($storeId) ?? '';

         $url = self::API_BASE_URL . self::MODELS_ENDPOINT;

         try {
             // Removed debug logging
             $response = $this->makeRequest($apiKey, $url, 'GET');
             $responseData = $this->jsonSerializer->unserialize($response);

             if (isset($responseData['data']) && is_array($responseData['data'])) {
                 foreach ($responseData['data'] as $model) {
                     if (isset($model['id']) && $model['id'] === $modelId) {
                         // Removed debug log
                         return [
                             'id' => $model['id'],
                             'name' => $model['name'] ?? $modelId,
                             'pricing' => [
                                 'prompt' => $model['pricing']['prompt'] ?? null,
                                 'completion' => $model['pricing']['completion'] ?? null,
                             ],
                             // Add other relevant details if needed
                         ];
                     }
                 }
                 $this->logger->warning("Model not found in OpenRouter response: $modelId");
                 return null;
             } else {
                 $this->logger->error("Could not extract models data from OpenRouter response.", ['response' => $responseData]);
                 return null;
             }

         } catch (LocalizedException $e) {
             // Keep error logging, simplified message
             $this->logger->error("OpenRouter Models API Error: " . $e->getMessage());
             return null;
         } catch (Exception $e) {
             $this->logger->critical("Error fetching OpenRouter Model Details: " . $e->getMessage(), ['exception' => $e]);
             return null;
         }
     }

     /**
      * Helper function to make cURL requests.
      *
      * @param string $apiKey
      * @param string $url
      * @param string $method
      * @param array $headers
      * @param string|null $payload
      * @param int $timeout
      * @return string Response body
      * @throws LocalizedException
      */
     private function makeRequest(
         string $apiKey,
         string $url,
         string $method = 'GET',
         array $headers = [],
         ?string $payload = null,
         int $timeout = 60
     ): string {
         // Ensure Authorization header is added only if API key is provided
         if (!empty($apiKey)) {
             // Remove existing Authorization header if present to avoid duplication
             $headers = array_filter($headers, function ($header) {
                 return strpos(strtolower($header), 'authorization:') !== 0;
             });
             $headers[] = 'Authorization: Bearer ' . $apiKey;
         }

         $ch = curl_init();
         curl_setopt($ch, CURLOPT_URL, $url);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
         curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
         // Follow redirects if necessary (optional)
         // curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

         if (strtoupper($method) === 'POST') {
             curl_setopt($ch, CURLOPT_POST, true);
             if ($payload !== null) {
                 curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
             }
         } elseif (strtoupper($method) !== 'GET') {
             curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
             if ($payload !== null) {
                 curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
             }
         }

         $responseBody = curl_exec($ch);
         $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $curlError = curl_error($ch);
         $curlErrno = curl_errno($ch); // Get cURL error number
         curl_close($ch);

         // Removed debug logging for response details

         if ($curlError) {
             throw new LocalizedException(__("cURL Error calling API [%1]: %2 (Code: %3)", $url, $curlError, $curlErrno));
         }

         // Handle HTTP errors
         if ($statusCode < 200 || $statusCode >= 300) {
             $errorDetails = $responseBody;
             try {
                 // Attempt to decode JSON error response from API provider
                 $decodedError = $this->jsonSerializer->unserialize((string)$responseBody);
                 if (isset($decodedError['error']['message'])) {
                     $errorDetails = $decodedError['error']['message'];
                 } elseif (isset($decodedError['message'])) { // Some APIs might use 'message' directly
                     $errorDetails = $decodedError['message'];
                 }
             } catch (Exception $e) {
                 // Ignore unserialize errors if response is not JSON
             }
             throw new LocalizedException(__(
                 "Error communicating with API [%1]: HTTP Status %2. Details: %3",
                 $url,
                 $statusCode,
                 is_string($errorDetails) ? substr($errorDetails, 0, 500) : 'Could not extract error details.'
             ));
         }

         // Handle cases where curl_exec might return false or null without an error set (less common)
         if ($responseBody === false || $responseBody === null) {
             throw new LocalizedException(__("Empty or invalid response received from API [%1] (Status: %2)", $url, $statusCode));
         }

         return (string)$responseBody;
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
             self::XML_PATH_API_KEY,
             ScopeInterface::SCOPE_STORE,
             $storeId
         );
         // Ensure key is decrypted only if it exists
         return $key ? $this->encryptor->decrypt($key) : null;
     }

     /**
      * Get the appropriate AI model name based on the model kind.
      *
      * @param string $modelKind ('thinking' or 'rendering')
      * @param int $storeId
      * @return string|null
      */
     private function getModelForKind(string $modelKind, int $storeId): ?string
     {
         $configPath = null;
         $defaultModel = null;

         if ($modelKind === 'thinking') {
             $configPath = self::XML_PATH_THINKING_MODEL;
             $defaultModel = self::DEFAULT_THINKING_MODEL;
         } elseif ($modelKind === 'rendering') {
             $configPath = self::XML_PATH_RENDERING_MODEL;
             $defaultModel = self::DEFAULT_RENDERING_MODEL;
         } else {
             $this->logger->warning("Unknown model kind '$modelKind' requested.");
             return null; // Return null for unknown kind
         }

         return $this->scopeConfig->getValue(
             $configPath,
             ScopeInterface::SCOPE_STORE,
             $storeId
         ) ?: $defaultModel;
     }
 }
