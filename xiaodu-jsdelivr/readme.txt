=== xiaodu-jsdelivr ===
Contributors: dujiulun2006
Tags: jsdelivr,cdn,static
Requires at least: 5.3
Tested up to: 5.7
Requires PHP: 7.2
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan and serve static files from jsDelivr CDN (https://jsdelivr.com).

== Description ==
Scan and serve static files from jsDelivr CDN (https://jsdelivr.com).

= How to use =
1. Install and activate the plugin
2. Wait for the initial scan(s) to complete. Scan results are shown on the plugin's options page
3. The static file references on the frontend and admin area will be replaced

= How it works =
Explained in my [blog entry](https://s.du9l.com/u7yiP).

= Source code repository =
Check out the [GitHub repository](https://github.com/Du9L/wordpress-plugin-xiaodu-jsdelivr).

== Frequently Asked Questions ==

= The plugin won't scan =
Please make sure that your WP-Cron is working, or look into alternative ways to trigger cron executions.

= No references are replaced =
Please wait for the scan to finish. The initial scan may take a while.

Also make sure that your frontend cache plugin (e.g. WP Super Cache) is not serving stale pages.

== Changelog ==

= 1.3 =
* Add "Scan API", an optional hosted service to assist and accelerate the scanning process

= 1.2.1 =
* When a file cannot be matched with a remote timeout, try again soon

= 1.2 =
* Record failed paths during scans to avoid unnecessary attempts in future scans
* Show scan result and failed paths on options page
* Add an option to randomize scan order

= 1.1 =
* Provide an options page with status display and two new options
* Better scan timeout handling

= 1.0 =
* First version with working Scanner and Replacer.
