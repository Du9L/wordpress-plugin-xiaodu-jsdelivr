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
 * Scan and schedule the scanning of static files.
 */

if ( ! defined( 'ABSPATH' ) ) { die( 'Invalid request.' ); }

DEFINE('XIAODU_JSDELIVR_CRON_HOOK', 'xiaodu_jsdelivr_cron');

// Activation and deactivation hooks

function xiaodu_jsdelivr_activation()
{
    wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
    wp_schedule_event(time(), 'daily', XIAODU_JSDELIVR_CRON_HOOK);
}

function xiaodu_jsdelivr_deactivation()
{
    wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
    delete_option('xiaodu_jsdelivr_lock');
    delete_option('xiaodu_jsdelivr_data');
}

// After upgraded, steal the lock and schedule an immediate scan

function _xiaodu_jsdelivr_steal_lock_and_reschedule() {
    delete_option('xiaodu_jsdelivr_lock');
    wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
    wp_schedule_event(time(), 'daily', XIAODU_JSDELIVR_CRON_HOOK);
}

add_action('upgrader_process_complete', '_xiaodu_jsdelivr_on_upgrade', 20, 2);

function _xiaodu_jsdelivr_on_upgrade($wp_upgrader, $hook_extra) {
    if (isset($hook_extra['type']) && in_array($hook_extra['type'], array('core', 'plugin', 'theme'))) {
        _xiaodu_jsdelivr_steal_lock_and_reschedule();
    }
}

// Scanning entry point

add_action(XIAODU_JSDELIVR_CRON_HOOK, '_xiaodu_jsdelivr_scan');

function _xiaodu_jsdelivr_scan_directory($dir, &$options) {
    if ($options['is_timeout'] || time() - $options['start_time'] > 30) {
        _xiaodu_jsdelivr_debug_log("SCAN TIME OUT $dir");
        $options['is_timeout'] = TRUE;
        return;
    }
    _xiaodu_jsdelivr_debug_log("START DIR SCAN $dir ");
    $dir_full_path = ABSPATH . $dir;
    $dir_contents = @scandir($dir_full_path, SCANDIR_SORT_NONE);
    if ($dir_contents === FALSE || !is_array($dir_contents)) {
        error_log("_xiaodu_jsdelivr_scan_directory: Invalid directory, " . $dir_full_path);
        return;
    }
    if (!$dir_contents) {
        return;
    }
    $dir_contents = array_diff($dir_contents, array('.', '..'));
    $old_data = &$options['old_data'];
    $new_data = &$options['new_data'];
    $scan_hints = $options['scan_hints'];
    $version = $options['version'];
    $version_hint = $options['version_hint'];
    foreach ($dir_contents as $name) {
        $full_path = $dir_full_path . '/' . $name;
        $path = $dir . '/' . $name;
        if (is_dir($full_path)) {
            _xiaodu_jsdelivr_scan_directory($path, $options);
            if ($options['is_timeout']) {
                return;
            }
            continue;
        }
        // Filter extension
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('js', 'css'))) {
            continue;
        }
        // Hash file content
        $file_hash = @hash_file('sha256', $full_path);
        if ($file_hash === FALSE) {
            error_log("_xiaodu_jsdelivr_scan_directory: Hash failed, " . $full_path);
            continue;
        }
        // Check if file is unchanged
        if (isset($old_data[$path]) && $file_hash == $old_data[$path]['sha256']) {
            $new_data[$path] = $old_data[$path];
            $new_data[$path]['version'] = $version;
            $new_data[$path]['version_hint'] = $version_hint;
            continue;
        }
        // Scan using hints
        $scan_result = NULL;
        if ($scan_hints) {
            foreach ($scan_hints as $hint => $skip_length) {
                $concat_path = ($skip_length <= 0) ? $path : substr($path, $skip_length);
                $scan_url = $hint . $concat_path;
                $scan_hash = @hash_file('sha256', $scan_url);
                _xiaodu_jsdelivr_debug_log("TRY $path [$file_hash] -> $scan_url [$scan_hash]");
                if ($scan_hash === $file_hash) {
                    $scan_result = $scan_url;
                    break;
                }
            }
        }
        // Fallback using hash
        if ($scan_result === NULL) {
            $api_url = 'https://data.jsdelivr.com/v1/lookup/hash/' . $file_hash;
            $api_res = @file_get_contents($api_url);
            if ($api_res !== FALSE) {
                $api_res = @json_decode($api_res, TRUE, 2);
                if ($api_res !== NULL && isset($api_res['file'])) {
                    if ($api_res['file'][0] != '/') {
                        $api_res['file'] = '/' . $api_res['file'];
                    }
                    $scan_url = "https://cdn.jsdelivr.net/{$api_res['type']}/{$api_res['name']}@{$api_res['version']}{$api_res['file']}";
                    $scan_hash = @hash_file('sha256', $scan_url);
                    _xiaodu_jsdelivr_debug_log("LOOKUP $path [$file_hash] -> $scan_url [$scan_hash]");
                    if ($scan_hash === $file_hash) {
                        $scan_result = $scan_url;
                    }
                }
            }
        }
        // Save success result
        if ($scan_result !== NULL) {
            $new_data[$path] = array(
                'version' => $version, 
                'version_hint' => $version_hint,
                'sha256' => $file_hash, 
                'url' => $scan_result
            );
            _xiaodu_jsdelivr_debug_log("FOUND MATCH: $path -> $scan_result");
        }
    }
}

