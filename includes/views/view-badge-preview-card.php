<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Third line of the badge, shared by the preview and the printed card:
 * the title if there is one, otherwise "Expires: m/d/Y".
 * 2099-12-31 means "never expires" and is not shown. expiration_date is a
 * DATETIME, so only its date part is compared.
 */
function fsbhoa_zebra_card_line3( $cardholder ) {
    $title = trim( $cardholder['title'] ?? '' );
    if ( '' !== $title ) {
        return $title;
    }

    $expiry = trim( (string) ( $cardholder['card_expiry_date'] ?? '' ) );
    if ( '' === $expiry || '2099-12-31' === substr( $expiry, 0, 10 ) ) {
        return '';
    }
    $timestamp = strtotime( $expiry );
    return $timestamp ? 'Expires: ' . date( 'm/d/Y', $timestamp ) : '';
}

/**
 * Renders just the physical 2.125" x 3.375" Zebra ID Badge.
 * Used by the Archive detail hook.
 */
function fsbhoa_zebra_render_badge_only( $cardholder_id, $cardholder = null ) {
    if ( empty( $cardholder ) && ! empty( $cardholder_id ) ) {
        global $wpdb;
        $cardholder = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, cred.expiration_date AS card_expiry_date 
             FROM ac_cardholders c 
             LEFT JOIN ac_credentials cred ON c.id = cred.cardholder_id AND cred.credential_type = 'MIFARE_BADGE'
             WHERE c.id = %d",
            $cardholder_id
        ), ARRAY_A );
    }

    if ( empty( $cardholder ) ) {
        return;
    }

    $first_name = trim( $cardholder['first_name'] ?? '' );
    $last_name  = trim( $cardholder['last_name'] ?? '' );
    $title      = trim( $cardholder['title'] ?? '' );
    $photo_src  = ! empty( $cardholder['photo'] ) ? 'data:image/jpeg;base64,' . base64_encode( $cardholder['photo'] ) : '';

    $subtitle_text = fsbhoa_zebra_card_line3( $cardholder );
    ?>
    <style>
        .fsbhoa-id-card-box {
            width: 2.125in;
            height: 3.375in;
            border: 1px solid #333;
            padding: 6px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #fff;
            margin: 0 auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            border-radius: 4px;
        }
        .fsbhoa-id-photo-frame {
            width: 100%;
            aspect-ratio: 375 / 450;
            background-color: #f0f0f0;
            overflow: hidden;
            margin-bottom: 10px;
            border-radius: 2px;
        }
        .fsbhoa-id-photo-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .fsbhoa-id-name-stack {
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .fsbhoa-id-name-stack .card-name {
            font-size: 18px !important;
            font-weight: 700 !important;
            line-height: 1.1 !important;
            margin: 0 !important;
            color: #111;
        }
        .fsbhoa-id-name-stack .card-subtitle {
            font-size: 12px !important;
            color: #555;
            margin: 4px 0 0 0 !important;
            line-height: 1.2 !important;
        }
    </style>

    <div class="fsbhoa-id-card-box">
        <div class="fsbhoa-id-photo-frame">
            <?php if ( ! empty( $photo_src ) ) : ?>
                <img src="<?php echo esc_attr( $photo_src ); ?>" alt="Badge Photo">
            <?php endif; ?>
        </div>
        <div class="fsbhoa-id-name-stack">
            <p class="card-name"><?php echo esc_html( $first_name ); ?></p>
            <p class="card-name"><?php echo esc_html( $last_name ); ?></p>
            <?php if ( ! empty( $subtitle_text ) ) : ?>
                <p class="card-subtitle"><?php echo esc_html( $subtitle_text ); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Renders the full 2-column preview (Badge on left, Details on right) for the Print Page.
 */
function fsbhoa_zebra_render_badge_card( $cardholder_id, $show_paper_print_btn = false ) {
    if ( empty( $cardholder_id ) ) {
        return;
    }

    global $wpdb;
    $cardholder = $wpdb->get_row( $wpdb->prepare( "
        SELECT c.*, p.street_address, cred.expiration_date AS card_expiry_date
        FROM ac_cardholders c
        LEFT JOIN ac_property p ON c.property_id = p.property_id
        LEFT JOIN ac_credentials cred ON c.id = cred.cardholder_id AND cred.credential_type = 'MIFARE_BADGE'
        WHERE c.id = %d
    ", $cardholder_id ), ARRAY_A );

    if ( ! $cardholder ) {
        return;
    }

    $first_name = trim( $cardholder['first_name'] ?? '' );
    $last_name  = trim( $cardholder['last_name'] ?? '' );
    $full_name  = trim( $first_name . ' ' . $last_name );
    $title      = trim( $cardholder['title'] ?? '' );
    $company    = trim( $cardholder['company'] ?? '' );
    $is_vendor  = ( isset( $cardholder['cardholder_type'] ) && 'vendor' === $cardholder['cardholder_type'] );
    ?>
    <style>
        .fsbhoa-badge-preview-wrapper {
            max-width: 900px;
            margin: 1.5em auto;
        }
        .fsbhoa-badge-columns {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }
        .fsbhoa-badge-card-container {
            flex: 0 0 240px;
            text-align: center;
        }
        .fsbhoa-badge-card-container h3,
        .fsbhoa-badge-details-container h3 {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 16px;
        }
        .fsbhoa-badge-details-container {
            flex: 1;
        }
        .fsbhoa-details-box {
            background: #fdfdfd;
            border: 1px solid #ccd0d4;
            padding: 15px 20px;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .fsbhoa-details-box p {
            margin: 0 0 8px;
            font-size: 14px;
            line-height: 1.4;
        }
        .fsbhoa-details-box p:last-child {
            margin-bottom: 0;
        }
        @media (max-width: 600px) {
            .fsbhoa-badge-columns {
                flex-direction: column;
                align-items: center;
            }
            .fsbhoa-badge-details-container {
                width: 100%;
            }
        }
    </style>

    <div class="fsbhoa-badge-preview-wrapper">
        <div class="fsbhoa-badge-columns">

            <!-- Left: ID Badge -->
            <div class="fsbhoa-badge-card-container">
                <h3><?php esc_html_e( 'Card Preview', 'fsbhoa-ac' ); ?></h3>
                <?php fsbhoa_zebra_render_badge_only( $cardholder_id, $cardholder ); ?>
            </div>

            <!-- Right: Details -->
            <div class="fsbhoa-badge-details-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3><?php esc_html_e( 'Cardholder Details', 'fsbhoa-ac' ); ?></h3>
                    <?php if ( $show_paper_print_btn ) : 
                        $report_page = get_page_by_path( 'cardholder-pages' );
                        if ( $report_page ) :
                            $report_url = add_query_arg( array(
                                'action'       => 'fsbhoa_print_report',
                                'selected_ids' => $cardholder_id,
                                '_wpnonce'     => wp_create_nonce( 'fsbhoa_print_report_nonce' ),
                            ), admin_url( 'admin-post.php' ) );
                            ?>
                            <a href="<?php echo esc_url( $report_url ); ?>" target="_blank" class="button button-secondary" style="font-size: 12px;">
                                <?php esc_html_e( 'Print Paper Record', 'fsbhoa-ac' ); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="fsbhoa-details-box">
                    <p><strong><?php esc_html_e( 'Name:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $full_name ); ?></p>
                    <?php if ( ! empty( $title ) ) : ?>
                        <p><strong><?php esc_html_e( 'Title:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $title ); ?></p>
                    <?php endif; ?>
                    <?php if ( $is_vendor && ! empty( $company ) ) : ?>
                        <p><strong><?php esc_html_e( 'Company:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $company ); ?></p>
                    <?php else : ?>
                        <p><strong><?php esc_html_e( 'Address:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $cardholder['street_address'] ?? 'N/A' ); ?></p>
                    <?php endif; ?>
                    <p><strong><?php esc_html_e( 'Phone:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( ! empty( $cardholder['phone'] ) ? $cardholder['phone'] . ' (' . ( $cardholder['phone_type'] ?? 'Mobile' ) . ')' : 'N/A' ); ?></p>
                    <p><strong><?php esc_html_e( 'Email:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( ! empty( $cardholder['email'] ) ? $cardholder['email'] : 'N/A' ); ?></p>
                    <p><strong><?php esc_html_e( 'Type:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $is_vendor ? 'Vendor / Staff' : ( $cardholder['resident_type'] ?? 'Resident' ) ); ?></p>
                </div>
            </div>

        </div>
    </div>
    <?php
}

