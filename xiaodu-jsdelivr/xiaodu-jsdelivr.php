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
/**
 * Plugin Name: xiaodu-jsdelivr
 * Plugin URI: https://t.du9l.com/2021/04/xiaodu-jsdelivr-wordpress-plugin-scan-serve-static-files-cdn/
 * Description: Scan and serve static files from jsDelivr CDN <https://jsdelivr.com>.
 * Version: 1.0
 * Requires at least: 5.4
 * Requires PHP: 7.2
 * Author: Xiaodu @ Du9L.com
 * Author URI: https://t.du9l.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) { die( 'Invalid request.' ); }

function xiaodu_jsdelivr_debug_log($content, $var=NULL) {
    if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
        return;
    }
    if ($var) {
        $content .= var_export($var, TRUE);
    }
    error_log('[xiaodu_jsdelivr_debug] ' . $content);
}

require_once(plugin_dir_path(__FILE__) . 'scanner.php');
register_activation_hook(__FILE__, 'xiaodu_jsdelivr_activation');
register_deactivation_hook(__FILE__, 'xiaodu_jsdelivr_deactivation');

require_once(plugin_dir_path(__FILE__) . 'replacer.php');