function _xiaodu_jsdelivr_scan() {
    // Check and set lock
    $now = time();
    _xiaodu_jsdelivr_debug_log("START SCAN $now");
    $lock = get_option('xiaodu_jsdelivr_lock');
    if ($lock !== FALSE && $now - intval($lock) < 86400) {
        error_log("_xiaodu_jsdelivr_scan: Lock not expired, " . print_r($lock, TRUE));
        return;
    }
    if (add_option('xiaodu_jsdelivr_lock', $now) === FALSE) {
        error_log("_xiaodu_jsdelivr_scan: Lock failed");
        return;
    }
    // Read existing data
    $old_data = get_option('xiaodu_jsdelivr_data');
    if (!is_array($old_data)) {
        if ($old_data !== FALSE) {
            error_log("_xiaodu_jsdelivr_scan: Invalid old data, " . print_r($old_data, TRUE));
        }
        $old_data = array();
    }
    $new_data = array();

    // Scan base WordPress files
    global $wp_version;
    $jsdelivr_wp_hint = "https://cdn.jsdelivr.net/gh/WordPress/WordPress@{$wp_version}/";
    $options = array(
        "old_data" => &$old_data,
        "new_data" => &$new_data,
        "scan_hints" => array($jsdelivr_wp_hint => 0),
        "version" => $wp_version,
        "version_hint" => NULL,
        "start_time" => $now,
        "is_timeout" => FALSE,
    );
    $scan_dir_list = array(
        'wp-admin',
        'wp-includes',
    );
    foreach ($scan_dir_list as $dir) {
        _xiaodu_jsdelivr_scan_directory($dir, $options);
        if ($options['is_timeout']) {
            break;
        }
    }

    // Scan plugins
    if (!$options['is_timeout']) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $all_plugins = get_plugins();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (!isset($plugin_data['Version'])) {
                error_log("_xiaodu_jsdelivr_scan: Invalid plugin data, " . print_r($plugin_file, TRUE));
                continue;
            }
            $first_slash = strpos($plugin_file, '/');
            if ($first_slash === FALSE) {
                continue;
            }
            // Add path hint
            $plugin_dir_name = substr($plugin_file, 0, $first_slash);
            $plugin_dir = 'wp-content/plugins/' . $plugin_dir_name;
            if (isset($new_data[$plugin_dir])) {
                error_log("_xiaodu_jsdelivr_scan: Repeated plugin dir, " . $plugin_dir);
                continue;
            }
            $plugin_version = $plugin_data['Version'];
            $new_data['^' . $plugin_dir] = array(
                "hint_file" => 'wp-content/plugins/' . $plugin_file, "version" => $plugin_version,
            );
            // Scan folder
            $jsdelivr_plugin_hint = "https://cdn.jsdelivr.net/wp/plugins/{$plugin_dir_name}/tags/{$plugin_version}/";
            $options['version'] = $plugin_version;
            $options['version_hint'] = strlen($plugin_dir);
            $options['scan_hints'] = array(
                $jsdelivr_plugin_hint => strlen($plugin_dir) + 1, 
                $jsdelivr_wp_hint => 0,
            );
            _xiaodu_jsdelivr_scan_directory($plugin_dir, $options);
            if ($options['is_timeout']) {
                break;
            }
        }
    }

    // Scan themes
    if (!$options['is_timeout']) {
        $all_themes = wp_get_themes(array("errors" => FALSE));
        foreach ($all_themes as $theme_name => $wp_theme) {
            // Theme must be located inside the WordPress folder
            $theme_root = $wp_theme->get_theme_root();
            if (substr($theme_root, 0, strlen(ABSPATH)) !== ABSPATH) {
                continue;
            }
            // Add path hint
            $theme_root_dir = substr($theme_root, strlen(ABSPATH));
            $theme_dir = $theme_root_dir . '/' . $wp_theme->get_stylesheet();
            if (isset($new_data[$theme_dir])) {
                error_log("_xiaodu_jsdelivr_scan: Repeated theme dir, " . $theme_dir);
                continue;
            }
            $theme_file = $theme_dir . '/style.css';
            $theme_version = $wp_theme->version;
            $new_data['^' . $theme_dir] = array(
                "hint_file" => $theme_file, "version" => $theme_version,
            );
            // Scan folder
            $theme_basename = basename($theme_dir);
            $jsdelivr_theme_hint = "https://cdn.jsdelivr.net/wp/themes/{$theme_basename}/{$theme_version}/";
            $options['version'] = $theme_version;
            $options['version_hint'] = strlen($theme_dir);
            $options['scan_hints'] = array(
                $jsdelivr_theme_hint => strlen($theme_dir) + 1,
                $jsdelivr_wp_hint => 0,
            );
            _xiaodu_jsdelivr_scan_directory($theme_dir, $options);
            if ($options['is_timeout']) {
                break;
            }
        }
    }

    // Save result and release lock
    $lock = get_option('xiaodu_jsdelivr_lock');
    if ($lock !== $now) {
        error_log("_xiaodu_jsdelivr_scan: Lock was stolen, " . print_r($lock, TRUE));
        return;
    }
    if ($options['is_timeout']) {
        $new_data = array_replace($old_data, $new_data);
    }
    update_option('xiaodu_jsdelivr_data', $new_data);
    delete_option('xiaodu_jsdelivr_lock');
    _xiaodu_jsdelivr_debug_log("FINISH SCAN $now");
    if ($options['is_timeout']) {
        wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
        wp_schedule_event(time() + 10, 'daily', XIAODU_JSDELIVR_CRON_HOOK);
        _xiaodu_jsdelivr_debug_log("THIS SCAN TIMED OUT, WILL SCAN AGAIN $now");
    }
}
