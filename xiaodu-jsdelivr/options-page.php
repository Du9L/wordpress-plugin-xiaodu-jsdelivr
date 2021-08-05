<?php
/*
Copyright (C) 2021  Xiaodu @ Du9L.com

This file is part of xiaodu-jsdelivr.

xiaodu-jsdelivr is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

xiaodu-jsdelivr is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with xiaodu-jsdelivr.  If not, see <https://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) { die( 'Invalid request.' ); }

/**
 * Options page in admin area.
 */

define('XIAODU_JSDELIVR_OPTIONS_PAGE_NAME', 'xiaodu_jsdelivr');

function xiaodu_jsdelivr_options_page_register() {
    add_options_page(
        'xiaodu-jsdelivr',
        'xiaodu-jsdelivr',
        'manage_options',
        XIAODU_JSDELIVR_OPTIONS_PAGE_NAME,
        'xiaodu_jsdelivr_options_page'
    );
}

add_action('admin_menu', 'xiaodu_jsdelivr_options_page_register');

function xiaodu_jsdelivr_add_plugin_link( $links, $file ) {
    global $xiaodu_jsdelivr_plugin_dir_path;
    if ( $file === 'xiaodu-jsdelivr/xiaodu-jsdelivr.php' ) {
        $url = admin_url( 'options-general.php?page=' . XIAODU_JSDELIVR_OPTIONS_PAGE_NAME );
        $links = (array) $links;
        $links[] = sprintf( '<a href="%s">%s</a>', esc_url($url), __( 'Settings' ) );
    }
    return $links;
}

add_filter( 'plugin_action_links', 'xiaodu_jsdelivr_add_plugin_link', 10, 2 );

function xiaodu_jsdelivr_options_init() {
    $page_name = XIAODU_JSDELIVR_OPTIONS_PAGE_NAME;
    // Register a new setting
    register_setting( $page_name, XiaoduJsdelivrOptions::$options_key );

    // Status section
    $status_section_name = 'xiaodu_jsdelivr_status';
    add_settings_section(
        $status_section_name,
        'Scan Status',
        'xiaodu_jsdelivr_options_status_section_cb',
        $page_name
    );

    add_settings_field(
        '_status_data',
        'Data',
        'xiaodu_jsdelivr_status_data_cb',
        $page_name,
        $status_section_name
    );

    add_settings_field(
        '_status_scan',
        'Scanner',
        'xiaodu_jsdelivr_status_scanner_cb',
        $page_name,
        $status_section_name
    );

    add_settings_field(
        '_status_scan_result',
        'Last scan result',
        'xiaodu_jsdelivr_status_scan_result_cb',
        $page_name,
        $status_section_name
    );

    add_settings_field(
        '_status_scan_fail',
        'Last scan failed paths',
        'xiaodu_jsdelivr_status_scan_fail_cb',
        $page_name,
        $status_section_name
    );

    // Options section
    $default_section_name = 'xiaodu_jsdelivr_options';
    add_settings_section(
        $default_section_name,
        'Plugin Options',
        'xiaodu_jsdelivr_options_default_section_cb',
        $page_name
    );

    add_settings_field(
        'scanner_always_hash',
        'Always hash in Scanner',
        'xiaodu_jsdelivr_option_boolean_cb',
        $page_name,
        $default_section_name,
        array(
            'label_for' => 'scanner_always_hash',
            'desc' => 'Always calculate hash in Scanner (Default: off, trust file modification time and size)',
        )
    );

    $sys_time_limit = function_exists( 'ini_get' ) ? intval(ini_get( 'max_execution_time' )) : 0;
    add_settings_field(
        'scanner_timeout',
        'Scanner timeout (seconds)',
        'xiaodu_jsdelivr_option_integer_cb',
        $page_name,
        $default_section_name,
        array(
            'label_for' => 'scanner_timeout',
            'desc' => "Timeout in seconds for single Scanner run (Default: 0, use system time limit [$sys_time_limit])",
        )
    );

    add_settings_field(
        'scanner_randomized',
        'Randomized scan order',
        'xiaodu_jsdelivr_option_boolean_cb',
        $page_name,
        $default_section_name,
        array(
            'label_for' => 'scanner_randomized',
            'desc' => 'Scan directory content in random order (Default: off, use filesystem default order)',
        )
    );

    // API section
    $api_section_name = 'xiaodu_jsdelivr_api';
    add_settings_section(
        $api_section_name,
        'Scan API',
        'xiaodu_jsdelivr_options_api_section_cb',
        $page_name
    );

    add_settings_field(
        'e_api_enabled',
        'Enable API',
        'xiaodu_jsdelivr_option_boolean_cb',
        $page_name,
        $api_section_name,
        array(
            'label_for' => 'e_api_enabled',
            'desc' => 'Enable scan API service'
        )
    );

    add_settings_field(
        'e_api_key',
        'API Key',
        'xiaodu_jsdelivr_option_string_cb',
        $page_name,
        $api_section_name,
        array(
            'label_for' => 'e_api_key',
            'desc' => 'Your API key generated in the manager',
        )
    );

    add_settings_field(
        'e_api_secret',
        'API Secret',
        'xiaodu_jsdelivr_option_string_cb',
        $page_name,
        $api_section_name,
        array(
            'label_for' => 'e_api_secret',
            'desc' => 'Your API secret generated in the manager',
        )
    );

    add_settings_field(
        'e_api_disable_themes',
        'Don\'t upload themes data',
        'xiaodu_jsdelivr_option_boolean_cb',
        $page_name,
        $api_section_name,
        array(
            'label_for' => 'e_api_disable_themes',
            'desc' => 'When checked, theme names and versions will NOT be sent to API service for matching.',
        )
    );

    add_settings_field(
        '_e_api_result',
        'API Access Result',
        'xiaodu_jsdelivr_api_result_cb',
        $page_name,
        $api_section_name
    );
}

