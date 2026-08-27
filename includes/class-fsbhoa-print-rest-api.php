<?php
/**
 * Handles internal REST API endpoints for the Print Service.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Print_REST_API {

    private $namespace = 'fsbhoa/v1';

    /**
     * Registers the routes for the print service API.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/print_log_update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_log_callback' ),
            'permission_callback' => array( $this, 'check_internal_api_key' ),
        ) );
    }

    /**
     * Permission callback to verify the internal API key.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if the key is valid, otherwise a WP_Error.
     */
    public function check_internal_api_key( $request ) {
        $stored_token = get_option('fsbhoa_ac_print_api_token');
        $request_token = $request->get_header('X-Internal-API-Key');

        // Ensure tokens are not empty and are strings
        if ( empty($stored_token) || empty($request_token) || !is_string($request_token) ) {
            return false;
        }
        
        // Use hash_equals for a timing-attack-safe comparison
        return hash_equals( $stored_token, $request_token );
    }

    /**
     * Callback to update a print log entry. Called by the Go service.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_log_callback( $request ) {
        global $wpdb;
        $params = $request->get_json_params();

        // Basic validation
        if ( !isset($params['log_id']) || !is_numeric($params['log_id']) || !isset($params['status']) ) {
            return new WP_Error( 'bad_request', 'Missing or invalid required parameters.', array( 'status' => 400 ) );
        }

        $log_id = absint($params['log_id']);
        $status = sanitize_text_field($params['status']);
        $message = isset($params['status_message']) ? sanitize_text_field($params['status_message']) : '';

        $data_to_update = [
            'status' => $status,
            'status_message' => $message
        ];
        $where = ['log_id' => $log_id];
        $format = ['%s', '%s'];
        $where_format = ['%d'];

        $result = $wpdb->update('ac_print_log', $data_to_update, $where, $format, $where_format);

        if ($result === false) {
            error_log('FSBHOA Print API DB Error: ' . $wpdb->last_error);
            return new WP_Error( 'db_error', 'Failed to update print log.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Log updated successfully.'], 200 );
    }
}

