<?php
/**
 * Désinstallation du plugin : supprime les options créées.
 * N'affecte jamais le cluster Elasticsearch ni les index existants.
 *
 * @package ElasticPress_French_Addon
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'epfr_settings' );

if ( is_multisite() ) {
	delete_site_option( 'epfr_settings' );
}
