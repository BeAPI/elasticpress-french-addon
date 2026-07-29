<?php
/**
 * PHPUnit bootstrap (no WordPress).
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'EPFR_OPTION_KEY', 'epfr_settings' );

$GLOBALS['epfr_test_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default Default value when the option is missing.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		if ( array_key_exists( $option, $GLOBALS['epfr_test_options'] ) ) {
			return $GLOBALS['epfr_test_options'][ $option ];
		}
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param mixed $value Option value.
	 */
	function update_option( string $option, $value ): bool {
		$GLOBALS['epfr_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * @param mixed $default Default value when the option is missing.
	 * @return mixed
	 */
	function get_site_option( string $option, $default = false ) {
		return get_option( $option, $default );
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	/**
	 * @param mixed $value Option value.
	 */
	function update_site_option( string $option, $value ): bool {
		return update_option( $option, $value );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * @param mixed                $args     Values to merge.
	 * @param array<string, mixed> $defaults Defaults.
	 * @return array<string, mixed>
	 */
	function wp_parse_args( $args, array $defaults = [] ): array {
		if ( ! is_array( $args ) ) {
			$args = [];
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param mixed $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-epfr-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-epfr-analyzer.php';
