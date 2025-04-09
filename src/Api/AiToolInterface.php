<?php
/**
 * NeutromeLabs AiLand AI Tool Interface
 *
 * Defines the contract for AI tools that can be defined and potentially executed.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Interface AiToolInterface
 */
interface AiToolInterface
{
    /**
     * Get the tool definition schema for the AI.
     *
     * Should return an array conforming to the expected format (e.g., OpenAI function definition).
     * Example:
     * [
     *   'type' => 'function',
     *   'function' => [
     *     'name' => 'tool_name',
     *     'description' => 'Tool description',
     *     'parameters' => [
     *       'type' => 'object',
     *       'properties' => [
     *         'param1' => ['type' => 'string', 'description' => '...'],
     *         // ... other parameters
     *       ],
     *       'required' => ['param1']
     *     ]
     *   ]
     * ]
     *
     * @return array
     */
    public function getToolDefinition(): array;

    /**
     * Execute the tool's action using the arguments provided by the AI and the store context.
     * The tool is responsible for retrieving necessary configurations (like API keys) itself.
     *
     * @param array $arguments Decoded arguments from the AI's tool call.
     * @param int $storeId The store scope ID.
     * @return string The result of the tool execution, to be sent back to the AI.
     * @throws LocalizedException If execution fails.
     */
    public function execute(array $arguments, int $storeId = 0): string;
}
