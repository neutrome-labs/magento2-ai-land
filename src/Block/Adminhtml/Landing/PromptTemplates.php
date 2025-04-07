<?php
/**
 * NeutromeLabs AiLand Block for Providing Prompt Templates
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Block\Adminhtml\Landing;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Block to provide predefined prompt templates to the form.
 */
class PromptTemplates extends Template
{
    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * @param Context $context
     * @param Json $jsonSerializer
     * @param array $data
     */
    public function __construct(
        Context $context,
        Json $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * Get predefined prompt templates as a JSON string.
     *
     * @return string
     */
    public function getPromptTemplatesJson(): string
    {
        $templates = [
            'basic_page' => [
                'label' => 'Basic Content Page',
                'prompt' => <<<PROMPT
Create a standard content page with the following sections:
1.  **Hero Section:** Large heading "[Your Main Title Here]", subheading "[Brief description]", and a call-to-action button "Learn More".
2.  **Introduction:** A paragraph explaining [Topic of the page].
3.  **Key Features (3 columns):** List three key features/benefits with icons and short descriptions.
    *   Feature 1: [Description]
    *   Feature 2: [Description]
    *   Feature 3: [Description]
4.  **Conclusion:** A summary paragraph reinforcing the main message.
5.  **Final CTA:** A section with the heading "Ready to Start?" and a button "Get Started Now".

Style: Clean, professional, use standard Bootstrap classes for layout.
PROMPT
            ],
            'product_promo_block' => [
                'label' => 'Product Promotion Block',
                'prompt' => <<<PROMPT
Create a promotional block for a product.
-   **Layout:** Image on the left, text on the right (responsive).
-   **Image:** Placeholder for product image (use a generic placeholder URL if possible).
-   **Text:**
    -   Headline: "[Product Name] - Special Offer!"
    -   Description: "[Briefly describe the product and the promotion, e.g., 20% off this week only!]"
    -   Button: "Shop Now" linking to "[Product URL]".

Style: Eye-catching, clear call-to-action. Use CSS classes for easy styling (e.g., `promo-block`, `promo-image`, `promo-text`).
PROMPT
            ],
            'faq_block' => [
                'label' => 'FAQ Block (Accordion)',
                'prompt' => <<<PROMPT
Create an FAQ section using an accordion structure. Include the following questions:
1.  **[Question 1]?**
    *   [Answer 1]
2.  **[Question 2]?**
    *   [Answer 2]
3.  **[Question 3]?**
    *   [Answer 3]

Structure: Use appropriate HTML (e.g., divs, buttons/headings for triggers, content divs) that can be easily targeted by JavaScript to create an accordion effect. Add basic ARIA attributes for accessibility if possible.
PROMPT
            ],
            // Add more templates as needed
        ];

        return $this->jsonSerializer->serialize($templates);
    }
}
