<?php
/**
 * Plugin core: adjusts the ElasticPress analyzer for correct French handling.
 *
 * Fixes three issues found in the default ElasticPress mapping:
 * 1. asciifolding missing from the "text" chain (only present in a "keyword" normalizer,
 *    so ineffective for full-text search) -> "haiti" and "haïti" produce two different tokens.
 * 2. Overly aggressive "snowball" (French) stemmer, which truncates words to ~4 letters and
 *    creates irrelevant collisions (haine/haute/fait all reduced to a similar root).
 * 3. No handling of French elision (l'article, d'un, qu'il...).
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

namespace ElasticPress_French_Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Analyzer
 */
class Analyzer {


	private static ?Analyzer $instance = null;

	public static function instance(): Analyzer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Hook into ElasticPress filters.
	 */
	public function init(): void {
		add_filter( 'ep_config_mapping', [ $this, 'filter_mapping' ], 20 );
		add_filter( 'ep_analyzer_language', [ $this, 'force_analyzer_language' ], 20, 2 );
		add_filter( 'ep_post_fuzziness_arg', [ $this, 'filter_fuzziness' ] );
	}

	/**
	 * Force Elasticsearch analyzer language to French while the addon is enabled.
	 *
	 * ElasticPress expects different formats per context (stopwords list vs snowball
	 * stemmer vs analyzer language key). Returning a bare "french" for every context
	 * would break ep_stop stopwords.
	 *
	 * @param  string $lang    Language resolved by ElasticPress (or earlier filters).
	 * @param  string $context Where the filter runs (e.g. filter_ep_stop).
	 * @return string
	 */
	public function force_analyzer_language( string $lang, string $context ): string {
		if ( empty( Settings::get()['enabled'] ) ) {
			return $lang;
		}

		if ( 'filter_ep_stop' === $context ) {
			return '_french_';
		}

		if ( 'filter_ewp_snowball' === $context ) {
			return 'French';
		}

		return 'french';
	}

	/**
	 * Modify the mapping sent to Elasticsearch when indexes are created/synced.
	 *
	 * Important: this change only applies to indexes created AFTER activation.
	 * A full reindex (wp elasticpress index --setup) is required.
	 *
	 * @param  array<string, mixed> $mapping Native ElasticPress mapping.
	 * @return array<string, mixed>
	 */
	public function filter_mapping( array $mapping ): array {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return $mapping;
		}

		if ( ! isset( $mapping['settings']['analysis'] ) ) {
			$mapping['settings']['analysis'] = [];
		}

		$mapping['settings']['analysis']['filter'] = $this->build_filters(
			$mapping['settings']['analysis']['filter'] ?? [],
			$settings
		);

		foreach ( [ 'default', 'default_search' ] as $analyzer_key ) {
			if ( ! isset( $mapping['settings']['analysis']['analyzer'][ $analyzer_key ] ) ) {
				continue;
			}

			$mapping['settings']['analysis']['analyzer'][ $analyzer_key ] = $this->build_analyzer(
				$mapping['settings']['analysis']['analyzer'][ $analyzer_key ],
				$settings
			);
		}

		/**
		 * Allow a specific project to adjust the mapping after our pass
		 * (e.g. stem_exclusion on a brand name, see "La Croix" / "croissant").
		 *
		 * @param array<string, mixed> $mapping  Final mapping.
		 * @param array<string, mixed> $settings Plugin settings.
		 */
		return apply_filters( 'epfr_mapping', $mapping, $settings );
	}

	/**
	 * Build/complete the list of named filters available to analyzers.
	 * Never removes existing filters defined by ElasticPress or another plugin
	 * (e.g. ep_synonyms_filter, shingle_filter): ours are added alongside.
	 *
	 * @param  array<string, mixed> $filters  Existing filters.
	 * @param  array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private function build_filters( array $filters, array $settings ): array {
		if ( ! empty( $settings['elision'] ) ) {
			$filters['epfr_elision'] = [
				'type'          => 'elision',
				'articles_case' => true,
				'articles'      => [ 'l', 'm', 't', 'qu', 'n', 's', 'j', 'd', 'c', 'jusqu', 'quoiqu', 'lorsqu', 'puisqu' ],
			];
		}

		if ( 'none' !== $settings['stemmer'] ) {
			$filters['epfr_stemmer'] = [
				'type'     => 'stemmer',
				'language' => $settings['stemmer'],
			];
		}

		$extra_stopwords = Settings::get_extra_stopwords_array();
		if ( ! empty( $extra_stopwords ) ) {
			$filters['epfr_extra_stop'] = [
				'type'        => 'stop',
				'stopwords'   => $extra_stopwords,
				'ignore_case' => true,
			];
		}

		return $filters;
	}

	/**
	 * Rebuild the filter chain for a given analyzer (default / default_search).
	 *
	 * Order applied: elision -> lowercase -> asciifolding -> existing stopwords
	 * -> extra stopwords -> existing synonyms -> stemmer.
	 * Asciifolding must sit after lowercase and before the stemmer, otherwise
	 * stemming on accented characters is incorrect.
	 *
	 * @param  array<string, mixed> $analyzer Analyzer definition.
	 * @param  array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private function build_analyzer( array $analyzer, array $settings ): array {
		$chain = $analyzer['filter'] ?? [];

		// Remove any existing stemmer (snowball, stemmer...): replaced by ours,
		// without touching non-stemmer filters (ep_stop, ep_synonyms_filter...).
		if ( 'none' !== $settings['stemmer'] ) {
			$chain = array_values(
				array_filter(
					$chain,
					static function ( $filter_name ): bool {
						return is_string( $filter_name ) && ! preg_match( '/snowball|stemmer/i', $filter_name );
					}
				)
			);
		}

		// Elision at the start of the chain (before lowercase, as in ES native french analyzer).
		if ( ! empty( $settings['elision'] ) && ! in_array( 'epfr_elision', $chain, true ) ) {
			array_unshift( $chain, 'epfr_elision' );
		}

		// Ensure lowercase is present.
		if ( ! in_array( 'lowercase', $chain, true ) ) {
			$chain[] = 'lowercase';
		}

		// Insert asciifolding right after lowercase.
		if ( ! empty( $settings['asciifolding'] ) && ! in_array( 'asciifolding', $chain, true ) ) {
			$position = array_search( 'lowercase', $chain, true );
			array_splice( $chain, (int) $position + 1, 0, 'asciifolding' );
		}

		// Extra stopwords after existing stopwords (ep_stop).
		if ( ! empty( Settings::get_extra_stopwords_array() ) && ! in_array( 'epfr_extra_stop', $chain, true ) ) {
			$chain[] = 'epfr_extra_stop';
		}

		// Stemmer must stay at the very end of the chain.
		if ( 'none' !== $settings['stemmer'] ) {
			$chain[] = 'epfr_stemmer';
		}

		$analyzer['filter'] = array_values( array_unique( $chain ) );

		return $analyzer;
	}

	/**
	 * Override the fuzziness applied by ElasticPress on search queries.
	 *
	 * @param  mixed $fuzziness Native ElasticPress value (usually "auto").
	 * @return mixed
	 */
	public function filter_fuzziness( $fuzziness ) {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return $fuzziness;
		}

		if ( 'auto' === $settings['fuzziness'] ) {
			return 'auto';
		}

		return (int) $settings['fuzziness'];
	}
}
