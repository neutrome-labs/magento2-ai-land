<?php
/**
 * NeutromeLabs AiLand AI Tool Pool
 *
 * Manages available AI tools injected via DI.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model;

use NeutromeLabs\AiLand\Api\AiToolInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\NoninterceptableInterface;

/**
 * Class AiToolPool
 * Manages a collection of AI tools.
 * Implements NoninterceptableInterface to ensure the constructor argument is processed as intended by DI.
 */
class AiToolPool implements NoninterceptableInterface
{
    /**
     * @var AiToolInterface[]
     */
    private $tools;

    /**
     * Constructor.
     *
     * @param AiToolInterface[] $tools Array of tool instances keyed by their identifier.
     */
    public function __construct(array $tools = [])
    {
        // Basic validation to ensure injected items implement the interface
        foreach ($tools as $key => $tool) {
            if (!$tool instanceof AiToolInterface) {
                throw new \InvalidArgumentException(
                    "Tool with key '$key' must implement " . AiToolInterface::class
                );
            }
        }
        $this->tools = $tools;
    }

    /**
     * Get a specific tool by its identifier.
     *
     * @param string $identifier The unique identifier key of the tool (e.g., 'think', 'generate_technical_design').
     * @return AiToolInterface
     * @throws LocalizedException If the tool identifier is not found.
     */
    public function getTool(string $identifier): AiToolInterface
    {
        if (!isset($this->tools[$identifier])) {
            throw new LocalizedException(__('AI Tool with identifier "%1" not found.', $identifier));
        }
        return $this->tools[$identifier];
    }

    /**
     * Get all registered tool identifiers.
     *
     * @return string[]
     */
    public function getAllToolIdentifiers(): array
    {
        return array_keys($this->tools);
    }
}
