=== MetaSoft Connector ===
Contributors: metasoftsas
Tags: metasoft, woocommerce, integration, connector
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely connects this WordPress site to your MetaSoftSAS store so products, orders, customers, stock and tracking can be managed from the MetaSoftSAS panel instead of WordPress.

== Description ==

MetaSoft Connector is the bridge plugin for MetaSoftSAS's "Connect WordPress" integration. Install it, paste the Connection Key shown in your MetaSoftSAS panel (Website → WordPress কানেক্ট), and your site becomes centrally managed:

* MetaSoftSAS stays the single source of truth for products, categories, stock and pricing.
* Orders placed on this site flow into MetaSoftSAS's existing order management, courier and fraud-check tools.
* Tracking (Meta Pixel / CAPI, Microsoft Clarity) stays controlled from MetaSoftSAS.

This plugin is deliberately lightweight — it does not duplicate MetaSoftSAS's business logic, it only provides a secure, authenticated bridge for MetaSoftSAS to call into and for this site to call out to.

== Installation ==

1. Upload the `metasoft-connector` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In your MetaSoftSAS panel, go to Website → WordPress কানেক্ট and click "কানেকশন কী তৈরি করুন" to generate a Connection Key.
4. In WordPress, go to Settings → MetaSoft Connector, paste the key, and click "Connect to MetaSoftSAS".

== Changelog ==

= 0.1.0 =
* Initial release: secure connection handshake, health check, disconnect.
