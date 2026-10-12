<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

function fsbhoa_render_printable_card_view() {
    if ( ! isset( $_GET['cardholder_id'] ) || ! is_numeric( $_GET['cardholder_id'] ) ) {
        echo '<p>' . esc_html__( 'No cardholder ID provided.', 'fsbhoa-ac' ) . '</p>';
        return;
    }
    $cardholder_id = absint( $_GET['cardholder_id'] );

    // 1. Render the self-contained Badge & Details Component
    require_once dirname( __FILE__ ) . '/view-badge-preview-card.php';
    fsbhoa_zebra_render_badge_card( $cardholder_id, true );

    // 2. Render Zebra's Workflow Controls (Submit, Poll, Swipe)
    $debug_mode = ( get_option( 'fsbhoa_ac_print_debug_mode', 'off' ) === 'on' );
    $cancel_url = home_url( '/cardholder/' );

    global $wpdb;
    $type = $wpdb->get_var( $wpdb->prepare( "SELECT cardholder_type FROM ac_cardholders WHERE id = %d LIMIT 1", $cardholder_id ) );
    if ( 'vendor' === $type ) {
        $cancel_url = home_url( '/vendors/' );
    }
    ?>
    <style>
        .fsbhoa-workflow-container {
            max-width: 900px;
            margin: 2em auto;
            padding: 2em 1.5em;
            border: 1px solid #ccd0d4;
            background: #fff;
            border-radius: 4px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            box-sizing: border-box;
        }
        .fsbhoa-workflow-section {
            display: none;
            margin-bottom: 1.5em;
        }
        #fsbhoa-initial-section {
            display: block;
        }
        #fsbhoa-rfid-section input {
            font-size: 1.5em;
            text-align: center;
            width: 220px;
            letter-spacing: 2px;
            padding: 5px;
            margin-top: 10px;
        }
        .status-message {
            font-weight: bold;
            font-size: 1.2em;
            padding: 1em;
        }
        .status-error { color: #d63638; }
        .status-success { color: #4CAF50; }
        .fsbhoa-workflow-footer {
            position: static !important;
            margin-top: 1.5em;
            padding-top: 1.5em;
            border-top: 1px solid #eee;
        }
    </style>

    <div class="fsbhoa-workflow-container">
        <div id="fsbhoa-initial-section" class="fsbhoa-workflow-section">
            <?php if ( $debug_mode ) : ?>
                <div class="notice notice-warning inline" style="margin-bottom: 1em; padding: 10px;">
                    <p><strong><?php esc_html_e( 'Debug Mode (Dry Run) is ON.', 'fsbhoa-ac' ); ?></strong> <?php esc_html_e( 'The job will NOT be sent to the physical printer.', 'fsbhoa-ac' ); ?></p>
                </div>
            <?php endif; ?>
            <p><?php esc_html_e( 'Verify the card preview and details above are correct.', 'fsbhoa-ac' ); ?></p>
            <button id="fsbhoa-start-print-btn" class="button button-primary button-hero" data-cardholder-id="<?php echo esc_attr( $cardholder_id ); ?>">
                <?php esc_html_e( 'Start Print Process', 'fsbhoa-ac' ); ?>
            </button>
        </div>

        <div id="fsbhoa-status-section" class="fsbhoa-workflow-section">
            <p class="status-message"><?php esc_html_e( 'Please wait...', 'fsbhoa-ac' ); ?></p>
        </div>

        <div id="fsbhoa-rfid-section" class="fsbhoa-workflow-section">
            <h3><?php esc_html_e( 'Print Complete!', 'fsbhoa-ac' ); ?></h3>
            <p><?php esc_html_e( 'Please swipe the newly printed card now.', 'fsbhoa-ac' ); ?></p>
            <input type="text" id="fsbhoa-rfid-input" maxlength="8" autofocus />
            <p id="fsbhoa-rfid-error" class="status-message status-error" style="display: none;"></p>
        </div>

        <div id="fsbhoa-dryrun-section" class="fsbhoa-workflow-section">
            <h3><?php esc_html_e( 'Dry Run Complete!', 'fsbhoa-ac' ); ?></h3>
            <p><?php esc_html_e( 'The image has been generated successfully.', 'fsbhoa-ac' ); ?></p>
            <a href="#" id="fsbhoa-view-image-btn" class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'View Generated Image', 'fsbhoa-ac' ); ?>
            </a>
        </div>

        <div class="fsbhoa-workflow-footer">
            <a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Cancel and Go Back to List', 'fsbhoa-ac' ); ?>
            </a>
        </div>
    </div>
    <?php
}

