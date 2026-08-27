<?php
/**
 * Main function to generate the print preview and workflow UI.
 */
function fsbhoa_render_printable_card_view() {
    if ( ! isset( $_GET['cardholder_id'] ) || ! is_numeric( $_GET['cardholder_id'] ) ) {
        echo '<p>' . esc_html__( 'No cardholder ID provided.', 'fsbhoa-ac' ) . '</p>';
        return;
    }
    $cardholder_id = absint( $_GET['cardholder_id'] );

    global $wpdb;
    $cardholder = $wpdb->get_row( $wpdb->prepare("
        SELECT c.*, p.street_address, cred.expiration_date AS card_expiry_date
        FROM ac_cardholders c
        LEFT JOIN ac_property p ON c.property_id = p.property_id
        LEFT JOIN ac_credentials cred ON c.id = cred.cardholder_id AND cred.credential_type = 'MIFARE_BADGE'
        WHERE c.id = %d
    ", $cardholder_id), ARRAY_A);

    if ( $wpdb->last_error ) {
        error_log('FSBHOA DB Error on print page query: ' . $wpdb->last_error);
        echo '<p>' . esc_html__('A database error occurred. Please contact an administrator.', 'fsbhoa-ac') . '</p>';
        return;
    }
    if ( ! $cardholder ) {
        echo '<p>' . esc_html__( 'Cardholder not found.', 'fsbhoa-ac' ) . '</p>';
        return;
    }

    // --- Prepare display variables ---
    $first_name = trim($cardholder['first_name']);
    $last_name = trim($cardholder['last_name']);
    $full_name = $first_name . ' ' . $last_name;
    $title = trim($cardholder['title']);
    $photo_src = !empty($cardholder['photo']) ? 'data:image/jpeg;base64,' . base64_encode($cardholder['photo']) : '';
    $subtitle_text = '';
    if (!empty($title)) {
        $subtitle_text = $title;
    } elseif ($cardholder['card_expiry_date'] && $cardholder['card_expiry_date'] !== '2099-12-31') {
        $expiration_text = date('m/d/Y', strtotime($cardholder['card_expiry_date']));
        $subtitle_text = 'Expires: ' . $expiration_text;
    }
    ?>

    <div class="fsbhoa-print-page-wrapper" data-debug-mode="<?php echo (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on') ? 'true' : 'false'; ?>">
        <div class="fsbhoa-print-columns">

            <div class="fsbhoa-card-preview-container">
                <h3>Card Preview</h3>
                <div class="id-card-container">
                    <div class="id-card-body">
                        <div class="id-card-photo">
                            <?php if ($photo_src): ?>
                                <img src="<?php echo esc_attr($photo_src); ?>" alt="Cardholder Photo">
                            <?php endif; ?>
                        </div>
                        <div class="id-card-info">
                            <p class="card-name"><?php echo esc_html($first_name); ?></p>
                            <p class="card-name"><?php echo esc_html($last_name); ?></p>
                            
                            <?php if (!empty($subtitle_text)): ?>
                                <p class="card-subtitle"><?php echo esc_html($subtitle_text); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fsbhoa-cardholder-details-container">
                <h3>Cardholder Details</h3>
                <?php
                   $report_page = get_page_by_path('cardholder-pages');
                   if ($report_page) {
                       $report_url = add_query_arg('selected_ids', $cardholder_id, get_permalink($report_page->ID));
                 ?>
                       <a href="<?php echo esc_url($report_url); ?>" target="_blank" class="button button-secondary" style="float: right; margin-top: -40px;">
                           <?php esc_html_e('Print Paper Record', 'fsbhoa-ac'); ?>
                       </a>
                <?php
                   } 
                ?>
                <div class="details-box">
                    <p><strong>Name:</strong> <?php echo esc_html($full_name); ?></p>
                    <?php if (!empty($title)): ?>
                        <p><strong>Title:</strong> <?php echo esc_html($title); ?></p>
                    <?php endif; ?>
                    <p><strong>Address:</strong> <?php echo esc_html($cardholder['street_address'] ?? 'N/A'); ?></p>
                    <p><strong>Phone:</strong> <?php echo esc_html( !empty($cardholder['phone']) ? $cardholder['phone'] . ' (' . $cardholder['phone_type'] . ')' : 'N/A' ); ?></p>
                    <p><strong>Email:</strong> <?php echo esc_html($cardholder['email'] ?? 'N/A'); ?></p>
                    <p><strong>Resident Type:</strong> <?php echo esc_html($cardholder['resident_type'] ?? 'N/A'); ?></p>
                </div>
            </div>

        </div>

        <div class="workflow-container">
            <div id="fsbhoa-initial-section" class="workflow-section">
                <?php
                    // Check if Print Debug Mode (Dry Run) is active
                    if (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on') {
                        $settings_url = admin_url('admin.php?page=fsbhoa_print_service_settings');
                        echo '<div class="notice notice-warning inline" style="margin-bottom: 1em; padding: 10px;">';
                        echo '<p><strong>Debug Mode (Dry Run) is ON.</strong> The job will NOT be sent to the printer.</p>';
                        echo '</div>';
                    }
                ?>
                <p>Verify the card preview and details above are correct.</p>
                <button id="fsbhoa-start-print-btn" class="button button-primary button-hero"
                        data-cardholder-id="<?php echo esc_attr($cardholder_id); ?>">Start Print Process</button>
            </div>
            <div id="fsbhoa-status-section" class="workflow-section">
                <p class="status-message">Please wait...</p>
            </div>
            <div id="fsbhoa-rfid-section" class="workflow-section">
                <h3>Print Complete!</h3>
                <p>Please swipe the newly printed card now.</p>
                <input type="text" id="fsbhoa-rfid-input" maxlength="8" autofocus />
            </div>
            <div id="fsbhoa-dryrun-section" class="workflow-section">
                <h3>Dry Run Complete!</h3>
                <p>The image has been generated successfully.</p>
                <a href="#" id="fsbhoa-view-image-btn" class="button button-primary" target="_blank" rel="noopener noreferrer">View Generated Image</a>
            </div>
            <div class="fsbhoa-workflow-footer">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('cardholder'))); ?>" class="button button-secondary">Cancel and Go Back to List</a>
            </div>
        </div>
    </div>
    <?php
}
