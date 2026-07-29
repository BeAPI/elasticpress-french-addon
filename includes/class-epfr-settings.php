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
		];
	}

	/**
	 * Get current settings merged with defaults
	 * (protects against a partial option after a future plugin update).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( EPFR_OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Sanitize a settings array submitted from the admin form.
	 *
	 * @param  array<string, mixed> $input Raw form data.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
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
		];
	}

	/**
	 * Turn the extra stopwords list (comma-separated text) into an ES array.
	 *
	 * @return string[]
	 */
	public static function get_extra_stopwords_array(): array {
		$settings = self::get();
		if ( empty( $settings['extra_stopwords'] ) ) {
			return [];
		}
		$words = array_map( 'trim', explode( ',', $settings['extra_stopwords'] ) );
		$words = array_filter(
			$words,
			static function ( string $word ): bool {
				return '' !== $word;
			}
		);
		return array_values( $words );
	}
}
