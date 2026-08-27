jQuery(document).ready(function($) {
    'use strict';

    const cardholderId = $('#fsbhoa-start-print-btn').data('cardholder-id');
    const cardholderListPageUrl = fsbhoa_print_vars.cardholder_page_url;
    const isDebugMode = $('.fsbhoa-print-page-wrapper').data('debug-mode');
    let pollingInterval;

    // --- UI Helper Functions ---
    function showSection(sectionId) {
        $('.workflow-section').hide();
        $(sectionId).show();
    }

    function showStatusMessage(message, type = 'normal') {
        showSection('#fsbhoa-status-section');
        const statusDiv = $('#fsbhoa-status-section .status-message');
        statusDiv.text(message).removeClass('status-error status-success');
        if (type === 'error') {
            statusDiv.addClass('status-error');
        } else if (type === 'success') {
            statusDiv.addClass('status-success');
        }
    }

    // --- Workflow Step 1: Submit Job ---
    $('#fsbhoa-start-print-btn').on('click', function() {
        $(this).prop('disabled', true);
        showStatusMessage('Submitting print job...');

        $.ajax({
            url: fsbhoa_print_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'fsbhoa_submit_print_job',
                cardholder_id: cardholderId,
                security: fsbhoa_print_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatusMessage('Job submitted. Waiting for printer...');
                    pollStatus(response.data.log_id); // Start polling using the new log_id
                } else {
                    showStatusMessage('Error: ' + response.data.message, 'error');
                }
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON ? xhr.responseJSON.data.message : 'Could not reach the server.';
                showStatusMessage('Failed to submit print job: ' + errorMsg, 'error');
            }
        });
    });

    // --- Workflow Step 2: Poll for Status ---
    function pollStatus(logId) {
        pollingInterval = setInterval(function() {
            $.ajax({
                url: fsbhoa_print_vars.ajax_url,
                method: 'POST',
                data: {
                    action: 'fsbhoa_check_print_status',
                    log_id: logId,
                    security: fsbhoa_print_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const status = response.data.status.toUpperCase();
                        const message = response.data.status_message || status;

                        if (response.data.status_message) {
                            showStatusMessage('Status: ' + message);
                        }
                        
                        // --- 2. HANDLE DEBUG VS. LIVE MODE ---
                        if (status === 'COMPLETED_OK') {
                            clearInterval(pollingInterval);
                            
                            if (isDebugMode) {
                                // DRY RUN IS COMPLETE
                                const imageUrl = fsbhoa_print_vars.ajax_url + '?action=fsbhoa_serve_dry_run_image&log_id=' + logId;
                                $('#fsbhoa-view-image-btn').attr('href', imageUrl);
                                showSection('#fsbhoa-dryrun-section');
                            } else {
                                // NORMAL PRINT IS COMPLETE
                                showSection('#fsbhoa-rfid-section');
                                $('#fsbhoa-rfid-input').focus();
                            }

                        } else if (status.includes('ERROR') || status.includes('FAILED')) {
                            clearInterval(pollingInterval);
                            showStatusMessage('Print failed: ' + message, 'error');
                        }
                    } else {
                        clearInterval(pollingInterval);
                        showStatusMessage('Error checking status: ' + response.data.message, 'error');
                    }
                }
            });
        }, 3000); // Poll every 3 seconds
    }

    // --- Workflow Step 3: Listen for RFID Input ---
    $('#fsbhoa-rfid-input').on('input', function() {
        if ($(this).val().length >= 8) {
            $(this).prop('disabled', true);
            clearInterval(pollingInterval); // Stop any lingering polling
            saveRfidAndActivate($(this).val());
        }
    });

    // --- Workflow Step 4: Save RFID and Activate ---
    function saveRfidAndActivate(rfid) {
        showStatusMessage('Saving RFID and activating card...');
        $.ajax({
            url: fsbhoa_print_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'fsbhoa_save_rfid',
                cardholder_id: cardholderId,
                rfid_id: rfid,
                security: fsbhoa_print_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message); // Simple success alert
                    if (cardholderListPageUrl) {
                        window.location.href = cardholderListPageUrl; // Redirect back to the list
                    }
                } else {
                    showStatusMessage('Error activating card: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showStatusMessage('A critical error occurred while saving the RFID.', 'error');
            }
        });
    }
});

