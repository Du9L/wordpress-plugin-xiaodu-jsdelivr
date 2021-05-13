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

function xiaodu_jsdelivr_options_init() {
    $page_name = XIAODU_JSDELIVR_OPTIONS_PAGE_NAME;
    // Register a new setting
    register_setting( $page_name, XiaoduJsdelivrOptions::$options_key );

    // Register a new section
    $status_section_name = 'xiaodu_jsdelivr_status';
    add_settings_section(
        'xiaodu_jsdelivr_status',
        'Status',
        'xiaodu_jsdelivr_options_status_section_cb',
        $page_name
    );

    $default_section_name = 'xiaodu_jsdelivr_options';
    add_settings_section(
        $default_section_name,
        'Options',
        'xiaodu_jsdelivr_options_default_section_cb',
        $page_name
    );

    // Register a new field
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
}

add_action('admin_init', 'xiaodu_jsdelivr_options_init');

function xiaodu_jsdelivr_options_default_section_cb( $args ) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        var_dump(get_option(XiaoduJsdelivrOptions::$options_key));
    }
}

function xiaodu_jsdelivr_options_status_section_cb( $args ) {

}

function xiaodu_jsdelivr_status_data_cb( $args ) {
    $data = get_option('xiaodu_jsdelivr_data');
    $data_size = is_array($data) ? count($data) : 0;
    echo "<p>Scan data size: $data_size ";
    submit_button(__('Clear data'), 'small', XiaoduJsdelivrOptions::$options_key . '[clear_data]', false);
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
    submit_button(__('Start new scan'), 'small', XiaoduJsdelivrOptions::$options_key . '[scan_now]', false);
    echo '</p>';
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
            submit_button( __( 'Save Settings' ) );
            ?>
        </form>
    </div>
    <?php
}

function xiaodu_jsdelivr_options_sanitize_filter( $value ) {
    if (isset($value['clear_data'])) {
        unset($value['clear_data']);
        delete_option('xiaodu_jsdelivr_data');
        add_settings_error('xiaodu_jsdelivr_messages', 'clear_data', __('Data cleared...'), 'info');
    }
    if (isset($value['scan_now'])) {
        unset($value['scan_now']);
        xiaodu_jsdelivr_reschedule(true, 0);
        add_settings_error('xiaodu_jsdelivr_messages', 'scan_now', __('New scan will start soon...'), 'info');
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