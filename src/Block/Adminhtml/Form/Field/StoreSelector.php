<?php
/**
 * NeutromeLabs AiLand Custom Store Selector Block
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\System\Store as SystemStore; // Provides store options

/**
 * Renders a custom dropdown for selecting a store view.
 */
class StoreSelector extends Select
{
    /**
     * @var SystemStore
     */
    protected $systemStore;

    /**
     * @param Context $context
     * @param SystemStore $systemStore
     * @param array $data
     */
    public function __construct(
        Context $context,
        SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->systemStore = $systemStore;
    }

    /**
     * Set input name
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * Set input id
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * Render block HTML
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }
        return parent::_toHtml();
    }

    /**
     * Retrieve source options
     *
     * @return array
     */
    private function getSourceOptions(): array
    {
        // Get store views, including the 'All Store Views' option if desired
        // The 'false' argument includes the default 'All Store Views' option with value 0
        // The 'true' argument adds website/group structure which we don't need here
        return $this->systemStore->getStoreValuesForForm(false, false);
    }
}
