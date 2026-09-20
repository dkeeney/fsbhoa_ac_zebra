<?php
/**
 * Plugin Name: FSBHOA Access Control - Zebra Printer
 * Description: Zebra card printing module for the FSBHOA Access Control system. Connects to the local Go printing service.
 * Version: 1.0.0
 * Author: David E. Keeney
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FSBHOA_ZEBRA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// 1. Load the classes and views
require_once FSBHOA_ZEBRA_PLUGIN_DIR . 'includes/class-fsbhoa-print-actions.php';
require_once FSBHOA_ZEBRA_PLUGIN_DIR . 'includes/class-fsbhoa-print-rest-api.php';
require_once FSBHOA_ZEBRA_PLUGIN_DIR . 'includes/views/view-print-card.php';
require_once FSBHOA_ZEBRA_PLUGIN_DIR . 'includes/class-fsbhoa-zebra-settings.php';
require_once FSBHOA_ZEBRA_PLUGIN_DIR . 'includes/views/view-badge-preview-card.php';

// 2. Initialize the plugin components immediately
new Fsbhoa_Zebra_Settings();
new Fsbhoa_Print_Actions();

// 3. Register REST API endpoints
function fsbhoa_zebra_register_rest() {
    $print_api = new Fsbhoa_Print_REST_API();
    $print_api->register_routes();
}
/**
 * Register the Zebra Card Printing shortcode
 */
function fsbhoa_zebra_register_shortcodes() {
    add_shortcode( 'fsbhoa_print_card', 'fsbhoa_zebra_render_print_card_shortcode' );
}
add_action( 'init', 'fsbhoa_zebra_register_shortcodes' );
add_action( 'rest_api_init', 'fsbhoa_zebra_register_rest' );
add_action( 'fsbhoa_render_card_preview', 'fsbhoa_zebra_render_badge_only', 10, 2 );

/**
 * Enqueue Zebra Printer assets on the card print page
 */
function fsbhoa_zebra_enqueue_assets() {
    global $post;

    // Check if we are on the frontend print page OR the wp-admin print page
    $is_frontend_print = ( is_a( $post, 'WP_Post' ) && $post->post_name === 'print-photo-id' )
                         || ( isset( $_GET['action'] ) && $_GET['action'] === 'print_card' );
    $is_admin_print    = ( isset( $_GET['page'] ) && $_GET['page'] === 'fsbhoa_print_card' );

    if ( ! $is_frontend_print && ! $is_admin_print ) {
        return;
    }

    $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;

    // Resolve dynamic return URL based on cardholder_type
    $return_url = home_url( '/cardholder/' );

    if ( $cardholder_id > 0 ) {
        global $wpdb;
        $type = $wpdb->get_var( $wpdb->prepare(
            "SELECT cardholder_type FROM ac_cardholders WHERE id = %d LIMIT 1",
            $cardholder_id
        ) );

        if ( 'vendor' === $type ) {
            $return_url = home_url( '/vendors/' );
        }
    }

    // Force a fresh file timestamp to bust browser cache
    $css_path = plugin_dir_path( __FILE__ ) . 'assets/css/fsbhoa-print-styles.css';
    $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0';

    $js_path  = plugin_dir_path( __FILE__ ) . 'assets/js/fsbhoa-print-workflow.js';
    $js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0';

    wp_enqueue_style(
        'fsbhoa-print-styles',
        plugin_dir_url( __FILE__ ) . 'assets/css/fsbhoa-print-styles.css',
        array(),
        $css_ver
    );

    wp_enqueue_script(
        'fsbhoa-print-workflow',
        plugin_dir_url( __FILE__ ) . 'assets/js/fsbhoa-print-workflow.js',
        array( 'jquery' ),
        $js_ver,
        true
    );

    wp_localize_script( 'fsbhoa-print-workflow', 'fsbhoa_print_vars', array(
        'ajax_url'            => admin_url( 'admin-ajax.php' ),
        'nonce'               => wp_create_nonce( 'fsbhoa_print_card_nonce' ),
        'cardholder_page_url' => $return_url,
    ) );
}
add_action( 'wp_enqueue_scripts', 'fsbhoa_zebra_enqueue_assets' );
add_action( 'admin_enqueue_scripts', 'fsbhoa_zebra_enqueue_assets' );

/**
 * Shortcode callback: renders the Zebra card print workflow view
 */
function fsbhoa_zebra_render_print_card_shortcode() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '<p>' . esc_html__( 'Unauthorized access.', 'fsbhoa-ac' ) . '</p>';
    }

    ob_start();
    if ( function_exists( 'fsbhoa_render_printable_card_view' ) ) {
        fsbhoa_render_printable_card_view();
    }
    return ob_get_clean();
}


/**
 * Register the Print Service with the Core's System Status Dashboard
 */
function fsbhoa_zebra_register_system_service( $services ) {
    $services['fsbhoa_printer'] = 'Print Service';
    return $services;
}
add_filter( 'fsbhoa_system_services', 'fsbhoa_zebra_register_system_service' );

