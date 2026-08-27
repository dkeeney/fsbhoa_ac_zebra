<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Zebra_Settings {
    private const DEFAULT_PRINT_API_TOKEN = 'eZdaPzde/0JGMirn6DV4VPSErRerexAiqZBCQj/T3Vg=';
    private $print_service_config_path = '/var/lib/fsbhoa/zebra_print_service.json';
    private $parent_slug = 'fsbhoa_ac_main_menu';
    private $page_slug = 'fsbhoa_print_service_settings';
    private $option_group = 'fsbhoa_print_service_options';

    public function __construct() {
        if (!get_option('fsbhoa_ac_print_api_token')) {
            update_option('fsbhoa_ac_print_api_token', self::DEFAULT_PRINT_API_TOKEN);
        }

        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_fsbhoa_save_print_settings', array( $this, 'ajax_save_print_settings' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            $this->parent_slug,
            'Print Service Config',
            'Print Service',
            'manage_options',
            $this->page_slug,
            array( $this, 'render_settings_page' ),
            15
        );
    }

    public function enqueue_assets($hook) {
        // Only load the media uploader on our specific settings page
        if (strpos($hook, $this->page_slug) !== false) {
            wp_enqueue_media();
        }
    }

    public function register_settings() {
        add_settings_section('fsbhoa_print_service_section', 'Print Service Settings', null, $this->page_slug);
        add_settings_field('fsbhoa_ac_print_port_field', 'Zebra Print Service Port', array($this, 'render_field_callback'), $this->page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_port', 'type' => 'number', 'default' => 8081]);
        add_settings_field('fsbhoa_ac_printer_name_field', 'CUPS Printer Name', array($this, 'render_field_callback'), $this->page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_printer_name', 'type' => 'text', 'default' => 'Zebra-ZC300', 'desc' => 'The exact name of the printer queue in CUPS.']);
        add_settings_field('fsbhoa_ac_print_debug_mode_field', 'Debug Mode (Dry Run)', array($this, 'render_field_callback'), $this->page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_debug_mode', 'type' => 'checkbox', 'desc' => 'If checked, the service will only generate the image file in /var/tmp and will NOT send it to the printer.']);
        add_settings_field('fsbhoa_ac_card_back_url_field', 'Card Back Logo', array($this, 'render_media_uploader_field'), $this->page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_card_back_url', 'desc' => 'Select an image from the Media Library for the back of the card.']);
        add_settings_field('fsbhoa_ac_print_template_path_field', 'Print Template JSON Path', array($this, 'render_field_callback'), $this->page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_template_path', 'type' => 'text', 'desc' => 'Full server path to the print template JSON file.']);

        register_setting($this->option_group, 'fsbhoa_ac_print_port', 'absint');
        register_setting($this->option_group, 'fsbhoa_ac_printer_name', 'sanitize_text_field');
        register_setting($this->option_group, 'fsbhoa_ac_print_debug_mode', 'sanitize_text_field');
        register_setting($this->option_group, 'fsbhoa_ac_card_back_url', 'esc_url_raw');
        register_setting($this->option_group, 'fsbhoa_ac_print_template_path', 'sanitize_text_field');
        register_setting($this->option_group, 'fsbhoa_ac_print_api_token', 'sanitize_text_field');
    }

    public function render_field_callback($args) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option($id, $default);

        if ($type === 'checkbox') {
            echo "<input type='checkbox' name='{$id}' value='on' " . checked($value, 'on', false) . " />";
        } else {
            echo "<input type='{$type}' name='{$id}' value='" . esc_attr($value) . "' class='regular-text' />";
        }
        if ($desc) { echo "<p class='description'>" . esc_html($desc) . "</p>"; }
    }

    public function render_media_uploader_field($args) {
        $id    = $args['id'];
        $value = get_option($id, '');
        $desc  = $args['desc'] ?? '';
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <button type="button" class="button fsbhoa-media-upload-btn" data-target="<?php echo esc_attr($id); ?>">Upload/Select Image</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-print-settings-page">
            <h1>Zebra Print Service Configuration</h1>
             <?php do_settings_sections($this->page_slug); ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-print-settings-button" class="button button-primary">Save Print Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Media Uploader Logic
            $('.fsbhoa-media-upload-btn').click(function(e) {
                e.preventDefault();
                var button = $(this);
                var targetId = button.data('target');
                var customUploader = wp.media({
                    title: 'Select Image',
                    button: { text: 'Use this Image' },
                    multiple: false
                }).on('select', function() {
                    var attachment = customUploader.state().get('selection').first().toJSON();
                    $('#' + targetId).val(attachment.url);
                }).open();
            });

            // AJAX Save Logic
            $('#fsbhoa-save-print-settings-button').click(function() {
                var btn = $(this);
                var feedback = $('#fsbhoa-save-feedback');
                var options = [];
                
                $('#fsbhoa-print-settings-page input').each(function() {
                    var type = $(this).attr('type');
                    if (type === 'checkbox') {
                        options.push({ name: $(this).attr('name'), value: $(this).is(':checked') ? 'on' : 'off' });
                    } else if (type !== 'button') {
                        options.push({ name: $(this).attr('name'), value: $(this).val() });
                    }
                });

                btn.prop('disabled', true);
                feedback.text('Saving...').css('color', '#000').show();

                $.post(ajaxurl, {
                    action: 'fsbhoa_save_print_settings',
                    nonce: '<?php echo wp_create_nonce("fsbhoa_print_settings_nonce"); ?>',
                    options: options
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        feedback.text(response.data).css('color', 'green');
                    } else {
                        feedback.text('Error: ' + response.data).css('color', 'red');
                    }
                    setTimeout(function() { feedback.fadeOut(); }, 3000);
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_save_print_settings() {
        check_ajax_referer('fsbhoa_print_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permission denied.', 403); }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }

        $this->write_json_config();
        wp_send_json_success('Zebra Print Service settings saved.');
    }

    private function write_json_config() {
        $print_config = [
            'port'         => (int) get_option('fsbhoa_ac_print_port', 8081),
            'api_url'      => get_site_url() . '/wp-json/fsbhoa/v1/print_log_update',
            'api_token'    => get_option('fsbhoa_ac_print_api_token', ''),
            'printer_name' => get_option('fsbhoa_ac_printer_name', 'Zebra-ZC300'),
            'debug_mode'   => (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on'),
        ];
        
        $json_data = json_encode($print_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $config_dir = dirname($this->print_service_config_path);
        
        if (!is_dir($config_dir)) { mkdir($config_dir, 0755, true); }
        file_put_contents($this->print_service_config_path, $json_data);
    }
}

