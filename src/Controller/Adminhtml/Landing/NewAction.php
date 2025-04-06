<?php
/**
 * NeutromeLabs AiLand Admin Controller for New Landing Page Form
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Controller\Adminhtml\Landing;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Controller for displaying the AI Landing generation form.
 */
class NewAction extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'NeutromeLabs_AiLand::generate'; // Corresponds to the resource in menu.xml

    /**
     * @param Context $context
     */
    public function __construct(
        Context $context
    )
    {
        parent::__construct($context);
    }

    /**
     * Create new landing page action
     *
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('NeutromeLabs_AiLand::generate_landing'); // Highlight the menu item
        $resultPage->getConfig()->getTitle()->prepend(__('Generate AI Landing'));

        return $resultPage;
    }

    /**
     * Check permission.
     * For now, checks the top-level resource defined in menu.xml
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
