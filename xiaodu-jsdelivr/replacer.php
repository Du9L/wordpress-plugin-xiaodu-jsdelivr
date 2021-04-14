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
 * Replace references to static files using scanning results.
 */

if ( ! defined( 'ABSPATH' ) ) { die( 'Invalid request.' ); }

$xiaodu_jsdelivr_data = null;
$xiaodu_jsdelivr_pattern = null;
$xiaodu_jsdelivr_versions = null;

function xiaodu_jsdelivr_global_data_loader() {
    global $xiaodu_jsdelivr_data;
    if ($xiaodu_jsdelivr_data !== NULL) {
        return $xiaodu_jsdelivr_data;
    }
    $xiaodu_jsdelivr_data = get_option('xiaodu_jsdelivr_data', FALSE);
    if (!$xiaodu_jsdelivr_data) {
        return FALSE;
    }
    
    global $xiaodu_jsdelivr_pattern, $xiaodu_jsdelivr_versions;
    $xiaodu_jsdelivr_versions = array();
    $pathes = array(
        'wp-admin',
        'wp-includes',
    );
    if (defined('WP_PLUGIN_DIR') && ABSPATH === substr(WP_PLUGIN_DIR, 0, strlen(ABSPATH))) {
        $pathes []= substr(WP_PLUGIN_DIR, strlen(ABSPATH));
    }
    global $wp_theme_directories;
    foreach ($wp_theme_directories as $theme_root) {
        if (ABSPATH === substr($theme_root, 0, strlen(ABSPATH))) {
            $pathes []= substr($theme_root, strlen(ABSPATH));
        }
    }
    for ($i = 0; $i < count($pathes); $i++) { 
        $pathes[$i] = preg_quote($pathes[$i], '/');
    }
    $site_url = preg_quote(site_url(), '/');
    $xiaodu_jsdelivr_pattern = '/^' .  // Beginning
        '(' . $site_url . ')?' .  // [1] (Optional) Site URL
        '\/(' . implode('|', $pathes) . ')' .  // [2] Search pathes
        '\/([^\?]*)' .  // [3] File path
        '(\?.*)?'.  // [4] (Optional) Query string
        '/';
    return $xiaodu_jsdelivr_data;
}

function xiaodu_jsdelivr_url_replacer($src) {
    $data = xiaodu_jsdelivr_global_data_loader();
    if (!$data) {
        return $src;
    }
    global $xiaodu_jsdelivr_pattern;
    if (!preg_match($xiaodu_jsdelivr_pattern, $src, $matches)) {
        return $src;
    }
    $path = $matches[2];
    $file_path = $matches[3];
    $file = $path . '/' . $file_path;
    if (!isset($data[$file])) {
        xiaodu_jsdelivr_debug_log("No scan result for {$file}");
        return $src;
    }
    $entry = $data[$file];
    if (!isset($entry['url']) || !isset($entry['version'])) {
        error_log("_xiaodu_jsdelivr_url_replacer: Invalid search result $file, " . print_r($entry, TRUE));
        return $src;
    }
    // Check version
    $data_version = $entry['version'];
    $version_hint = $entry['version_hint'];
    if ($version_hint === NULL) {
        global $wp_version;
        $current_version = $wp_version;
    } else if (is_int($version_hint)) {
        global $xiaodu_jsdelivr_versions;
        $hint_key = '^' . substr($file, 0, $version_hint);
        if (isset($xiaodu_jsdelivr_versions[$hint_key])) {
            $current_version = $xiaodu_jsdelivr_versions[$hint_key];
            if ($current_version === FALSE) {
                return $src;
            }
        } else {
            if (!isset($data[$hint_key]) || !isset($data[$hint_key]['hint_file'])) {
                error_log("_xiaodu_jsdelivr_url_replacer: No hint {$src}, {$hint_key}");
                $xiaodu_jsdelivr_versions[$hint_key] = FALSE;
                return $src;
            }
            $hint_file = ABSPATH . $data[$hint_key]['hint_file'];
            if (!is_readable($hint_file)) {
                error_log("_xiaodu_jsdelivr_url_replacer: Invalid hint file {$src}, {$hint_file}");
                $xiaodu_jsdelivr_versions[$hint_key] = FALSE;
                return $src;
            }
            $file_data = get_file_data($hint_file, array('Version' => 'Version'));
            if (!$file_data || !isset($file_data['Version'])) {
                error_log("_xiaodu_jsdelivr_url_replacer: No version in hint {$hint_file}, " . print_r($file_data, TRUE));
                $xiaodu_jsdelivr_versions[$hint_key] = FALSE;
                return $src;
            }
            $current_version = $file_data['Version'];
            $xiaodu_jsdelivr_versions[$hint_key] = $current_version;
        }
    } else {
        error_log("_xiaodu_jsdelivr_url_replacer: Invalid hint {$src}, " . print_r($version_hint, TRUE));
        return $src;
    }
    if ($data_version !== $current_version) {
        xiaodu_jsdelivr_debug_log("Version mismatch, {$file}, {$data_version}, {$current_version}");
        return $src;
    }
    // Version matches, replace src with scanned URL
    return $entry['url'];
}

add_filter( 'script_loader_src', 'xiaodu_jsdelivr_url_replacer', 20, 1 );
add_filter( 'style_loader_src', 'xiaodu_jsdelivr_url_replacer', 20, 1 );