add_action('admin_init', 'xiaodu_jsdelivr_options_init');

function xiaodu_jsdelivr_options_default_section_cb( $args ) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        var_dump(get_option(XiaoduJsdelivrOptions::$options_key));
    }
}

function xiaodu_jsdelivr_options_status_section_cb( $args ) {

}

function xiaodu_jsdelivr_options_api_section_cb( $args ) {
    echo <<<EOF
    <p>
    Scan API is an <b>optional</b> hosted service that uses pre-calculated data to assist and accelerate the scanning process.<br />
    If you want to use this service, you can generate an API key and secret pair from the
    <a href="https://s.du9l.com/xjapi" target="_blank" rel="external noopener"><b>API Manager</b></a>
    and fill it in below.<br />
    Details can be found in <a href="https://s.du9l.com/RKimP" target="_blank" rel="external noopener">this blog post</a>.<br />
    <em>As of version 1.3, the plugin only retrieves and handles results from the API service about WordPress base files.
    The plugin itself can still scan plugin and theme files directly.</em>
    </p>
    <h3>&gt;&gt; Data usage and privacy</h3>
    <p>
    When you use the API service, you agree that your website's URL and WordPress,
    plugins and themes information will be uploaded and logged by the service provider.<br />
    If you keep this feature disabled, no requests will be made to the service.
    </p>
    <h3>&gt;&gt; Options</h3>
EOF;
}

function xiaodu_jsdelivr_status_data_cb( $args ) {
    $data = get_option('xiaodu_jsdelivr_data');
    $data_size = is_array($data) ? count($data) : 0;
    echo "<p>Scan data size: $data_size ";
    if (defined('WP_DEBUG') && WP_DEBUG) {
        submit_button('Clear scan results', 'small', XiaoduJsdelivrOptions::$options_key . '[clear_data]', false);
        submit_button('Clear api cache', 'small', XiaoduJsdelivrOptions::$options_key . '[clear_api]', false);
    }
    echo '</p>';
}

function xiaodu_jsdelivr_status_scanner_cb( $args ) {
    $scan_lock = get_transient('xiaodu_jsdelivr_lock');
    if ($scan_lock === false) {
        $next_scan = wp_next_scheduled(XIAODU_JSDELIVR_CRON_HOOK);
        $time_to_next_scan = $next_scan === false ? 'ERROR' : intval($next_scan - time());
        echo "<p>IDLE - Time to next scan: $time_to_next_scan ";
        if (is_int($time_to_next_scan) && $time_to_next_scan < -10) {
            echo ' <strong>(WP-Cron not working?)</strong> ';
        }
    } else {
        $cur_scan = floatval($scan_lock);
        $cur_scan_time = intval(time() - $cur_scan);
        echo "<p>SCANNING - Time since current scan started: $cur_scan_time ";
    }
    submit_button('Start new scan', 'small', XiaoduJsdelivrOptions::$options_key . '[scan_now]', false);
    echo '</p>';
}

function xiaodu_jsdelivr_status_scan_result_cb( $args ) {
    $data = get_option('xiaodu_jsdelivr_data');
    if (is_array($data) && isset($data['*result*'], $data['*result*']['is_timeout'])) {
        $result = $data['*result*']['is_timeout'] ? 'Timed out' : 'Finished in time!';
    } else {
        $result = 'Unknown';
    }
    echo "<p>$result</p>";
}

function xiaodu_jsdelivr_status_scan_fail_cb( $args ) {
    $data = get_option('xiaodu_jsdelivr_data');
    if (is_array($data) && isset($data['*result*'], $data['*result*']['fail_records'])) {
        $fail_records = $data['*result*']['fail_records'];
        $fail_count = count($fail_records);
        if ($fail_count == 0) {
            echo "<p>No failures!</p>";
            return;
        }
        echo "<p>Total: $fail_count</p><ul>";
        $display_limit = 10;
        foreach ($fail_records as $path) {
            if ($display_limit == 0) {
                echo '<li>...</li>';
                break;
            }
            echo "<li>" . esc_html($path) . "</li>";
            $display_limit --;
        }
        echo "</ul>";
    } else {
        echo "<p>Unknown</p>";
    }
}

