# NeutromeLabs AiLand - User Flows

This document outlines the steps an administrator takes to use the AI Landing Page Generator module.

## Prerequisites

1. The `NeutromeLabs_AiLand` module is installed and enabled.
2. The Google Gemini API Key has been correctly configured under **Stores > Configuration > NeutromeLabs > AI Landings**.

## Flow 1: Generating a Landing Page from a Custom Prompt

1. Navigate to **Content > Elements > Generate AI Landing** in the Magento Admin panel.
2. The "Generate AI Landing Page" form appears.
3. In the **Generation Settings** section:
    * Enter a **CMS Block Title** for the new block (e.g., "Spring Sale AI Landing").
    * Enter a unique **CMS Block Identifier** (e.g., `spring_sale_ai_landing`). Remember to use only lowercase letters,
      numbers, and underscores.
    * Select **"Custom Prompt"** from the **Generation Source** dropdown.
    * The **Custom Prompt** textarea will become visible. Enter the detailed prompt describing the desired landing page
      content. Be specific about the tone, key messages, calls to action, etc.
4. Click the **"Generate"** button.
    * *(Behind the scenes: An AJAX request is sent to the server with the prompt. The server contacts the Google Gemini
      API.)*
    * Wait for the generation process to complete (JavaScript should ideally show a loading indicator).
5. Once complete, the generated HTML content will appear in the **Generated Content Preview** textarea below.
6. **Review** the generated HTML content in the preview area.
7. **(Optional) Regenerate:** If unsatisfied with the content, modify the **Custom Prompt** if needed, and click the **"
   Regenerate"** button. The preview area will update with the new content. Repeat as necessary.
8. **Save:** Once satisfied with the previewed content, click the **"Save as CMS Block"** button (top right).
9. If successful, you will be redirected to the CMS Blocks grid (**Content > Elements > Blocks**), and a success message
   will confirm the block creation. The new block will be listed there, active, and assigned to all store views by
   default.
10. If there are errors (e.g., duplicate identifier, missing required fields), an error message will appear, and you
    will remain on the form page with your entered data preserved. Correct the errors and try saving again.

## Flow 2: Generating a Landing Page from a Product

1. Navigate to **Content > Elements > Generate AI Landing**.
2. In the **Generation Settings** section:
    * Enter a **CMS Block Title**.
    * Enter a unique **CMS Block Identifier**.
    * Select **"Product"** from the **Generation Source** dropdown.
    * The **Select Product** field/button will become visible.
3. Click the button or link associated with **Select Product**.
    * *(UI Component: A modal window with a product grid should appear - Note: This part requires full UI component
      implementation)*.
4. Find and select the desired product from the grid/modal. Click "Add Selected Product" (or similar).
5. The selected Product ID should populate the necessary hidden field in the form.
6. Click the **"Generate"** button.
    * *(Behind the scenes: AJAX request sends `source_type=product` and the selected `product_id`. The server fetches
      product data (Name, Descriptions, Price, Meta Desc) and sends a formatted prompt to the AI.)*
7. Wait for generation and review the content in the **Generated Content Preview** area.
8. **(Optional) Regenerate:** Click **"Regenerate"** if needed. The same product data will be used.
9. **Save:** Click **"Save as CMS Block"** when satisfied.
10. Check for success/error messages and redirection as in Flow 1.

## Flow 3: Generating a Landing Page from a Category

1. Navigate to **Content > Elements > Generate AI Landing**.
2. In the **Generation Settings** section:
    * Enter a **CMS Block Title**.
    * Enter a unique **CMS Block Identifier**.
    * Select **"Category"** from the **Generation Source** dropdown.
    * The **Select Category** field (likely a dropdown tree) will become visible.
3. Select the desired category from the dropdown/tree structure.
4. Click the **"Generate"** button.
    * *(Behind the scenes: AJAX request sends `source_type=category` and the selected `category_id`. The server fetches
      category data (Name, Description, Product Names) and sends a formatted prompt to the AI.)*
5. Wait for generation and review the content in the **Generated Content Preview** area.
6. **(Optional) Regenerate:** Click **"Regenerate"** if needed. The same category data will be used.
7. **Save:** Click **"Save as CMS Block"** when satisfied.
8. Check for success/error messages and redirection as in Flow 1.
