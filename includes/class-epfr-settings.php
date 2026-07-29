<?php
/**
 * Plugin settings management.
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

namespace ElasticPress_French_Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {


	/**
	 * Default values, aligned with recommendations for standard French content.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'enabled'         => true,
			'asciifolding'    => true,
			'elision'         => true,
			'stemmer'         => 'light_french', // none | minimal_french | light_french | french
			'fuzziness'       => 'auto',         // auto | 0 | 1 | 2
			'extra_stopwords' => '',             // comma-separated list, in addition to _french_
			'stem_exclusion'  => '',             // comma-separated words excluded from stemming
			'dual_analyzers'  => false,          // light on main fields + heavy .stemmed multi-fields
		];
	}

	/**
	 * Whether ElasticPress is running in network-wide mode.
	 *
	 * Falls back to checking whether ElasticPress is network-activated when the
	 * EP_IS_NETWORK constant is not yet defined (e.g. during plugin activation).
	 */
	public static function is_network_mode(): bool {
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( 'elasticpress/elasticpress.php' );
	}

	/**
	 * Capability required to manage addon settings (aligned with ElasticPress).
	 */
	public static function get_capability(): string {
		if ( self::is_network_mode() ) {
			if ( function_exists( '\ElasticPress\Utils\get_network_capability' ) ) {
				return \ElasticPress\Utils\get_network_capability();
			}
			return 'manage_network_options';
		}

		if ( function_exists( '\ElasticPress\Utils\get_capability' ) ) {
			return \ElasticPress\Utils\get_capability();
		}

		return 'manage_options';
	}

	/**
	 * Get current settings merged with defaults
	 * (protects against a partial option after a future plugin update).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = self::read_raw();
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		if ( self::is_network_mode() && [] === $stored ) {
			$stored = self::migrate_blog_option_to_site_option();
		}

		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Persist settings using the storage matching the ElasticPress mode.
	 *
	 * @param  array<string, mixed> $settings Sanitized settings.
	 * @return bool
	 */
	public static function update( array $settings ): bool {
		if ( self::is_network_mode() ) {
			return (bool) update_site_option( EPFR_OPTION_KEY, $settings );
		}
		return (bool) update_option( EPFR_OPTION_KEY, $settings );
	}

	/**
	 * Whether a stored option already exists (without merging defaults).
	 */
	public static function exists(): bool {
		if ( self::is_network_mode() ) {
			return false !== get_site_option( EPFR_OPTION_KEY, false );
		}
		return false !== get_option( EPFR_OPTION_KEY, false );
	}

	/**
	 * Sanitize a settings array submitted from the admin form.
	 *
	 * Accepts mixed because options.php may pass null when the field is absent from POST.
	 *
	 * @param  mixed $input Raw form data.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$defaults = self::get_defaults();

		$allowed_stemmers  = [ 'none', 'minimal_french', 'light_french', 'french' ];
		$allowed_fuzziness = [ 'auto', '0', '1', '2' ];

		$stemmer   = isset( $input['stemmer'] ) ? sanitize_key( (string) $input['stemmer'] ) : $defaults['stemmer'];
		$fuzziness = isset( $input['fuzziness'] ) ? sanitize_key( (string) $input['fuzziness'] ) : $defaults['fuzziness'];

		return [
			'enabled'         => ! empty( $input['enabled'] ),
			'asciifolding'    => ! empty( $input['asciifolding'] ),
			'elision'         => ! empty( $input['elision'] ),
			'stemmer'         => in_array( $stemmer, $allowed_stemmers, true ) ? $stemmer : $defaults['stemmer'],
			'fuzziness'       => in_array( $fuzziness, $allowed_fuzziness, true ) ? $fuzziness : $defaults['fuzziness'],
			'extra_stopwords' => isset( $input['extra_stopwords'] ) ? sanitize_textarea_field( (string) $input['extra_stopwords'] ) : '',
			'stem_exclusion'  => isset( $input['stem_exclusion'] ) ? sanitize_textarea_field( (string) $input['stem_exclusion'] ) : '',
			'dual_analyzers'  => ! empty( $input['dual_analyzers'] ),
		];
	}

	/**
	 * Turn a comma-separated setting into a trimmed string array.
	 *
	 * @param  string $raw Comma-separated list.
	 * @return string[]
	 */
	private static function csv_to_array( string $raw ): array {
		if ( '' === $raw ) {
			return [];
		}
		$words = array_map( 'trim', explode( ',', $raw ) );
		$words = array_filter(
			$words,
			static function ( string $word ): bool {
				return '' !== $word;
			}
		);
		return array_values( $words );
	}

	/**
	 * Turn the extra stopwords list (comma-separated text) into an ES array.
	 *
	 * @param  array<string, mixed>|null $settings Optional settings snapshot (avoids get_option()).
	 * @return string[]
	 */
	public static function get_extra_stopwords_array( ?array $settings = null ): array {
		$settings = $settings ?? self::get();
		return self::csv_to_array( (string) ( $settings['extra_stopwords'] ?? '' ) );
	}

	/**
	 * Turn the stem exclusion list (comma-separated text) into an ES array.
	 *
	 * @param  array<string, mixed>|null $settings Optional settings snapshot (avoids get_option()).
	 * @return string[]
	 */
	public static function get_stem_exclusion_array( ?array $settings = null ): array {
		$settings = $settings ?? self::get();
		return self::csv_to_array( (string) ( $settings['stem_exclusion'] ?? '' ) );
	}

	/**
	 * Whether dual light/heavy analyzers are active and meaningful.
	 *
	 * @param  array<string, mixed>|null $settings Optional settings snapshot.
	 * @return bool
	 */
	public static function is_dual_analyzers_enabled( ?array $settings = null ): bool {
		$settings = $settings ?? self::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['dual_analyzers'] ) ) {
			return false;
		}
		return 'none' !== ( $settings['stemmer'] ?? 'none' );
	}

	/**
	 * Read the raw stored option for the current ElasticPress mode.
	 *
	 * @return mixed
	 */
	private static function read_raw(): mixed {
		if ( self::is_network_mode() ) {
			return get_site_option( EPFR_OPTION_KEY, [] );
		}
		return get_option( EPFR_OPTION_KEY, [] );
	}

	/**
	 * One-shot migration: copy a blog option into the network site option.
	 *
	 * @return array<string, mixed>
	 */
	private static function migrate_blog_option_to_site_option(): array {
		$blog_option = get_option( EPFR_OPTION_KEY, false );
		if ( ! is_array( $blog_option ) || [] === $blog_option ) {
			$main_site_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 0;
			if ( $main_site_id > 0 && get_current_blog_id() !== $main_site_id ) {
				$blog_option = get_blog_option( $main_site_id, EPFR_OPTION_KEY, false );
			}
		}

		if ( ! is_array( $blog_option ) || [] === $blog_option ) {
			return [];
		}

		update_site_option( EPFR_OPTION_KEY, $blog_option );
		delete_option( EPFR_OPTION_KEY );

		if ( function_exists( 'get_main_site_id' ) ) {
			$main_site_id = (int) get_main_site_id();
			if ( $main_site_id > 0 && get_current_blog_id() !== $main_site_id ) {
				delete_blog_option( $main_site_id, EPFR_OPTION_KEY );
			}
		}

		return $blog_option;
	}
}
