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
 * Class XiaoduJsdelivrOptions
 */

class XiaoduJsdelivrOptions
{
    /**
     * @var XiaoduJsdelivrOptions
     */
    private static $_instance = null;
    public static $options_key = 'xiaodu_jsdelivr_options';

    private function __construct()
    {
        $this->reload();
    }

    /**
     * Reload options
     */
    public function reload() {
        $options = get_option(self::$options_key, array());
        if (!is_array($options)) {
            $options = array();
        }
        $vars = get_object_vars($this);

        foreach ($vars as $k => $v) {
            $exist_in_options = array_key_exists($k, $options);
            if (is_bool($v)) {
                if (!$exist_in_options) {
                    $this->{$k} = false;
                } else {
                    $this->{$k} = $options[$k] == '1';
                }
            } else {
                xiaodu_jsdelivr_debug_log('Unsupported option type: ' . gettype($v));
            }
        }
    }

    /**
     * @return XiaoduJsdelivrOptions
     */
    public static function inst() {
        if (self::$_instance === null) {
            self::$_instance = new XiaoduJsdelivrOptions();
        }
        return self::$_instance;
    }

    // ======== The following are options ========

    /**
     * Always calculate hash in Scanner
     * @var bool
     */
    public $scanner_always_hash = false;
}
