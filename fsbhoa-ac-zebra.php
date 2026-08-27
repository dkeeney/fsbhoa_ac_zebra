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

// 2. Initialize the plugin components immediately
new Fsbhoa_Zebra_Settings();
new Fsbhoa_Print_Actions();

// 3. Register REST API endpoints
function fsbhoa_zebra_register_rest() {
    $print_api = new Fsbhoa_Print_REST_API();
    $print_api->register_routes();
}
add_action( 'rest_api_init', 'fsbhoa_zebra_register_rest' );

/**
 * Enqueue Zebra Printer assets on the card print page
 */
function fsbhoa_zebra_enqueue_assets( $hook ) {
    if ( isset($_GET['page']) && $_GET['page'] === 'fsbhoa_print_card' ) {
        wp_enqueue_style( 'fsbhoa-print-styles', plugin_dir_url( __FILE__ ) . 'assets/css/fsbhoa-print-styles.css', [], '1.0.0' );
        wp_enqueue_script( 'fsbhoa-print-workflow', plugin_dir_url( __FILE__ ) . 'assets/js/fsbhoa-print-workflow.js', ['jquery'], '1.0.0', true );

        wp_localize_script( 'fsbhoa-print-workflow', 'fsbhoa_print_vars', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'fsbhoa_print_card_nonce' )
        ]);
    }
}
add_action( 'admin_enqueue_scripts', 'fsbhoa_zebra_enqueue_assets' );

/**
 * Register the Print Service with the Core's System Status Dashboard
 */
function fsbhoa_zebra_register_system_service( $services ) {
    $services['fsbhoa_printer'] = 'Print Service';
    return $services;
}
add_filter( 'fsbhoa_system_services', 'fsbhoa_zebra_register_system_service' );