function xiaodu_jsdelivr_api_result_cb( $args ) {
    $result = get_transient('xiaodu_jsdelivr_api_result');
    if (!$result) {
        $result_text = 'Unknown (not accessed recently)';
    } else {
        $time_diff = time() - $result['time'];
        $success = $result['success'] ? 'Success' : 'Failed';
        $result_text = "$time_diff seconds ago - $success";
        if (!$result['success']) {
            $result_text .= "; Code: {$result['code']}; Error: {$result['error']}";
        }
    }
    echo "<p>$result_text</p>";
}

function xiaodu_jsdelivr_option_boolean_cb( $args ) {
    $options_key = XiaoduJsdelivrOptions::$options_key;
    $options = XiaoduJsdelivrOptions::inst();
    $option_name = $args['label_for'];
    $option_name_esc_attr = esc_attr($option_name);
    $current_value = property_exists($options, $option_name) ? $options->{$option_name} : false;
    ?>
    <input
        type="checkbox" value="1"
        id="<?php echo $option_name_esc_attr; ?>"
        name="<?php echo esc_attr( $options_key ); ?>[<?php echo $option_name_esc_attr; ?>]"
        <?php checked($current_value); ?>
    />
    <span class="description">
        <label for="<?php echo $option_name_esc_attr; ?>">
            <?php esc_html_e( $args['desc'] ); ?>
        </label>
    </span>
    <?php
}

function xiaodu_jsdelivr_option_integer_cb( $args ) {
    $options_key = XiaoduJsdelivrOptions::$options_key;
    $options = XiaoduJsdelivrOptions::inst();
    $option_name = $args['label_for'];
    $option_name_esc_attr = esc_attr($option_name);
    $current_value = property_exists($options, $option_name) ? $options->{$option_name} : '';
    ?>
    <input
            type="number" value="<?php echo esc_attr($current_value); ?>"
            id="<?php echo $option_name_esc_attr; ?>"
            name="<?php echo esc_attr( $options_key ); ?>[<?php echo $option_name_esc_attr; ?>]"
    />
    <span class="description">
        <label for="<?php echo $option_name_esc_attr; ?>">
            <?php esc_html_e( $args['desc'] ); ?>
        </label>
    </span>
    <?php
}

function xiaodu_jsdelivr_option_string_cb( $args ) {
    $options_key = XiaoduJsdelivrOptions::$options_key;
    $options = XiaoduJsdelivrOptions::inst();
    $option_name = $args['label_for'];
    $option_name_esc_attr = esc_attr($option_name);
    $current_value = property_exists($options, $option_name) ? $options->{$option_name} : '';
    ?>
    <input
            type="text" value="<?php echo esc_attr($current_value); ?>"
            id="<?php echo $option_name_esc_attr; ?>"
            name="<?php echo esc_attr( $options_key ); ?>[<?php echo $option_name_esc_attr; ?>]"
    />
    <span class="description">
        <label for="<?php echo $option_name_esc_attr; ?>">
            <?php esc_html_e( $args['desc'] ); ?>
        </label>
    </span>
    <?php
}

function xiaodu_jsdelivr_options_page() {
    // check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            // output security fields for the registered setting
            settings_fields( XIAODU_JSDELIVR_OPTIONS_PAGE_NAME );
            // output setting sections and their fields
            do_settings_sections( XIAODU_JSDELIVR_OPTIONS_PAGE_NAME );
            // output save settings button
            submit_button( __( 'Save Changes' ) );
            ?>
        </form>
    </div>
    <?php
}

function xiaodu_jsdelivr_options_sanitize_filter( $value ) {
    if (isset($value['clear_data'])) {
        unset($value['clear_data']);
        delete_option('xiaodu_jsdelivr_data');
        add_settings_error('xiaodu_jsdelivr_messages', 'clear_data', 'Data cleared...', 'info');
    }
    if (isset($value['clear_api'])) {
        unset($value['clear_api']);
        delete_transient('xiaodu_jsdelivr_api_resp');
        delete_transient('xiaodu_jsdelivr_api_result');
        add_settings_error('xiaodu_jsdelivr_messages', 'clear_api', 'API response cleared...', 'info');
    }
    if (isset($value['scan_now'])) {
        unset($value['scan_now']);
        xiaodu_jsdelivr_reschedule(true, 0);
        add_settings_error('xiaodu_jsdelivr_messages', 'scan_now', 'New scan will start soon...', 'info');
    }
    $options = XiaoduJsdelivrOptions::inst();
    $value = $options->sanitize_post_option($value);
    return $value;
}

add_filter('pre_update_option_' . XiaoduJsdelivrOptions::$options_key, 'xiaodu_jsdelivr_options_sanitize_filter');

function xiaodu_jsdelivr_options_update_hook( $value ) {
    $options = XiaoduJsdelivrOptions::inst();
    $options->reload();
}

add_action('update_option_' . XiaoduJsdelivrOptions::$options_key, 'xiaodu_jsdelivr_options_update_hook');
