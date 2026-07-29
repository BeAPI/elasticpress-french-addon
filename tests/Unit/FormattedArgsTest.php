<?php
/**
 * Unit tests for Analyzer::filter_formatted_args() and filter_fuzziness().
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

namespace ElasticPress_French_Addon\Tests\Unit;

use ElasticPress_French_Addon\Analyzer;
use ElasticPress_French_Addon\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Class FormattedArgsTest
 */
class FormattedArgsTest extends TestCase {

	/**
	 * @var Analyzer
	 */
	private Analyzer $analyzer;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['epfr_test_options'] = [];
		$this->analyzer               = Analyzer::instance();
	}

	/**
	 * Persist settings via the in-memory option stub.
	 *
	 * @param array<string, mixed> $overrides Settings overrides.
	 */
	private function set_settings( array $overrides = [] ): void {
		update_option(
			EPFR_OPTION_KEY,
			array_merge( Settings::get_defaults(), $overrides )
		);
	}

	/**
	 * Typical post-weighting ElasticPress formatted args with nested multi_match.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_formatted_args(): array {
		return [
			'query' => [
				'bool' => [
					'should' => [
						[
							'multi_match' => [
								'query'  => 'cheval',
								'type'   => 'best_fields',
								'fields' => [
									'post_title^3',
									'post_content',
									'post_excerpt^0.5',
									'meta.foo',
								],
							],
						],
						[
							'multi_match' => [
								'query'  => 'cheval',
								'type'   => 'phrase',
								'fields' => [
									'post_title^3',
									'post_content',
								],
							],
						],
						[
							'multi_match' => [
								'query'  => 'cheval',
								'type'   => 'cross_fields',
								'fields' => [
									'post_title',
									'post_content',
									'post_excerpt',
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Collect multi_match clauses by type from a formatted-args tree.
	 *
	 * @param  array<string, mixed> $node Query tree.
	 * @return array<string, array<string, mixed>>
	 */
	private function multi_match_by_type( array $node ): array {
		$found = [];
		$this->walk_multi_match(
			$node,
			static function ( array $mm ) use ( &$found ): void {
				$type           = (string) ( $mm['type'] ?? '' );
				$found[ $type ] = $mm;
			}
		);
		return $found;
	}

	/**
	 * @param array<string, mixed> $node     Query tree.
	 * @param callable             $callback Receives each multi_match array.
	 */
	private function walk_multi_match( array $node, callable $callback ): void {
		if ( isset( $node['multi_match'] ) && is_array( $node['multi_match'] ) ) {
			$callback( $node['multi_match'] );
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->walk_multi_match( $value, $callback );
			}
		}
	}

	public function test_dual_off_leaves_args_unchanged(): void {
		$this->set_settings( [ 'dual_analyzers' => false ] );
		$input = $this->sample_formatted_args();

		$result = $this->analyzer->filter_formatted_args( $input, [] );

		$this->assertSame( $input, $result );
	}

	public function test_addon_disabled_leaves_args_unchanged(): void {
		$this->set_settings(
			[
				'enabled'        => false,
				'dual_analyzers' => true,
			]
		);
		$input = $this->sample_formatted_args();

		$result = $this->analyzer->filter_formatted_args( $input, [] );

		$this->assertSame( $input, $result );
	}

	public function test_stemmer_none_leaves_args_unchanged(): void {
		$this->set_settings(
			[
				'stemmer'        => 'none',
				'dual_analyzers' => true,
			]
		);
		$input = $this->sample_formatted_args();

		$result = $this->analyzer->filter_formatted_args( $input, [] );

		$this->assertSame( $input, $result );
	}

	public function test_dual_on_injects_stemmed_fields_with_half_boost(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );

		$result = $this->analyzer->filter_formatted_args( $this->sample_formatted_args(), [] );
		$byType = $this->multi_match_by_type( $result['query'] );

		$this->assertSame(
			[
				'post_title^3',
				'post_content',
				'post_excerpt^0.5',
				'meta.foo',
				'post_title.stemmed^1.5',
				'post_content.stemmed^0.5',
				'post_excerpt.stemmed^0.25',
			],
			$byType['best_fields']['fields']
		);
	}

	public function test_cross_fields_skips_stemmed_injection(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );

		$result = $this->analyzer->filter_formatted_args( $this->sample_formatted_args(), [] );
		$byType = $this->multi_match_by_type( $result['query'] );

		$this->assertSame(
			[
				'post_title',
				'post_content',
				'post_excerpt',
			],
			$byType['cross_fields']['fields']
		);
	}

	public function test_nested_bool_injects_all_eligible_multi_match(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );

		$result = $this->analyzer->filter_formatted_args( $this->sample_formatted_args(), [] );
		$byType = $this->multi_match_by_type( $result['query'] );

		$this->assertContains( 'post_title.stemmed^1.5', $byType['best_fields']['fields'] );
		$this->assertContains( 'post_title.stemmed^1.5', $byType['phrase']['fields'] );
		$this->assertContains( 'post_content.stemmed^0.5', $byType['phrase']['fields'] );
		$this->assertArrayNotHasKey(
			'post_title.stemmed^1.5',
			array_flip( $byType['cross_fields']['fields'] )
		);
	}

	public function test_injection_is_idempotent(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );
		$input = $this->sample_formatted_args();

		$once  = $this->analyzer->filter_formatted_args( $input, [] );
		$twice = $this->analyzer->filter_formatted_args( $once, [] );

		$this->assertSame( $once, $twice );
	}

	public function test_non_source_fields_are_ignored(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );

		$result = $this->analyzer->filter_formatted_args( $this->sample_formatted_args(), [] );
		$byType = $this->multi_match_by_type( $result['query'] );
		$fields = $byType['best_fields']['fields'];

		$this->assertContains( 'meta.foo', $fields );
		foreach ( $fields as $field ) {
			$this->assertStringNotContainsString( 'meta.foo.stemmed', (string) $field );
		}
	}

	public function test_missing_query_is_noop(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );
		$input = [ 'size' => 10 ];

		$result = $this->analyzer->filter_formatted_args( $input, [] );

		$this->assertSame( $input, $result );
	}

	public function test_non_array_query_is_noop(): void {
		$this->set_settings( [ 'dual_analyzers' => true ] );
		$input = [ 'query' => 'match_all' ];

		$result = $this->analyzer->filter_formatted_args( $input, [] );

		$this->assertSame( $input, $result );
	}

	public function test_filter_fuzziness_auto(): void {
		$this->set_settings( [ 'fuzziness' => 'auto' ] );

		$this->assertSame( 'auto', $this->analyzer->filter_fuzziness( 'AUTO_FROM_EP' ) );
	}

	public function test_filter_fuzziness_numeric(): void {
		$this->set_settings( [ 'fuzziness' => '1' ] );

		$this->assertSame( '1', $this->analyzer->filter_fuzziness( 'auto' ) );
	}

	public function test_filter_fuzziness_disabled_passthrough(): void {
		$this->set_settings(
			[
				'enabled'   => false,
				'fuzziness' => '2',
			]
		);

		$this->assertSame( 'auto', $this->analyzer->filter_fuzziness( 'auto' ) );
	}
}
