# NeutromeLabs AI Landing Page Generator (AiLand) for Magento 2

## Overview

This module allows Magento administrators to generate HTML landing page content using an AI service 
(currently configured for Google Gemini) based on product data, category data, or a custom text prompt. 
The generated content can then be reviewed and saved directly as a standard Magento CMS Block.

**Current Version:** 1.0.0
**Target Magento Version:** 2.4.6

## Features

* Generate landing page HTML using Google Gemini.
* Input sources:
    * Specific Product data (Name, Descriptions, Price, Meta Desc)
    * Specific Category data (Name, Description, Product Names)
    * Custom user-provided text prompt.
* Admin interface integrated under Content > Elements > Generate AI Landing.
* Preview generated HTML content.
* Option to regenerate content.
* Save generated content directly as a new, active CMS Block.
* Configuration section for API Key management.

## Installation

**Using Composer (Recommended - if packaged):**

```bash
composer require neutromelabs/module-ailand
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy [your locales] -f
bin/magento cache:flush
```

*(Note: Replace `neutromelabs/module-ailand` with the actual package name if published)*

**Manual Installation:**

1. Download or clone the module files.
2. Create the directory structure `app/code/NeutromeLabs/AiLand`.
3. Copy the module files into this directory.
4. Enable the module and run setup commands:
   ```bash
   bin/magento module:enable NeutromeLabs_AiLand
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento setup:static-content:deploy [your locales] -f
   bin/magento cache:flush
   ```

## Configuration

1. Log in to the Magento Admin panel.
2. Navigate to **Stores > Configuration > NeutromeLabs > AI Landings**.
3. Expand the **Google Gemini API** section.
4. Enter your **API Key** obtained from Google AI Studio or Google Cloud Console.
5. Click **Save Config**.

## Usage

See `USER_FLOWS.md` for detailed steps on how to generate and save landing pages.

## TODO / Future Enhancements

* Implement the actual Google Gemini API call logic in `Model/AiGenerator.php`.
* Add robust JavaScript for handling AJAX calls, button states (loading indicators), and updating the preview area
  dynamically.
* Implement the full Product Chooser UI component listing.
* Add more sophisticated validation for form inputs.
* Potentially add options for AI model selection, temperature, max tokens, etc., in configuration.
* Improve error handling and user feedback for API calls.
* Add unit/integration tests.
