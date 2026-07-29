<?php
/**
 * Verify that each admin setting has the expected runtime effect.
 *
 * Usage:
 *   php bin/verify-admin-options.php
 *   ddev composer verify:options
 *
 * This script orchestrates WP-CLI/DDEV commands from PHP so each check runs in
 * its own process. That avoids nested WP-CLI side effects during reindexing.
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

$repoRoot = dirname( __DIR__ );
chdir( $repoRoot );

$defaults = [
	'enabled'         => true,
	'asciifolding'    => true,
	'elision'         => true,
	'stemmer'         => 'light_french',
	'fuzziness'       => 'auto',
	'extra_stopwords' => '',
	'stem_exclusion'  => '',
	'dual_analyzers'  => false,
];

$results = [
	'passed' => 0,
	'failed' => 0,
];

epfrOptionsEnsureCorpus();

try {
	epfrOptionsApplySettings( $defaults );
	epfrOptionsReindex();

	epfrOptionsRunCheck(
		'addon enabled = off disables custom mapping',
		static function () use ( $defaults ): void {
			$settings                   = $defaults;
			$settings['enabled']        = false;
			$settings['dual_analyzers'] = true;
			$settings['stem_exclusion'] = 'croix';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();

			$analysis = epfrOptionsGetIndexAnalysis();
			$default  = $analysis['analyzer']['default']['filter'] ?? [];

			epfrOptionsAssert( ! in_array( 'epfr_elision', $default, true ), 'Disabled addon should not inject epfr_elision.' );
			epfrOptionsAssert( ! isset( $analysis['analyzer']['epfr_heavy'] ), 'Disabled addon should not register epfr_heavy.' );
			epfrOptionsAssert( ! epfrOptionsMappingHasStemmedField( 'post_content' ), 'Disabled addon should not add .stemmed fields.' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'asciifolding toggle',
		static function () use ( $defaults ): void {
			$settings                 = $defaults;
			$settings['asciifolding'] = false;
			$settings['fuzziness']    = '0';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'café' );
			epfrOptionsAssert( in_array( 'café', $tokens, true ), 'Without asciifolding, "café" should keep its accent in analyzed tokens.' );
			epfrOptionsAssert( ! in_array( 'cafe', $tokens, true ), 'Without asciifolding, "café" should not be folded to "cafe".' );

			$settings['asciifolding'] = true;
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'café' );
			epfrOptionsAssert( in_array( 'cafe', $tokens, true ), 'With asciifolding, "café" should be folded to "cafe".' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'elision toggle',
		static function () use ( $defaults ): void {
			$settings              = $defaults;
			$settings['fuzziness'] = '0';
			$settings['elision']   = false;
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', "L'amour" );
			epfrOptionsAssert(
				in_array( "l'amou", $tokens, true ) || in_array( "l'amour", $tokens, true ),
				'Without elision, "L\'amour" should keep the leading article in tokens.'
			);
			epfrOptionsAssert( ! epfrOptionsSlugMatches( 'amour', 'epfr-lamour', 1 ), 'Without elision, "amour" should not match "L\'amour".' );

			$settings['elision'] = true;
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', "L'amour" );
			epfrOptionsAssert(
				in_array( 'amou', $tokens, true ) || in_array( 'amour', $tokens, true ),
				'With elision, "L\'amour" should analyze to the same stem as "amour".'
			);
			epfrOptionsAssert( epfrOptionsSlugMatches( 'amour', 'epfr-lamour' ), 'With elision, "amour" should match "L\'amour".' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'stemmer selector values',
		static function () use ( $defaults ): void {
			$settings            = $defaults;
			$settings['fuzziness'] = '0';
			$settings['stemmer'] = 'none';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$analysis = epfrOptionsGetIndexAnalysis();
			$default  = $analysis['analyzer']['default']['filter'] ?? [];
			epfrOptionsAssert( ! in_array( 'epfr_stemmer', $default, true ), 'Stemmer=none should remove epfr_stemmer from default.' );
			$tokens = epfrOptionsAnalyzeText( 'default', 'chevaux' );
			epfrOptionsAssert( in_array( 'chevaux', $tokens, true ), 'Stemmer=none should keep "chevaux" unchanged.' );

			$settings['stemmer'] = 'minimal_french';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			epfrOptionsAssert( 'minimal_french' === epfrOptionsGetFilterLanguage( 'epfr_stemmer' ), 'Stemmer=minimal_french should configure the matching ES filter.' );

			$settings['stemmer'] = 'light_french';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			epfrOptionsAssert( 'light_french' === epfrOptionsGetFilterLanguage( 'epfr_stemmer' ), 'Stemmer=light_french should configure the matching ES filter.' );
			$tokens = epfrOptionsAnalyzeText( 'default', 'chevaux' );
			epfrOptionsAssert( in_array( 'cheval', $tokens, true ), 'Stemmer=light_french should reduce "chevaux" to "cheval".' );

			$settings['stemmer'] = 'french';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			epfrOptionsAssert( 'french' === epfrOptionsGetFilterLanguage( 'epfr_stemmer' ), 'Stemmer=french should configure the matching ES filter.' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'fuzziness selector',
		static function () use ( $defaults ): void {
			$settings              = $defaults;
			$settings['fuzziness'] = '0';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$query = epfrOptionsCaptureFormattedQuery( 'rechreche' );
			epfrOptionsAssert( epfrOptionsQueryHasFuzziness( $query, '0' ), 'Fuzziness=0 should be reflected in the formatted search query.' );

			$settings['fuzziness'] = '1';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$query = epfrOptionsCaptureFormattedQuery( 'rechreche' );
			epfrOptionsAssert( epfrOptionsQueryHasFuzziness( $query, '1' ), 'Fuzziness=1 should be reflected in the formatted search query.' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'extra_stopwords setting',
		static function () use ( $defaults ): void {
			$settings                    = $defaults;
			$settings['extra_stopwords'] = 'cafe';
			$settings['asciifolding']    = true;
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'cafe' );
			epfrOptionsAssert( [] === $tokens, 'Adding "cafe" to extra_stopwords should remove all tokens for that query.' );

			$settings['extra_stopwords'] = '';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'cafe' );
			epfrOptionsAssert( ! empty( $tokens ), 'Without extra_stopwords, "cafe" should keep at least one token.' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'stem_exclusion setting',
		static function () use ( $defaults ): void {
			$settings                   = $defaults;
			$settings['stem_exclusion'] = '';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'La Croix' );
			epfrOptionsAssert( in_array( 'croi', $tokens, true ), 'Without stem_exclusion, "La Croix" should be stemmed to "croi".' );

			$settings['stem_exclusion'] = 'croix';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();
			$tokens = epfrOptionsAnalyzeText( 'default', 'La Croix' );
			epfrOptionsAssert( in_array( 'croix', $tokens, true ), 'With stem_exclusion=croix, "La Croix" should keep "croix".' );
			epfrOptionsAssert( ! in_array( 'croi', $tokens, true ), 'With stem_exclusion=croix, "La Croix" should no longer stem to "croi".' );
		},
		$results
	);

	epfrOptionsRunCheck(
		'dual analyzers option',
		static function () use ( $defaults ): void {
			$settings                   = $defaults;
			$settings['dual_analyzers'] = true;
			$settings['stem_exclusion'] = 'croix';
			epfrOptionsApplySettings( $settings );
			epfrOptionsReindex();

			$analysis = epfrOptionsGetIndexAnalysis();
			epfrOptionsAssert( isset( $analysis['analyzer']['epfr_heavy'] ), 'Dual mode should register epfr_heavy.' );
			epfrOptionsAssert( epfrOptionsMappingHasStemmedField( 'post_content' ), 'Dual mode should add post_content.stemmed.' );
			epfrOptionsAssert( epfrOptionsMappingHasStemmedField( 'post_title' ), 'Dual mode should add post_title.stemmed.' );

			$defaultSearchFilters = $analysis['analyzer']['default_search']['filter'] ?? [];
			epfrOptionsAssert( in_array( 'ep_synonyms_filter', $defaultSearchFilters, true ), 'Dual mode must preserve default_search synonyms.' );

			$query = epfrOptionsCaptureFormattedQuery( 'cheval' );
			epfrOptionsAssert( epfrOptionsQueryHasField( $query, 'post_content.stemmed' ), 'Dual mode HTTP query must include post_content.stemmed after weighting.' );
			epfrOptionsAssert( epfrOptionsQueryHasField( $query, 'post_title.stemmed' ), 'Dual mode HTTP query must include post_title.stemmed after weighting.' );
			epfrOptionsAssert( epfrOptionsQueryHasField( $query, 'post_excerpt.stemmed' ), 'Dual mode HTTP query must include post_excerpt.stemmed after weighting.' );
			epfrOptionsAssert( ! epfrOptionsCrossFieldsHasStemmed( $query ), 'Dual mode should keep .stemmed fields out of cross_fields clauses.' );
		},
		$results
	);
} finally {
	epfrOptionsApplySettings( $defaults );
	epfrOptionsReindex();
}

echo PHP_EOL . sprintf( 'Result: %d passed, %d failed', $results['passed'], $results['failed'] ) . PHP_EOL;

if ( $results['failed'] > 0 ) {
	exit( 1 );
}

echo 'All admin options checks passed.' . PHP_EOL;

function epfrOptionsEnsureCorpus(): void {
	$slugs = [ 'epfr-cafe', 'epfr-lamour', 'epfr-chevaux', 'epfr-recherche', 'epfr-croix' ];
	$code  = <<<'PHP'
$slugs = %s;
foreach ( $slugs as $slug ) {
	if ( ! get_page_by_path( $slug, OBJECT, 'post' ) instanceof WP_Post ) {
		echo $slug . "\n";
	}
}
PHP;
	$output = epfrOptionsWpEval( sprintf( $code, var_export( $slugs, true ) ) );
	$output = trim( $output );
	if ( '' !== $output ) {
		throw new RuntimeException( 'Missing trap posts: ' . str_replace( PHP_EOL, ', ', $output ) . '. Run: ddev composer seed:corpus' );
	}
}

function epfrOptionsApplySettings( array $settings ): void {
	$code = sprintf(
		"if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) { update_site_option( 'epfr_settings', %s ); } else { update_option( 'epfr_settings', %s ); } echo 'ok';",
		var_export( $settings, true ),
		var_export( $settings, true )
	);
	epfrOptionsWpEval( $code );
}

function epfrOptionsReindex(): void {
	epfrOptionsRunCommand( epfrOptionsWpCommandPrefix() . ' elasticpress sync --setup --yes --path=wordpress' );
	// Force a refresh so subsequent searches/analyzes see the new mapping immediately.
	// Without this, slug-match assertions can flake right after --setup.
	epfrOptionsEsPostJson( '/_refresh', [] );
}

function epfrOptionsRunCheck( string $label, callable $callback, array &$results ): void {
	try {
		$callback();
		++$results['passed'];
		echo 'PASS  ' . $label . PHP_EOL;
	} catch ( Throwable $e ) {
		++$results['failed'];
		echo 'FAIL  ' . $label . PHP_EOL;
		echo '  ' . $e->getMessage() . PHP_EOL;
	}
}

function epfrOptionsAssert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function epfrOptionsGetIndexAnalysis(): array {
	$settings = epfrOptionsEsGetJson( '/_settings' );
	$index    = epfrOptionsGetIndexName();
	return $settings[ $index ]['settings']['index']['analysis'] ?? [];
}

function epfrOptionsGetFilterLanguage( string $filterName ): string {
	$analysis = epfrOptionsGetIndexAnalysis();
	return (string) ( $analysis['filter'][ $filterName ]['language'] ?? '' );
}

function epfrOptionsMappingHasStemmedField( string $fieldName ): bool {
	$mapping = epfrOptionsEsGetJson( '/_mapping' );
	$index   = epfrOptionsGetIndexName();
	return ! empty( $mapping[ $index ]['mappings']['properties'][ $fieldName ]['fields']['stemmed'] );
}

function epfrOptionsAnalyzeText( string $analyzer, string $text ): array {
	$body   = epfrOptionsEsPostJson(
		'/_analyze',
		[
			'analyzer' => $analyzer,
			'text'     => $text,
		]
	);
	$tokens = [];

	foreach ( $body['tokens'] ?? [] as $token ) {
		if ( isset( $token['token'] ) ) {
			$tokens[] = (string) $token['token'];
		}
	}

	return $tokens;
}

function epfrOptionsSlugMatches( string $search, string $slug, int $attempts = 5 ): bool {
	$code   = sprintf(
		<<<'PHP'
$slug = %s;
$search = %s;
add_filter( 'ep_is_decaying_enabled', '__return_false' );
$post = get_page_by_path( $slug, OBJECT, 'post' );
if ( ! $post instanceof WP_Post ) {
	echo '0';
	return;
}
$query = new WP_Query(
	[
		's'                      => $search,
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'post__in'               => [ (int) $post->ID ],
		'posts_per_page'         => 1,
		'ep_integrate'           => true,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	]
);
foreach ( $query->posts as $result ) {
	if ( $result instanceof WP_Post && $result->post_name === $slug ) {
		echo '1';
		return;
	}
}
echo '0';
PHP,
		var_export( $slug, true ),
		var_export( $search, true )
	);

	$attempts = max( 1, $attempts );

	// Retry positive lookups: search-after-reindex can briefly miss on a fresh index.
	for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
		if ( '1' === trim( epfrOptionsWpEval( $code ) ) ) {
			return true;
		}
		if ( $attempt < $attempts ) {
			usleep( 200000 );
			epfrOptionsEsPostJson( '/_refresh', [] );
		}
	}

	return false;
}

function epfrOptionsCaptureFormattedQuery( string $search ): array {
	$code = sprintf(
		<<<'PHP'
$search = %s;
$captured = [];
// Capture the HTTP body that ElasticPress actually sends. Asserting only on
// ep_post_formatted_args_query is insufficient: Search weighting rewrites
// multi_match.fields afterwards and drops unknown .stemmed entries.
add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) use ( &$captured ): void {
		unset( $response, $context, $class );
		if ( false === strpos( (string) $url, '/_search' ) || empty( $args['body'] ) ) {
			return;
		}
		$body = json_decode( (string) $args['body'], true );
		if ( is_array( $body ) ) {
			$captured = $body;
		}
	},
	10,
	5
);
new WP_Query(
	[
		's'                      => $search,
		'post_type'              => 'any',
		'post_status'            => 'publish',
		'posts_per_page'         => 3,
		'ep_integrate'           => true,
		'ep_facet'               => true,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	]
);
echo wp_json_encode( $captured );
PHP,
		var_export( $search, true )
	);
	$json = epfrOptionsWpEval( $code );
	$data = json_decode( $json, true );
	return is_array( $data ) ? $data : [];
}

function epfrOptionsQueryHasField( array $query, string $expectedField ): bool {
	foreach ( epfrOptionsCollectMultiMatchNodes( $query ) as $node ) {
		foreach ( $node['fields'] ?? [] as $field ) {
			if ( is_string( $field ) && 0 === strpos( $field, $expectedField ) ) {
				return true;
			}
		}
	}
	return false;
}

function epfrOptionsQueryHasFuzziness( array $query, string $expectedFuzziness ): bool {
	foreach ( epfrOptionsCollectMultiMatchNodes( $query ) as $node ) {
		if ( ! array_key_exists( 'fuzziness', $node ) ) {
			continue;
		}
		if ( (string) $node['fuzziness'] === $expectedFuzziness ) {
			return true;
		}
	}
	return false;
}

function epfrOptionsCrossFieldsHasStemmed( array $query ): bool {
	foreach ( epfrOptionsCollectMultiMatchNodes( $query ) as $node ) {
		if ( 'cross_fields' !== ( $node['type'] ?? '' ) ) {
			continue;
		}
		foreach ( $node['fields'] ?? [] as $field ) {
			if ( is_string( $field ) && false !== strpos( $field, '.stemmed' ) ) {
				return true;
			}
		}
	}
	return false;
}

function epfrOptionsCollectMultiMatchNodes( $node ): array {
	if ( ! is_array( $node ) ) {
		return [];
	}
	$matches = [];
	if ( isset( $node['multi_match'] ) && is_array( $node['multi_match'] ) ) {
		$matches[] = $node['multi_match'];
	}
	foreach ( $node as $value ) {
		if ( is_array( $value ) ) {
			$matches = array_merge( $matches, epfrOptionsCollectMultiMatchNodes( $value ) );
		}
	}
	return $matches;
}

function epfrOptionsEsGetJson( string $path ): array {
	$code = sprintf(
		<<<'PHP'
$host = \ElasticPress\Utils\get_host();
$index = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
$response = wp_remote_get( trailingslashit( $host ) . $index . %s );
if ( is_wp_error( $response ) ) {
	fwrite( STDERR, $response->get_error_message() );
	exit( 1 );
}
echo wp_remote_retrieve_body( $response );
PHP,
		var_export( $path, true )
	);
	$data = json_decode( epfrOptionsWpEval( $code ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'Invalid JSON from Elasticsearch GET ' . $path );
	}
	return $data;
}

function epfrOptionsEsPostJson( string $path, array $payload ): array {
	$code = sprintf(
		<<<'PHP'
$host = \ElasticPress\Utils\get_host();
$index = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
$response = wp_remote_post(
	trailingslashit( $host ) . $index . %s,
	[
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( %s ),
	]
);
if ( is_wp_error( $response ) ) {
	fwrite( STDERR, $response->get_error_message() );
	exit( 1 );
}
echo wp_remote_retrieve_body( $response );
PHP,
		var_export( $path, true ),
		var_export( $payload, true )
	);
	$data = json_decode( epfrOptionsWpEval( $code ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'Invalid JSON from Elasticsearch POST ' . $path );
	}
	return $data;
}

function epfrOptionsGetIndexName(): string {
	static $indexName;
	if ( is_string( $indexName ) ) {
		return $indexName;
	}
	$indexName = trim(
		epfrOptionsWpEval(
			"echo \\ElasticPress\\Indexables::factory()->get( 'post' )->get_index_name();"
		)
	);
	return $indexName;
}

function epfrOptionsWpEval( string $code ): string {
	$tmp = tempnam( sys_get_temp_dir(), 'epfr-options-' );
	if ( false === $tmp ) {
		throw new RuntimeException( 'Unable to create temporary file.' );
	}

	file_put_contents( $tmp, "<?php\n" . $code . "\n" );

	try {
		return epfrOptionsRunCommand(
			sprintf(
				'%s eval-file %s --path=wordpress',
				epfrOptionsWpCommandPrefix(),
				escapeshellarg( $tmp )
			)
		);
	} finally {
		@unlink( $tmp );
	}
}

function epfrOptionsRunCommand( string $command ): string {
	$descriptorSpec = [
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];
	$process = proc_open( $command, $descriptorSpec, $pipes, dirname( __DIR__ ) );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start command: ' . $command );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$exitCode = proc_close( $process );
	if ( 0 !== $exitCode ) {
		throw new RuntimeException(
			sprintf(
				"Command failed (%d): %s\n%s",
				$exitCode,
				$command,
				trim( $stderr ?: $stdout )
			)
		);
	}

	return (string) $stdout;
}

function epfrOptionsWpCommandPrefix(): string {
	if ( false !== getenv( 'IS_DDEV_PROJECT' ) || false !== getenv( 'DDEV_PRIMARY_URL' ) ) {
		return 'wp';
	}

	return 'ddev wp';
}
