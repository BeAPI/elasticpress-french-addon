<?php
/**
 * Unit tests for Analyzer::build_analyzer() and build_filters().
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

namespace ElasticPress_French_Addon\Tests\Unit;

use ElasticPress_French_Addon\Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Class AnalyzerBuildTest
 */
class AnalyzerBuildTest extends TestCase {

	/**
	 * @var Analyzer
	 */
	private Analyzer $analyzer;

	/**
	 * Default settings snapshot used by most tests.
	 *
	 * @return array<string, mixed>
	 */
	private function default_settings( array $overrides = [] ): array {
		return array_merge(
			[
				'enabled'         => true,
				'asciifolding'    => true,
				'elision'         => true,
				'stemmer'         => 'light_french',
				'fuzziness'       => 'auto',
				'extra_stopwords' => '',
				'stem_exclusion'  => '',
				'dual_analyzers'  => false,
			],
			$overrides
		);
	}

	/**
	 * Typical ElasticPress 5.x / 7-0 default filter chain before our pass.
	 *
	 * @return array<string, mixed>
	 */
	private function ep_default_analyzer(): array {
		return [
			'tokenizer'   => 'standard',
			'char_filter' => [ 'html_strip' ],
			'filter'      => [ 'lowercase', 'ep_stop', 'ewp_snowball', 'ep_asciifolding' ],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->analyzer = Analyzer::instance();
	}

	public function test_full_mode_places_asciifolding_after_ep_stop(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$chain = $result['filter'];
		$stop  = array_search( 'ep_stop', $chain, true );
		$fold  = array_search( 'asciifolding', $chain, true );

		$this->assertNotFalse( $stop );
		$this->assertNotFalse( $fold );
		$this->assertSame( $stop + 1, $fold );
	}

	public function test_full_mode_removes_ep_asciifolding_when_folding_on(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$this->assertNotContains( 'ep_asciifolding', $result['filter'] );
		$this->assertContains( 'asciifolding', $result['filter'] );
	}

	public function test_folding_off_removes_both_folding_filters(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings( [ 'asciifolding' => false ] ),
			'full'
		);

		$this->assertNotContains( 'asciifolding', $result['filter'] );
		$this->assertNotContains( 'ep_asciifolding', $result['filter'] );
	}

	public function test_elision_is_prepended(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$this->assertSame( 'epfr_elision', $result['filter'][0] );
	}

	public function test_elision_off_skips_epfr_elision(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings( [ 'elision' => false ] ),
			'full'
		);

