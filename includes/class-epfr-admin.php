<?php
/**
 * Plugin admin interface.
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

namespace ElasticPress_French_Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {


	private static ?Admin $instance = null;

	public static function instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'ep_admin_notices', [ $this, 'settings_language_notice' ] );
	}

	/**
	 * Warn on the ElasticPress Settings screen that Language is overridden.
	 *
	 * @param  array<string, array<string, mixed>> $notices ElasticPress admin notices.
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_language_notice( array $notices ): array {
		if ( empty( Settings::get()['enabled'] ) ) {
			return $notices;
		}

		if ( ! class_exists( '\ElasticPress\Screen' ) ) {
			return $notices;
		}

		if ( 'settings' !== \ElasticPress\Screen::factory()->get_current_screen() ) {
			return $notices;
		}

		$addon_url = admin_url( 'admin.php?page=elasticpress-french-addon' );

		$notices['epfr_language_forced'] = [
			'html'    => sprintf(
				/* translators: %s: URL to the French Addon settings screen */
				__( 'ElasticPress French Addon is active: the Elasticsearch analyzer language is forced to French (including stopwords), regardless of the Language setting below. <a href="%s">Manage French Addon settings</a>.', 'elasticpress-french-addon' ),
				esc_url( $addon_url )
			),
			'type'    => 'warning',
			'dismiss' => false,
			'scope'   => 'site',
		];

		return $notices;
	}

	/**
	 * Add the settings page under the native ElasticPress menu when available,
	 * otherwise as a standalone settings page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'elasticpress',
			__( 'French Addon', 'elasticpress-french-addon' ),
			__( 'French Addon', 'elasticpress-french-addon' ),
			'manage_options',
			'elasticpress-french-addon',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'epfr_settings_group',
			EPFR_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::get_defaults(),
			]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ElasticPress French Addon', 'elasticpress-french-addon' ); ?></h1>

			<div class="notice notice-info">
				<p>
		<?php esc_html_e( 'Any change below only applies to new indexes. A full reindex is required after saving:', 'elasticpress-french-addon' ); ?>
					<code>wp elasticpress index --setup --network-wide</code>
				</p>
				<p>
		<?php esc_html_e( 'While the addon is enabled, the Elasticsearch analyzer language is forced to French (including stopwords), regardless of the ElasticPress Language setting.', 'elasticpress-french-addon' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
		<?php settings_fields( 'epfr_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Addon enabled', 'elasticpress-french-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( 'Apply French corrections to the ElasticPress analyzer', 'elasticpress-french-addon' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Asciifolding', 'elasticpress-french-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[asciifolding]" value="1" <?php checked( ! empty( $settings['asciifolding'] ) ); ?> />
								<?php esc_html_e( 'Ignore accents at index and search time (recommended: "haiti" and "haïti" should return the same results)', 'elasticpress-french-addon' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Elision', 'elasticpress-french-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[elision]" value="1" <?php checked( ! empty( $settings['elision'] ) ); ?> />
								<?php esc_html_e( 'Handle French elision (l\'article, d\'un, qu\'il…)', 'elasticpress-french-addon' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="epfr_stemmer"><?php esc_html_e( 'Stemmer', 'elasticpress-french-addon' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[stemmer]" id="epfr_stemmer">
								<option value="none" <?php selected( $settings['stemmer'], 'none' ); ?>><?php esc_html_e( 'None (exact matches only)', 'elasticpress-french-addon' ); ?></option>
								<option value="minimal_french" <?php selected( $settings['stemmer'], 'minimal_french' ); ?>><?php esc_html_e( 'Minimal (obvious plurals only)', 'elasticpress-french-addon' ); ?></option>
								<option value="light_french" <?php selected( $settings['stemmer'], 'light_french' ); ?>><?php esc_html_e( 'Light (recommended — plurals, feminine forms, simple conjugations)', 'elasticpress-french-addon' ); ?></option>
								<option value="french" <?php selected( $settings['stemmer'], 'french' ); ?>><?php esc_html_e( 'Full Snowball (aggressive — risk of collisions between distinct words)', 'elasticpress-french-addon' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The "Full Snowball" stemmer often truncates words to 4–5 letters and can return unrelated results (e.g. "haine" and "haute" confused with "Haïti"). "Light" is the best compromise for most sites.', 'elasticpress-french-addon' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="epfr_fuzziness"><?php esc_html_e( 'Fuzziness (typo tolerance)', 'elasticpress-french-addon' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[fuzziness]" id="epfr_fuzziness">
								<option value="auto" <?php selected( $settings['fuzziness'], 'auto' ); ?>><?php esc_html_e( 'Auto (ElasticPress default)', 'elasticpress-french-addon' ); ?></option>
								<option value="0" <?php selected( $settings['fuzziness'], '0' ); ?>><?php esc_html_e( '0 — Disabled (strict matching)', 'elasticpress-french-addon' ); ?></option>
								<option value="1" <?php selected( $settings['fuzziness'], '1' ); ?>><?php esc_html_e( '1 — Tolerate one typo', 'elasticpress-french-addon' ); ?></option>
								<option value="2" <?php selected( $settings['fuzziness'], '2' ); ?>><?php esc_html_e( '2 — Tolerate two typos', 'elasticpress-french-addon' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Lower this only if asciifolding and the light stemmer are not enough to remove noise from results.', 'elasticpress-french-addon' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="epfr_extra_stopwords"><?php esc_html_e( 'Additional stopwords', 'elasticpress-french-addon' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[extra_stopwords]" id="epfr_extra_stopwords" rows="3" class="large-text"><?php echo esc_textarea( $settings['extra_stopwords'] ); ?></textarea>
							<p class="description">
								<?php
								printf(
									wp_kses(
										/* translators: 1: URL to the official Elastic stop token filter documentation, 2: URL to the Lucene French stopwords file. */
										__( 'Comma-separated list of words to ignore on top of the <a href="%1$s" target="_blank" rel="noopener noreferrer">standard French stopword list</a> (see also the <a href="%2$s" target="_blank" rel="noopener noreferrer">direct Lucene source file</a>) (e.g. company name if too frequent in content).', 'elasticpress-french-addon' ),
										[
											'a' => [
												'href'   => [],
												'target' => [],
												'rel'    => [],
											],
										]
									),
									esc_url( 'https://www.elastic.co/docs/reference/text-analysis/analysis-stop-tokenfilter' ),
									esc_url( 'https://github.com/apache/lucene/blob/main/lucene/analysis/common/src/resources/org/apache/lucene/analysis/snowball/french_stop.txt' )
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="epfr_stem_exclusion"><?php esc_html_e( 'Stem exclusion', 'elasticpress-french-addon' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[stem_exclusion]" id="epfr_stem_exclusion" rows="3" class="large-text"><?php echo esc_textarea( $settings['stem_exclusion'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Comma-separated words that must not be stemmed (keyword_marker). Classic example: exclude "croix" so a search for "croissant" does not match the brand "La Croix". Has no effect when Stemmer is set to None.', 'elasticpress-french-addon' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Dual analyzers (light / heavy)', 'elasticpress-french-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( EPFR_OPTION_KEY ); ?>[dual_analyzers]" value="1" <?php checked( ! empty( $settings['dual_analyzers'] ) ); ?> />
								<?php esc_html_e( 'Use a light analyzer on main text fields (precision) and a heavy stemmed analyzer on .stemmed multi-fields (recall)', 'elasticpress-french-addon' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Inspired by the dual french_light / french_heavy approach. Applies to post_title, post_content, and post_excerpt. Search queries automatically include the .stemmed fields at a lower boost. Has no effect when Stemmer is set to None. Requires a full reindex.', 'elasticpress-french-addon' ); ?></p>
						</td>
					</tr>

				</table>

		<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Verify the result', 'elasticpress-french-addon' ); ?></h2>
			<p>
		<?php esc_html_e( 'After reindexing, test the analyzer directly via the Elasticsearch API:', 'elasticpress-french-addon' ); ?>
			</p>
			<pre>curl -s 'http://YOUR_CLUSTER:9200/YOUR_INDEX/_analyze' \
  -H 'Content-Type: application/json' \
  -d '{"analyzer":"default","text":"Haïti haïti haiti"}'</pre>
			<p><?php esc_html_e( 'All three forms should produce the same token.', 'elasticpress-french-addon' ); ?></p>
		</div>
		<?php
	}
}
