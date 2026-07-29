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
 * Optional dual mode (JoliCode-inspired): light analysis on main text fields for precision,
 * heavy (stemmed) analysis on .stemmed multi-fields for recall.
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

	/**
	 * Post text fields that receive a .stemmed multi-field in dual mode.
	 *
	 * @var string[]
	 */
	private const STEMMED_SOURCE_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

	/**
	 * Relative boost applied to .stemmed fields vs their parent field boost.
	 */
	private const STEMMED_BOOST_FACTOR = 0.5;

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
		add_filter( 'ep_post_mapping', [ $this, 'filter_post_mapping' ], 15 );
		add_filter( 'ep_analyzer_language', [ $this, 'force_analyzer_language' ], 20, 2 );
		add_filter( 'ep_post_fuzziness_arg', [ $this, 'filter_fuzziness' ] );
		add_filter( 'ep_post_match_fuzziness', [ $this, 'filter_fuzziness' ] );
		// After ElasticPress Search weighting (priority 20), which rebuilds multi_match
		// field lists and would otherwise drop unknown .stemmed entries.
		add_filter( 'ep_formatted_args', [ $this, 'filter_formatted_args' ], 25, 2 );
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

		$dual = Settings::is_dual_analyzers_enabled( $settings );

		foreach ( [ 'default', 'default_search' ] as $analyzer_key ) {
			if ( ! isset( $mapping['settings']['analysis']['analyzer'][ $analyzer_key ] ) ) {
				continue;
			}

			$mapping['settings']['analysis']['analyzer'][ $analyzer_key ] = $this->build_analyzer(
				$mapping['settings']['analysis']['analyzer'][ $analyzer_key ],
				$settings,
				$dual ? 'light' : 'full'
			);
		}

		if ( $dual ) {
			$mapping = $this->register_heavy_analyzer( $mapping, $settings );
		}

		/**
		 * Allow a specific project to adjust the mapping after our pass
		 * (e.g. stemmer_override rules, extra keyword markers).
		 *
		 * @param array<string, mixed> $mapping  Final mapping.
		 * @param array<string, mixed> $settings Plugin settings.
		 */
		return apply_filters( 'epfr_mapping', $mapping, $settings );
	}

	/**
	 * Add .stemmed multi-fields on post text properties when dual mode is on.
	 *
	 * Runs on ep_post_mapping (before put_mapping) so we merge with features such as
	 * DidYouMean (.shingle) without overwriting their fields.
	 *
	 * @param  array<string, mixed> $mapping Post mapping.
	 * @return array<string, mixed>
	 */
	public function filter_post_mapping( array $mapping ): array {
		$settings = Settings::get();

		if ( ! Settings::is_dual_analyzers_enabled( $settings ) ) {
			return $mapping;
		}

		// Ensure filters/analyzers exist even if ep_post_mapping runs before ep_config_mapping.
		if ( ! isset( $mapping['settings']['analysis'] ) ) {
			$mapping['settings']['analysis'] = [];
		}
		$mapping['settings']['analysis']['filter'] = $this->build_filters(
			$mapping['settings']['analysis']['filter'] ?? [],
			$settings
		);
		$mapping = $this->register_heavy_analyzer( $mapping, $settings );

		$properties = $mapping['mappings']['properties'] ?? null;
		if ( ! is_array( $properties ) ) {
			// ES < 7 style: mappings.{type}.properties.
			if ( isset( $mapping['mappings'] ) && is_array( $mapping['mappings'] ) ) {
				foreach ( $mapping['mappings'] as $type => $type_mapping ) {
					if ( ! is_array( $type_mapping ) || empty( $type_mapping['properties'] ) || ! is_array( $type_mapping['properties'] ) ) {
						continue;
					}
					$mapping['mappings'][ $type ]['properties'] = $this->add_stemmed_fields( $type_mapping['properties'] );
				}
			}
			return $mapping;
		}

		$mapping['mappings']['properties'] = $this->add_stemmed_fields( $properties );

		return $mapping;
	}

	/**
	 * Inject .stemmed fields into the final formatted ES args (dual mode).
	 *
	 * Must run after ElasticPress weighting (`ep_formatted_args` priority 20).
	 * Weighting rewrites `multi_match.fields` from its known weightable keys and
	 * drops any field it does not recognize, including our `.stemmed` multi-fields.
	 *
	 * @param  array<string, mixed> $formatted_args Formatted Elasticsearch args.
	 * @param  array<string, mixed> $args           WP_Query args.
	 * @return array<string, mixed>
	 */
	public function filter_formatted_args( array $formatted_args, array $args ): array {
		unset( $args );

		if ( ! Settings::is_dual_analyzers_enabled() ) {
			return $formatted_args;
		}

		if ( empty( $formatted_args['query'] ) || ! is_array( $formatted_args['query'] ) ) {
			return $formatted_args;
		}

		$formatted_args['query'] = $this->inject_stemmed_fields_recursive( $formatted_args['query'] );

		return $formatted_args;
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

		// Never register keyword_marker without keywords (common ES configuration pitfall).
		$stem_exclusion = Settings::get_stem_exclusion_array();
		if ( ! empty( $stem_exclusion ) && 'none' !== $settings['stemmer'] ) {
			$filters['epfr_keywords'] = [
				'type'     => 'keyword_marker',
				'keywords' => $stem_exclusion,
			];
		}

		return $filters;
	}

	/**
	 * Rebuild the filter chain for a given analyzer.
	 *
	 * Modes:
	 * - full: elision -> lowercase -> asciifolding -> stop -> extras -> synonyms -> keywords -> stemmer
	 * - light: same without keywords/stemmer (precision / dual main fields)
	 *
	 * @param  array<string, mixed> $analyzer Analyzer definition.
	 * @param  array<string, mixed> $settings Plugin settings.
	 * @param  string               $mode     'full' or 'light'.
	 * @return array<string, mixed>
	 */
	private function build_analyzer( array $analyzer, array $settings, string $mode = 'full' ): array {
		$chain = $analyzer['filter'] ?? [];
		$apply_stemming = ( 'full' === $mode && 'none' !== $settings['stemmer'] );

		// Always remove existing stemmers and our keyword marker first.
		// In full mode we may append our own stemmer back; in light mode we keep fields unstemmed.
		$chain = array_values(
			array_filter(
				$chain,
				static function ( $filter_name ): bool {
					return is_string( $filter_name )
						&& ! preg_match( '/snowball|stemmer|epfr_keywords/i', $filter_name );
				}
			)
		);

		// Elision at the start of the chain (before lowercase, as in ES native french analyzer).
		if ( ! empty( $settings['elision'] ) && ! in_array( 'epfr_elision', $chain, true ) ) {
			array_unshift( $chain, 'epfr_elision' );
		}

		// Ensure lowercase is present.
		if ( ! in_array( 'lowercase', $chain, true ) ) {
			$chain[] = 'lowercase';
		}

		// Remove all accent-folding filters when the option is disabled, including ElasticPress'
		// preserve_original variant.
		$chain = array_values(
			array_filter(
				$chain,
				static function ( $filter_name ) use ( $settings ): bool {
					if ( empty( $settings['asciifolding'] ) && in_array( $filter_name, [ 'asciifolding', 'ep_asciifolding' ], true ) ) {
						return false;
					}
					return true;
				}
			)
		);

		// Insert asciifolding right after lowercase.
		if ( ! empty( $settings['asciifolding'] ) && ! in_array( 'asciifolding', $chain, true ) ) {
			$position = array_search( 'lowercase', $chain, true );
			array_splice( $chain, (int) $position + 1, 0, 'asciifolding' );
		}

		// Extra stopwords after existing stopwords (ep_stop).
		if ( ! empty( Settings::get_extra_stopwords_array() ) && ! in_array( 'epfr_extra_stop', $chain, true ) ) {
			$chain[] = 'epfr_extra_stop';
		}

		if ( $apply_stemming ) {
			// keyword_marker must sit immediately before the stemmer (official french order).
			if ( ! empty( Settings::get_stem_exclusion_array() ) && ! in_array( 'epfr_keywords', $chain, true ) ) {
				$chain[] = 'epfr_keywords';
			}
			$chain[] = 'epfr_stemmer';
		}

		$analyzer['filter'] = array_values( array_unique( $chain ) );

		return $analyzer;
	}

	/**
	 * Register the named epfr_heavy analyzer used by .stemmed multi-fields.
	 *
	 * @param  array<string, mixed> $mapping  Mapping.
	 * @param  array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function register_heavy_analyzer( array $mapping, array $settings ): array {
		$base = $mapping['settings']['analysis']['analyzer']['default'] ?? [
			'tokenizer'   => 'standard',
			'filter'      => [ 'lowercase' ],
			'char_filter' => [ 'html_strip' ],
		];

		// Start from the light default chain, then append keywords + stemmer.
		$heavy = $this->build_analyzer( $base, $settings, 'full' );

		$mapping['settings']['analysis']['analyzer']['epfr_heavy'] = $heavy;

		return $mapping;
	}

	/**
	 * Attach .stemmed multi-fields to the known post text properties.
	 *
	 * @param  array<string, mixed> $properties Mapping properties.
	 * @return array<string, mixed>
	 */
	private function add_stemmed_fields( array $properties ): array {
		foreach ( self::STEMMED_SOURCE_FIELDS as $field ) {
			if ( ! isset( $properties[ $field ] ) || ! is_array( $properties[ $field ] ) ) {
				continue;
			}

			if ( ! isset( $properties[ $field ]['fields'] ) || ! is_array( $properties[ $field ]['fields'] ) ) {
				$properties[ $field ]['fields'] = [];
			}

			$properties[ $field ]['fields']['stemmed'] = [
				'type'     => 'text',
				'analyzer' => 'epfr_heavy',
			];
		}

		return $properties;
	}

	/**
	 * Recursively walk a query and append .stemmed counterparts to multi_match fields.
	 *
	 * @param  mixed $node Query node.
	 * @return mixed
	 */
	private function inject_stemmed_fields_recursive( $node ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}

		if ( isset( $node['multi_match'] ) && is_array( $node['multi_match'] ) ) {
			$type = $node['multi_match']['type'] ?? '';
			// Heterogeneous analyzers break cross_fields; keep stemmed off those clauses.
			if ( 'cross_fields' !== $type && ! empty( $node['multi_match']['fields'] ) && is_array( $node['multi_match']['fields'] ) ) {
				$node['multi_match']['fields'] = $this->append_stemmed_field_names( $node['multi_match']['fields'] );
			}
		}

		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $this->inject_stemmed_fields_recursive( $value );
			}
		}

		return $node;
	}

	/**
	 * Append .stemmed field names with a reduced boost for known source fields.
	 *
	 * @param  array<int, string> $fields multi_match fields list.
	 * @return array<int, string>
	 */
	private function append_stemmed_field_names( array $fields ): array {
		$existing = [];
		foreach ( $fields as $field ) {
			if ( is_string( $field ) ) {
				$existing[ $this->strip_field_boost( $field ) ] = true;
			}
		}

		$extra = [];
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) ) {
				continue;
			}

			[ $name, $boost ] = $this->parse_field_boost( $field );

			if ( ! in_array( $name, self::STEMMED_SOURCE_FIELDS, true ) ) {
				continue;
			}

			$stemmed_name = $name . '.stemmed';
			if ( isset( $existing[ $stemmed_name ] ) ) {
				continue;
			}

			$stemmed_boost = $boost * self::STEMMED_BOOST_FACTOR;
			$extra[]       = $stemmed_name . '^' . $this->format_boost( $stemmed_boost );
			$existing[ $stemmed_name ] = true;
		}

		return array_merge( $fields, $extra );
	}

	/**
	 * @param  string $field Field possibly with ^boost.
	 * @return array{0: string, 1: float}
	 */
	private function parse_field_boost( string $field ): array {
		if ( preg_match( '/^(.+)\^([0-9]+(?:\.[0-9]+)?)$/', $field, $matches ) ) {
			return [ $matches[1], (float) $matches[2] ];
		}
		return [ $field, 1.0 ];
	}

	/**
	 * @param  string $field Field possibly with ^boost.
	 * @return string
	 */
	private function strip_field_boost( string $field ): string {
		return $this->parse_field_boost( $field )[0];
	}

	/**
	 * Format a boost for multi_match field syntax without trailing junk.
	 *
	 * @param  float $boost Boost value.
	 * @return string
	 */
	private function format_boost( float $boost ): string {
		$formatted = rtrim( rtrim( sprintf( '%.4F', $boost ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
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

		return (string) $settings['fuzziness'];
	}
}
