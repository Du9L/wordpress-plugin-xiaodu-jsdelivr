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

function xiaodu_jsdelivr_reschedule($steal_lock = false, $delay = 0) {
    if ($steal_lock) {
        delete_transient('xiaodu_jsdelivr_lock');
    }
    wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
    $next_scan = time() + $delay;
    wp_schedule_event($next_scan, 'daily', XIAODU_JSDELIVR_CRON_HOOK);
    return $next_scan;
}

function xiaodu_jsdelivr_activation()
{
    xiaodu_jsdelivr_reschedule();
}

function xiaodu_jsdelivr_deactivation()
{
    wp_clear_scheduled_hook(XIAODU_JSDELIVR_CRON_HOOK);
}

function xiaodu_jsdelivr_uninstall() {
    delete_transient('xiaodu_jsdelivr_lock');
    delete_option('xiaodu_jsdelivr_data');
    delete_option(XiaoduJsdelivrOptions::$options_key);
}

// After upgraded, steal the lock and schedule an immediate scan

add_action('upgrader_process_complete', 'xiaodu_jsdelivr_on_upgrade', 20, 2);

function xiaodu_jsdelivr_on_upgrade($wp_upgrader, $hook_extra) {
    if (isset($hook_extra['type']) && in_array($hook_extra['type'], array('core', 'plugin', 'theme'))) {
        xiaodu_jsdelivr_reschedule(true);
    }
}

// Scanning entry point

add_action(XIAODU_JSDELIVR_CRON_HOOK, 'xiaodu_jsdelivr_scan');

function xiaodu_jsdelivr_check_scan_timeout(&$options) {
    if ($options['is_timeout'] || (microtime(true) - $options['start_time'] > $options['timeout'])) {
        $options['is_timeout'] = true;
        return true;
    }
    return false;
}

function xiaodu_jsdelivr_hash_remote_url(&$options, $url) {
    $content = @file_get_contents($url, false, $options['stream_ctx']);
    if ($content === false) {
        error_log("xiaodu_jsdelivr_hash_remote_url: Get remote url failed, " . $url);
        return false;
    }
    return hash('sha256', $content);
}

/**
 * @param $old_entry array
 * @param $full_path string
 * @param $file_hash null | string
 * @param $file_stat array
 * @param $plugin_options XiaoduJsdelivrOptions
 * @return bool true = unchanged
 */
function xiaodu_jsdelivr_check_file_unchanged($old_entry, $full_path, &$file_hash, $file_stat, $plugin_options) {
    if (!$plugin_options->scanner_always_hash &&
        isset($old_entry['size'], $old_entry['mtime']) &&
        $old_entry['mtime'] == $file_stat['mtime']
    ) {
        return $old_entry['size'] == $file_stat['size'];
    }
    // Fall back to calculating hash
    if (!isset($file_hash)) {
        $file_hash = @hash_file('sha256', $full_path);
        if ($file_hash === FALSE) {
            error_log("_xiaodu_jsdelivr_scan_directory: Hash failed, " . $full_path);
            return false;
        }
    }
    return $file_hash == $old_entry['sha256'];
}

