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

        var $dataSourceType = $(config.dataSourceTypeSelector);
        var $productIdContainer = $(config.productIdFieldContainer);
        var $categoryIdContainer = $(config.categoryIdFieldContainer);
        // Use class selectors now
        var generateButtonClass = config.generateButtonClass; 
        var improveButtonClass = config.improveButtonClass; 
        var $designPlanArea = $(config.designPlanArea);
        var $previewIframe = $(config.previewIframe);
        var $hiddenContentArea = $(config.previewArea);
        var $form = $(config.formId);
        var isGenerating = false; // Flag to prevent multiple simultaneous requests

        // Initialize form validation
        $form.mage('validation', {
            errorPlacement: function (error, element) {
                // Default Magento error placement logic (can be customized)
                 var errorPlacementParent = element.parents('.admin__field');
                 if (errorPlacementParent.length) {
                    errorPlacementParent.addClass('admin__field-error');
                    error.appendTo(errorPlacementParent.find('.admin__field-control'));
                 } else {
                    error.insertAfter(element);
                 }
            },
            unhighlight: function(element) {
                // Default Magento unhighlight logic
                $(element).parent().removeClass('admin__field-error');
            }
        });


        // Function to toggle visibility of Product/Category ID fields
        function toggleSourceFields() {
            var selectedType = $dataSourceType.val();
            $productIdContainer.hide();
            $categoryIdContainer.hide();
            // Clear values when hidden? Optional.
            // $productIdContainer.find('input').val('');
            // $categoryIdContainer.find('input').val('');

            if (selectedType === 'product') {
                $productIdContainer.show();
            } else if (selectedType === 'category') {
                $categoryIdContainer.show();
            }
        }

        // Function to handle AJAX call for generation or improvement
        function processRequest(actionType) { // Renamed function, added actionType
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
            var loadingText = (actionType === 'improve') ? $t('Improving...') : $t('Generating...');
            var originalButtonTexts = {}; // Store original text for each button

            // Disable buttons and show loading state
            $buttonsToDisable.each(function() {
                var $btn = $(this);
                originalButtonTexts[$btn.attr('id') || $btn.index()] = $btn.find('span').text(); // Store original text
                $btn.prop('disabled', true).addClass('disabled').find('span').text(loadingText);
            });
            $otherButtons.prop('disabled', true).addClass('disabled'); // Disable the other set too
            $previewIframe.contents().find('body').html(loadingText); // Show status in iframe

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

                        // Update iframe preview
                        var iframeDoc = $previewIframe[0].contentWindow.document;
                        iframeDoc.open();
                        // Add basic styling for preview if desired
                        // Include CDN links directly in preview for self-contained rendering
                        iframeDoc.write('<html><head><style>body { font-family: sans-serif; padding: 10px; }</style></head><body>' + response.html + '</body></html>');
                        iframeDoc.close();
                    } else {
                        var errorMessage = response.message || $t('An unknown error occurred during generation.');
                        alert({ title: $t('Generation Error'), content: errorMessage });
                        $previewIframe.contents().find('body').html($t('Generation failed:') + '<br>' + errorMessage);
                        $hiddenContentArea.val(''); // Clear hidden content on error
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    var errorMsg = $t('Could not connect to the generation service. Status: ') + textStatus + ', Error: ' + errorThrown;
                     alert({ title: $t('AJAX Error'), content: errorMsg });
                     $previewIframe.contents().find('body').html($t('Generation failed. Could not connect.'));
                     $hiddenContentArea.val(''); // Clear hidden content on error
                },
                complete: function () {
                    // Re-enable buttons and restore text
                    $buttonsToDisable.each(function() {
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
        toggleSourceFields(); // Set initial visibility

        // Event listeners using delegation from the form element
        $form.on('change', config.dataSourceTypeSelector, toggleSourceFields);
        // Use class selectors for button clicks
        $form.on('click', generateButtonClass, function(e) {
            e.preventDefault(); // Prevent default if it's somehow not a button type
            processRequest('generate');
        });
        $form.on('click', improveButtonClass, function(e) {
            e.preventDefault();
            processRequest('improve');
        });
    };
});
