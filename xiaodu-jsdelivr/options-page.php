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
    $default_section_name = 'xiaodu_jsdelivr_options';
    add_settings_section(
        $default_section_name,
        'Options',
        'xiaodu_jsdelivr_options_default_section_cb',
        $page_name
    );

    // Register a new field
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
}

add_action('admin_init', 'xiaodu_jsdelivr_options_init');

function xiaodu_jsdelivr_options_default_section_cb( $args ) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        var_dump(get_option(XiaoduJsdelivrOptions::$options_key));
    }
}

function xiaodu_jsdelivr_option_boolean_cb( $args ) {
    // Get the value of the setting we've registered with register_setting()
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

function xiaodu_jsdelivr_options_page() {
    // check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // check if the user have submitted the settings
    // WordPress will add the "settings-updated" $_GET parameter to the url
    if ( isset( $_GET['settings-updated'] ) ) {
        $options = XiaoduJsdelivrOptions::inst();
        $options->reload();
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
