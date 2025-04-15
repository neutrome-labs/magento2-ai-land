define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'mage/validation' // Use Magento's validation widget
], function ($, $t, alert) {
    'use strict';

    console.log('AiLand form-interactions.js loaded'); // Debug log

    return function (config, element) {
        console.log('AiLand form-interactions initialized with config:', config); // Debug log

        // New selectors
        var $saveAsType = $(config.saveAsTypeSelector);
        var $promptTemplateSelector = $(config.promptTemplateSelector);
        var $customPrompt = $(config.customPromptSelector);
        var $titleLabel = $(config.titleLabelSelector);
        var $identifierLabel = $(config.identifierLabelSelector);
        var promptTemplates = config.promptTemplatesJson || {}; // Get templates data
        var $dataSourceType = $(config.dataSourceTypeSelector); // Re-added
        var $productIdContainer = $(config.productIdFieldContainer); // Re-added
        var $categoryIdContainer = $(config.categoryIdFieldContainer); // Re-added

        // Existing selectors
        var generateButtonClass = config.generateButtonClass;
        var improveButtonClass = config.improveButtonClass;
        var $designPlanArea = $(config.designPlanArea);
        var $previewIframe = $(config.previewIframe);
        var $hiddenContentArea = $(config.previewArea);
        var $form = $(config.formId);
        var $storeSwitcher = $(config.storeSwitcherSelector); // Use configured selector
        var isGenerating = false; // Flag to prevent multiple simultaneous requests
        var previewUrl = config.previewUrl; // Get preview URL from config
        var formKey = config.formKey; // Get form key from config

        // Initialize form validation with Tailwind adjustments
        $form.mage('validation', {
            errorPlacement: function (error, element) {
                // Find the dedicated error placeholder for this element
                var errorPlaceholder = $('[data-validation-error-for="' + element.attr('id') + '"]');
                if (errorPlaceholder.length) {
                    errorPlaceholder.html(error); // Place the error message in the placeholder
                } else {
                    error.insertAfter(element); // Fallback if no placeholder found
                }
                // Add Tailwind classes to highlight the invalid field
                element.addClass('border-red-500 focus:border-red-500 focus:ring-red-500');
                element.closest('div').find('label').addClass('text-red-600'); // Highlight label too
            },
            unhighlight: function (element) {
                // Remove error message from placeholder
                var errorPlaceholder = $('[data-validation-error-for="' + $(element).attr('id') + '"]');
                if (errorPlaceholder.length) {
                    errorPlaceholder.empty(); // Clear the placeholder
                }
                // Remove Tailwind highlighting classes
                $(element).removeClass('border-red-500 focus:border-red-500 focus:ring-red-500');
                $(element).closest('div').find('label').removeClass('text-red-600');
            }
        });

        // Function to toggle visibility of Product/Category ID fields (Re-added)
        function toggleSourceFields() {
            var selectedType = $dataSourceType.val();
            $productIdContainer.hide().find('input').val(''); // Hide and clear value
            $categoryIdContainer.hide().find('input').val(''); // Hide and clear value

            if (selectedType === 'product') {
                $productIdContainer.show();
            } else if (selectedType === 'category') {
                $categoryIdContainer.show();
            }
        }

        // Function to populate prompt template selector
        function populateTemplateSelector() {
            $.each(promptTemplates, function(key, template) {
                if (template.label) {
                    var $option = $('<option></option>').val(key).text(template.label);
                    $promptTemplateSelector.append($option);
                }
            });
        }

        // Function to load selected template into custom prompt field
        function loadSelectedTemplate() {
            var selectedKey = $promptTemplateSelector.val();
            if (selectedKey && promptTemplates[selectedKey] && promptTemplates[selectedKey].prompt) {
                $customPrompt.val(promptTemplates[selectedKey].prompt).trigger('change'); // Set value and trigger change
            }
        }

        // Function to update Title/Identifier labels based on Save As type
        function updateLabels() {
            var saveType = $saveAsType.val();
            if (saveType === 'page') {
                $titleLabel.text($t('CMS Page Title'));
                $identifierLabel.text($t('CMS Page Identifier'));
            } else { // Default to block or if empty
                $titleLabel.text($t('CMS Block Title'));
                $identifierLabel.text($t('CMS Block Identifier'));
            }
        }

        // Function to handle AJAX call for generation or improvement
        function processRequest(actionType) {
            if (isGenerating) {
                return; // Prevent multiple clicks while processing
            }

            // Use Magento's validation
            // Temporarily comment out validation check for debugging
            /*
            if ($form.validation && !$form.validation('isValid')) {
                 // Errors are shown by the validation widget
                 console.log('Form validation failed'); // Debug log
                 return;
            }
            */

            console.log('Processing request:', actionType); // Debug log
            isGenerating = true;
            // Select all buttons with the classes
            var $generateButtons = $(generateButtonClass);
            var $improveButtons = $(improveButtonClass);
            var $buttonsToDisable = (actionType === 'improve') ? $improveButtons : $generateButtons;
            var $otherButtons = (actionType === 'improve') ? $generateButtons : $improveButtons;
            var originalButtonTexts = {}; // Store original text for each button

            // Disable buttons and show loading state
            $buttonsToDisable.each(function () {
                var $btn = $(this);
                originalButtonTexts[$btn.attr('id') || $btn.index()] = $btn.find('span').text(); // Store original text
            });
            $otherButtons.prop('disabled', true).addClass('disabled'); // Disable the other set too

            var formData = $form.serializeArray();
            // Add actionType flag
            formData.push({name: 'action_type', value: actionType});

            $.ajax({
                url: config.generateUrl,
                type: 'POST',
                dataType: 'json',
                data: formData,
                showLoader: true, // Use Magento's loader
                success: function (response) {
                    if (response.success && response.html !== undefined) {
                        // Update design plan area (if provided, null on improve)
                        $designPlanArea.val(response.design || '');

                        // Update hidden textarea for form submission
                        $hiddenContentArea.val(response.html);

                        // Update iframe preview using form submission
                        var htmlContent = response.html;
                        var storeId = $storeSwitcher.val();

                        if (!storeId) {
                            alert({title: $t('Preview Error'), content: $t('Please select a Store View before generating a preview.')});
                            // Optionally clear iframe or show message
                            $previewIframe.attr('src', 'about:blank');
                        } else if (previewUrl && formKey) {
                            // Create a temporary form
                            var $tempForm = $('<form></form>')
                                .attr('method', 'post')
                                .attr('action', previewUrl)
                                .attr('target', 'ailand_preview_frame') // Target the iframe by name
                                .css('display', 'none');

                            // Add content input
                            $('<input>')
                                .attr('type', 'hidden')
                                .attr('name', 'content')
                                .val(htmlContent)
                                .appendTo($tempForm);

                            // Add form key input
                            $('<input>')
                                .attr('type', 'hidden')
                                .attr('name', 'form_key')
                                .val(formKey)
                                .appendTo($tempForm);

                            // Add store_id input
                            $('<input>')
                                .attr('type', 'hidden')
                                .attr('name', 'store_id')
                                .val(storeId)
                                .appendTo($tempForm);

                            // Append form to body, submit, and remove
                            $tempForm.appendTo('body');
                            $tempForm.submit();
                            $tempForm.remove();
                        } else {
                             alert({title: $t('Configuration Error'), content: $t('Preview URL or Form Key is missing in configuration.')});
                             $previewIframe.attr('src', 'about:blank');
                        }

                    } else {
                        var errorMessage = response.message || $t('An unknown error occurred during generation.');
                        alert({title: $t('Generation Error'), content: errorMessage});
                        $previewIframe.attr('src', 'about:blank'); // Clear iframe on error
                        $hiddenContentArea.val(''); // Clear hidden content on error
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    var errorMsg = $t('Could not connect to the generation service. Status: ') + textStatus + ', Error: ' + errorThrown;
                    alert({title: $t('AJAX Error'), content: errorMsg});
                    $previewIframe.attr('src', 'about:blank'); // Clear iframe on error
                    $hiddenContentArea.val(''); // Clear hidden content on error
                },
                complete: function () {
                    // Re-enable buttons and restore text
                    $buttonsToDisable.each(function () {
                        var $btn = $(this);
                        var originalText = originalButtonTexts[$btn.attr('id') || $btn.index()];
                        $btn.prop('disabled', false).removeClass('disabled').find('span').text(originalText);
                    });
                    $otherButtons.prop('disabled', false).removeClass('disabled');
                    isGenerating = false;
                }
            });
        }

        // Initial setup
        populateTemplateSelector();
        updateLabels();
        toggleSourceFields(); // Set initial visibility for data source fields

        // Event listeners using delegation from the form element
        $form.on('change', config.saveAsTypeSelector, updateLabels);
        $form.on('change', config.promptTemplateSelector, loadSelectedTemplate);
        $form.on('change', config.dataSourceTypeSelector, toggleSourceFields); // Re-added listener

        // Button clicks
        $form.on('click', generateButtonClass, function (e) {
            e.preventDefault();
            processRequest('generate');
        });
        $form.on('click', improveButtonClass, function (e) {
            e.preventDefault();
            processRequest('improve');
        });
    };
});
