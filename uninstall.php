<?php
/**
 * Uninstall the plugin: remove options created by the addon.
 * Never touches the Elasticsearch cluster or existing indexes.
 *
 * @package ElasticPress_French_Addon
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'epfr_settings' );
delete_site_option( 'epfr_settings' );

if ( is_multisite() ) {
	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $site_ids as $site_id ) {
		delete_blog_option( (int) $site_id, 'epfr_settings' );
	}
}
