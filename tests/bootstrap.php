<?php
/**
 * PHPUnit bootstrap (no WordPress).
 *
 * @package ElasticPress_French_Addon
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'EPFR_OPTION_KEY', 'epfr_settings' );

require_once dirname( __DIR__ ) . '/includes/class-epfr-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-epfr-analyzer.php';