		$this->assertNotContains( 'epfr_elision', $result['filter'] );
	}

	public function test_strips_ewp_snowball(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$this->assertNotContains( 'ewp_snowball', $result['filter'] );
	}

	public function test_full_mode_appends_stemmer(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$chain = $result['filter'];
		$this->assertSame( 'epfr_stemmer', end( $chain ) );
	}

	public function test_light_mode_omits_stemmer_and_keywords(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(
				[
					'stem_exclusion' => 'croix, haiti',
				]
			),
			'light'
		);

		$this->assertNotContains( 'epfr_stemmer', $result['filter'] );
		$this->assertNotContains( 'epfr_keywords', $result['filter'] );
		$this->assertContains( 'asciifolding', $result['filter'] );
		$this->assertContains( 'ep_stop', $result['filter'] );
	}

	public function test_stemmer_none_omits_stemmer_in_full_mode(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings( [ 'stemmer' => 'none' ] ),
			'full'
		);

		$this->assertNotContains( 'epfr_stemmer', $result['filter'] );
		$this->assertNotContains( 'epfr_keywords', $result['filter'] );
	}

	public function test_extra_stopwords_sit_between_ep_stop_and_asciifolding(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings( [ 'extra_stopwords' => 'foo, bar' ] ),
			'full'
		);

		$chain = $result['filter'];
		$stop  = array_search( 'ep_stop', $chain, true );
		$extra = array_search( 'epfr_extra_stop', $chain, true );
		$fold  = array_search( 'asciifolding', $chain, true );

		$this->assertSame( $stop + 1, $extra );
		$this->assertSame( $extra + 1, $fold );
	}

	public function test_stem_exclusion_adds_keywords_before_stemmer(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings( [ 'stem_exclusion' => 'croix' ] ),
			'full'
		);

		$chain    = $result['filter'];
		$keywords = array_search( 'epfr_keywords', $chain, true );
		$stemmer  = array_search( 'epfr_stemmer', $chain, true );

		$this->assertNotFalse( $keywords );
		$this->assertSame( $keywords + 1, $stemmer );
	}

	public function test_no_duplicate_asciifolding(): void {
		$analyzer = $this->ep_default_analyzer();
		$analyzer['filter'][] = 'asciifolding';

		$result = $this->analyzer->build_analyzer(
			$analyzer,
			$this->default_settings(),
			'full'
		);

		$counts = array_count_values( $result['filter'] );
		$this->assertSame( 1, $counts['asciifolding'] ?? 0 );
	}

	public function test_asciifolding_after_lowercase_when_ep_stop_missing(): void {
		$result = $this->analyzer->build_analyzer(
			[
				'tokenizer' => 'standard',
				'filter'    => [ 'lowercase', 'ewp_snowball' ],
			],
			$this->default_settings(),
			'full'
		);

		$chain = $result['filter'];
		$lower = array_search( 'lowercase', $chain, true );
		$fold  = array_search( 'asciifolding', $chain, true );

		$this->assertNotFalse( $lower );
		$this->assertSame( $lower + 1, $fold );
		$this->assertNotContains( 'ep_stop', $chain );
	}

	public function test_ensures_lowercase_when_absent(): void {
		$result = $this->analyzer->build_analyzer(
			[
				'tokenizer' => 'standard',
				'filter'    => [ 'ep_stop', 'ewp_snowball' ],
			],
			$this->default_settings( [ 'elision' => false ] ),
			'full'
		);

		$this->assertContains( 'lowercase', $result['filter'] );
	}

	public function test_preserves_ep_synonyms_after_asciifolding(): void {
		$result = $this->analyzer->build_analyzer(
			[
				'tokenizer' => 'standard',
				'filter'    => [ 'lowercase', 'ep_stop', 'ep_synonyms_filter', 'ewp_snowball', 'ep_asciifolding' ],
			],
			$this->default_settings(),
			'full'
		);

		$chain    = $result['filter'];
		$fold     = array_search( 'asciifolding', $chain, true );
		$synonyms = array_search( 'ep_synonyms_filter', $chain, true );

		$this->assertNotFalse( $fold );
		$this->assertNotFalse( $synonyms );
		$this->assertGreaterThan( $fold, $synonyms );
	}

	public function test_build_filters_registers_named_filters_from_settings(): void {
		$filters = $this->analyzer->build_filters(
			[],
			$this->default_settings(
				[
					'extra_stopwords' => 'lorem, ipsum',
					'stem_exclusion'  => 'croix',
				]
			)
		);

		$this->assertArrayHasKey( 'epfr_elision', $filters );
		$this->assertSame( 'elision', $filters['epfr_elision']['type'] );
		$this->assertArrayHasKey( 'epfr_stemmer', $filters );
		$this->assertSame( 'light_french', $filters['epfr_stemmer']['language'] );
		$this->assertArrayHasKey( 'epfr_extra_stop', $filters );
		$this->assertSame( [ 'lorem', 'ipsum' ], $filters['epfr_extra_stop']['stopwords'] );
		$this->assertArrayHasKey( 'epfr_keywords', $filters );
		$this->assertSame( [ 'croix' ], $filters['epfr_keywords']['keywords'] );
	}

	public function test_build_filters_skips_keyword_marker_without_exclusion_or_stemmer(): void {
		$filters = $this->analyzer->build_filters(
			[],
			$this->default_settings(
				[
					'stemmer'        => 'none',
					'stem_exclusion' => 'croix',
					'elision'        => false,
				]
			)
		);

		$this->assertArrayNotHasKey( 'epfr_stemmer', $filters );
		$this->assertArrayNotHasKey( 'epfr_keywords', $filters );
		$this->assertArrayNotHasKey( 'epfr_elision', $filters );
	}

	public function test_default_settings_produce_expected_full_chain(): void {
		$result = $this->analyzer->build_analyzer(
			$this->ep_default_analyzer(),
			$this->default_settings(),
			'full'
		);

		$this->assertSame(
			[ 'epfr_elision', 'lowercase', 'ep_stop', 'asciifolding', 'epfr_stemmer' ],
			$result['filter']
		);
	}
}