function xiaodu_jsdelivr_scan_directory($dir, &$options) {
    xiaodu_jsdelivr_debug_log("START DIR SCAN $dir ");
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
    $plugin_options = XiaoduJsdelivrOptions::inst();
    if ($plugin_options->scanner_randomized) {
        shuffle($dir_contents);
    }
    foreach ($dir_contents as $name) {
        $full_path = $dir_full_path . '/' . $name;
        $path = $dir . '/' . $name;
        if (is_dir($full_path)) {
            xiaodu_jsdelivr_scan_directory($path, $options);
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
        // Get file info
        $file_stat = @stat($full_path);
        if ($file_stat === FALSE || !isset($file_stat['size'], $file_stat['mtime'])) {
            error_log("_xiaodu_jsdelivr_scan_directory: Stat failed, " . $full_path);
            continue;
        }
        if ($file_stat['size'] > 8388608) {
            error_log("_xiaodu_jsdelivr_scan_directory: File too large, " . $full_path);
            continue;
        }
        $file_hash = null;
        // Check recent failure records
        $fail_record_key = '!' . $path;
        if (isset($old_data[$fail_record_key])) {
            $old_entry = $old_data[$fail_record_key];
            if ($options['start_time'] > $old_entry['fail_time'] + 7200) {
                // Failure records expired
                unset($old_data[$fail_record_key]);
            } else {
                $file_unchanged = xiaodu_jsdelivr_check_file_unchanged($old_entry, $full_path, $file_hash, $file_stat, $plugin_options);
                if ($file_unchanged) {
                    xiaodu_jsdelivr_debug_log('_xiaodu_jsdelivr_scan_directory: Skip recently failed file ', $full_path);
                    $new_data[$fail_record_key] = $old_entry;
                    $options['fail_records'][] = $path;
                    continue;
                } else {
                    if ($file_hash === false) {  // Hash failed
                        continue;
                    }
                    unset($old_data[$fail_record_key]);
                }
            }
        }
        // Check if file is unchanged
        if (isset($old_data[$path])) {
            $old_entry = &$old_data[$path];
            $file_unchanged = xiaodu_jsdelivr_check_file_unchanged($old_entry, $full_path, $file_hash, $file_stat, $plugin_options);
            if ($file_unchanged) {
                $new_entry = $old_entry;
                $new_entry['version'] = $version;
                $new_entry['version_hint'] = $version_hint;
                $new_entry['size'] = $file_stat['size'];
                $new_entry['mtime'] = $file_stat['mtime'];
                $new_data[$path] = $new_entry;
                continue;
            } else {
                if ($file_hash === false) {  // Hash failed
                    continue;
                }
                unset($old_data[$path]);
            }
        }
        // Hash file content if not already done
        if (!isset($file_hash)) {
            $file_hash = @hash_file('sha256', $full_path);
            if ($file_hash === FALSE) {
                error_log("_xiaodu_jsdelivr_scan_directory: Hash failed, " . $full_path);
                continue;
            }
        }
        // Scan using hints
        $scan_result = NULL;
        $remote_hash_timeout = false;
        if ($scan_hints) {
            foreach ($scan_hints as $hint => $skip_length) {
                $concat_path = ($skip_length <= 0) ? $path : substr($path, $skip_length);
                $scan_url = $hint . $concat_path;
                $scan_hash = xiaodu_jsdelivr_hash_remote_url($options, $scan_url);
                if ($scan_hash === false) {
                    $remote_hash_timeout = true;
                    if (xiaodu_jsdelivr_check_scan_timeout($options)) {
                        xiaodu_jsdelivr_debug_log("SCAN TIME OUT $path");
                        return;
                    }
                    continue;
                }
                xiaodu_jsdelivr_debug_log("TRY $path [$file_hash] -> $scan_url [$scan_hash]");
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
                if ($api_res !== NULL && isset($api_res['type'], $api_res['name'], $api_res['version'], $api_res['file'])) {
                    if ($api_res['file'][0] != '/') {
                        $api_res['file'] = '/' . $api_res['file'];
                    }
                    $scan_url = "https://cdn.jsdelivr.net/{$api_res['type']}/{$api_res['name']}@{$api_res['version']}{$api_res['file']}";
                    $scan_hash = xiaodu_jsdelivr_hash_remote_url($options, $scan_url);
                    if ($scan_hash === false) {
                        $remote_hash_timeout = true;
                        if (xiaodu_jsdelivr_check_scan_timeout($options)) {
                            xiaodu_jsdelivr_debug_log("SCAN TIME OUT $path");
                            return;
                        }
                    } else {
                        xiaodu_jsdelivr_debug_log("LOOKUP $path [$file_hash] -> $scan_url [$scan_hash]");
                        if ($scan_hash === $file_hash) {
                            $scan_result = $scan_url;
                        }
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
                'size' => $file_stat['size'],
                'mtime' => $file_stat['mtime'],
                'url' => $scan_result
            );
            xiaodu_jsdelivr_debug_log("FOUND MATCH: $path -> $scan_result");
        } else if (!$remote_hash_timeout) {
            // When all hinted urls are missing or mismatched, but **not timed out**,
            // add a failure record to avoid unnecessary attempts in a while.
            $new_data[$fail_record_key] = array(
                'sha256' => $file_hash,
                'size' => $file_stat['size'],
                'mtime' => $file_stat['mtime'],
                'fail_time' => intval($options['start_time']),
            );
            $options['fail_records'][] = $path;
            xiaodu_jsdelivr_debug_log("ADD FAILURE RECORD: $path");
        }
        if (!$remote_hash_timeout && xiaodu_jsdelivr_check_scan_timeout($options)) {
            xiaodu_jsdelivr_debug_log("SCAN TIME OUT $dir");
            return;
        }
    }
}

function xiaodu_jsdelivr_scan() {
    // Check and set lock
    $now = microtime(true);
    xiaodu_jsdelivr_debug_log("START SCAN $now");
    $lock_write_content = sprintf( '%.22F', $now );
    $lock = get_transient('xiaodu_jsdelivr_lock');
    if ($lock !== FALSE) {
        error_log("_xiaodu_jsdelivr_scan: Lock not expired, " . print_r($lock, TRUE));
        return;
    }
    if (set_transient('xiaodu_jsdelivr_lock', $lock_write_content, 360) === FALSE) {
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

    // Calculate appropriate timeout
    $sys_time_limit = function_exists( 'ini_get' ) ? intval(ini_get( 'max_execution_time' )) : 0;
    if ($sys_time_limit <= 0) {
        $sys_time_limit = 300;
    }
    $plugin_options = XiaoduJsdelivrOptions::inst();
    $user_time_limit = intval($plugin_options->scanner_timeout);
    if ($user_time_limit <= 0) {
        $user_time_limit = 300;
    }
    $timeout = min($sys_time_limit, $user_time_limit, 300);  // At most 300s
    /**
     * jsDelivr can be REALLY slow sometimes, especially when requesting a file that doesn't exist in the cache.
     * So here a safe margin of 10s is set, along with the 8s timeout below, to make these slow requests possible,
     * while ensuring that a single scan cannot exceed the time limits.
     */
    $timeout = max($timeout - 10, 10);

    // Scan base WordPress files
    global $wp_version;
    $jsdelivr_wp_hint = "https://cdn.jsdelivr.net/gh/WordPress/WordPress@{$wp_version}/";
    $stream_ctx = stream_context_create(array('http' => array(
        'timeout' => 8,
        'ignore_errors' => true,
        'user_agent' => 'PHP WordPress Plugin (xiaodu-jsdelivr; Scanner)',
    )));
    $options = array(
        "old_data" => &$old_data,
        "new_data" => &$new_data,
        "scan_hints" => array($jsdelivr_wp_hint => 0),
        "version" => $wp_version,
        "version_hint" => NULL,
        "start_time" => defined('WP_START_TIMESTAMP') ? WP_START_TIMESTAMP : $now,
        "timeout" => $timeout,
        "stream_ctx" => $stream_ctx,
        "is_timeout" => FALSE,
        "fail_records" => array(),
    );
    $scan_dir_list = array(
        'wp-admin',
        'wp-includes',
    );
    foreach ($scan_dir_list as $dir) {
        xiaodu_jsdelivr_scan_directory($dir, $options);
        if ($options['is_timeout']) {
            break;
        }
    }

    // Scan plugins
    $plugin_root = WP_PLUGIN_DIR;
    if (!$options['is_timeout'] && ABSPATH === substr($plugin_root, 0, strlen(ABSPATH))) {
        $plugin_parent_dir = substr(WP_PLUGIN_DIR, strlen(ABSPATH));
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
            $plugin_dir = $plugin_parent_dir . '/' . $plugin_dir_name;
            if (isset($new_data[$plugin_dir])) {
                error_log("_xiaodu_jsdelivr_scan: Repeated plugin dir, " . $plugin_dir);
                continue;
            }
            $plugin_version = $plugin_data['Version'];
            $new_data['^' . $plugin_dir] = array(
                "hint_file" => $plugin_parent_dir . '/' . $plugin_file, "version" => $plugin_version,
            );
            // Scan folder
            $jsdelivr_plugin_hint = "https://cdn.jsdelivr.net/wp/plugins/{$plugin_dir_name}/tags/{$plugin_version}/";
            $options['version'] = $plugin_version;
            $options['version_hint'] = strlen($plugin_dir);
            $options['scan_hints'] = array(
                $jsdelivr_plugin_hint => strlen($plugin_dir) + 1,
                $jsdelivr_wp_hint => 0,
            );
            xiaodu_jsdelivr_scan_directory($plugin_dir, $options);
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
            xiaodu_jsdelivr_scan_directory($theme_dir, $options);
            if ($options['is_timeout']) {
                break;
            }
        }
    }

    // Save result and release lock
    $lock = get_transient('xiaodu_jsdelivr_lock');
    if ($lock !== $lock_write_content) {
        error_log("_xiaodu_jsdelivr_scan: Lock was stolen, " . print_r($lock, TRUE));
        return;
    }
    if ($options['is_timeout']) {
        $new_data = array_replace($old_data, $new_data);
    }
    $new_data['*result*'] = array(
        'fail_records' => $options['fail_records'],
        'is_timeout' => $options['is_timeout'],
    );
    update_option('xiaodu_jsdelivr_data', $new_data);
    delete_transient('xiaodu_jsdelivr_lock');
    xiaodu_jsdelivr_debug_log("FINISH SCAN $now, data size = " . count($new_data));
    if ($options['is_timeout']) {
        $next_scan = xiaodu_jsdelivr_reschedule(false, 1);
        xiaodu_jsdelivr_debug_log("THIS SCAN TIMED OUT, WILL SCAN AGAIN $next_scan");
    }
}
