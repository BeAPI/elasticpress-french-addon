<?php
/**
 * Plugin Name:       ElasticPress French Addon
 * Plugin URI:        https://github.com/beapi/elasticpress-french-addon
 * Description:       Fixes and optimizes the ElasticPress analyzer for French: asciifolding, elision, configurable stemmer, extra stopwords, fuzziness. Notably fixes the classic bug where accent-free searches return unrelated results.
 * Version:            1.0.2
 * Requires at least: 6.5
 * Requires PHP:       8.0
 * Author:             Be API
 * Author URI:         https://beapi.fr
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         elasticpress-french-addon
 * Domain Path:         /languages
 * Requires Plugins:    elasticpress
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPFR_VERSION', '1.0.2' );
define( 'EPFR_PLUGIN_FILE', __FILE__ );
define( 'EPFR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPFR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPFR_OPTION_KEY', 'epfr_settings' );

/**
 * Load the plugin text domain for translations.
 */
function epfr_load_textdomain(): void {
	load_plugin_textdomain(
		'elasticpress-french-addon',
		false,
		dirname( plugin_basename( EPFR_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'epfr_load_textdomain', 0 );

/**
 * Ensure ElasticPress is active before loading anything else.
 * The ep_config_mapping hook only exists when ElasticPress is loaded.
 */
function epfr_check_dependencies(): bool {
	if ( ! class_exists( '\ElasticPress\Elasticsearch' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'ElasticPress French Addon requires the ElasticPress plugin to be installed and activated.', 'elasticpress-french-addon' )
				);
			}
		);
		return false;
	}
	return true;
}

/**
 * Plugin bootstrap.
 */
function epfr_init(): void {
	if ( ! epfr_check_dependencies() ) {
		return;
	}

	include_once EPFR_PLUGIN_DIR . 'includes/class-epfr-settings.php';
	include_once EPFR_PLUGIN_DIR . 'includes/class-epfr-analyzer.php';
	include_once EPFR_PLUGIN_DIR . 'includes/class-epfr-admin.php';

	\ElasticPress_French_Addon\Analyzer::instance()->init();
	\ElasticPress_French_Addon\Admin::instance()->init();
}
add_action( 'plugins_loaded', 'epfr_init', 20 ); // Priority 20: after ElasticPress itself.

/**
 * On activation: store default settings and show a reindex reminder.
 */
function epfr_activate(): void {
	include_once EPFR_PLUGIN_DIR . 'includes/class-epfr-settings.php';
	if ( ! \ElasticPress_French_Addon\Settings::exists() ) {
		\ElasticPress_French_Addon\Settings::update( \ElasticPress_French_Addon\Settings::get_defaults() );
	}
	set_transient( 'epfr_activation_notice', true, MINUTE_IN_SECONDS * 5 );
}
register_activation_hook( __FILE__, 'epfr_activate' );

/**
 * Activation notice: remind that a full reindex is required.
 * Analyzer changes never apply to an already created index.
 */
function epfr_activation_notice(): void {
	if ( ! get_transient( 'epfr_activation_notice' ) ) {
		return;
	}

	if ( ! class_exists( '\ElasticPress_French_Addon\Settings' ) ) {
		return;
	}

	if ( ! current_user_can( \ElasticPress_French_Addon\Settings::get_capability() ) ) {
		return;
	}

	delete_transient( 'epfr_activation_notice' );
	printf(
		'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong><br>%s</p></div>',
		esc_html__( 'ElasticPress French Addon is active.', 'elasticpress-french-addon' ),
		esc_html__( 'A full reindex is required for the new analyzer settings to take effect (wp elasticpress index --setup --network-wide). A simple sync is not enough.', 'elasticpress-french-addon' )
	);
}
add_action( 'admin_notices', 'epfr_activation_notice' );
add_action( 'network_admin_notices', 'epfr_activation_notice' );
