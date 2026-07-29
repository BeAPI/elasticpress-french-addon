<?php
/**
 * Enable or disable ElasticPress French Addon settings.
 *
 * Usage:
 *   wp eval-file bin/toggle-addon.php epfr-enabled-0 --path=wordpress
 *   wp eval-file bin/toggle-addon.php epfr-enabled-1 --path=wordpress
 *
 * @package ElasticPress_French_Addon
 */

// Note: no declare(strict_types=1) — wp eval-file uses eval() and rejects it.

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run via: wp eval-file bin/toggle-addon.php\n");
    exit(1);
}

$enabled = null;
foreach (array_merge($GLOBALS['argv'] ?? [], $_SERVER['argv'] ?? []) as $arg) {
    if ('epfr-enabled-0' === $arg || '--enabled=0' === $arg) {
        $enabled = '0';
    } elseif ('epfr-enabled-1' === $arg || '--enabled=1' === $arg) {
        $enabled = '1';
    } elseif (0 === strpos($arg, '--enabled=')) {
        $enabled = substr($arg, strlen('--enabled='));
    }
}

if (null === $enabled || ! in_array($enabled, [ '0', '1' ], true)) {
    WP_CLI::error('Usage: wp eval-file bin/toggle-addon.php epfr-enabled-0|epfr-enabled-1 --path=wordpress');
}

if (! defined('EPFR_OPTION_KEY')) {
    define('EPFR_OPTION_KEY', 'epfr_settings');
}

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

if (class_exists('\\ElasticPress_French_Addon\\Settings')) {
    $defaults = \ElasticPress_French_Addon\Settings::get_defaults();
}

$current = get_option(EPFR_OPTION_KEY, []);
if (! is_array($current)) {
    $current = [];
}

$settings            = wp_parse_args($current, $defaults);
$settings['enabled'] = ('1' === $enabled);

if ('1' === $enabled) {
    // Restore recommended defaults when re-enabling for compare runs.
    $settings['asciifolding']    = true;
    $settings['elision']         = true;
    $settings['stemmer']         = 'light_french';
    $settings['fuzziness']       = 'auto';
    $settings['extra_stopwords'] = '';
    $settings['stem_exclusion']  = '';
    $settings['dual_analyzers']  = false;
}

update_option(EPFR_OPTION_KEY, $settings);

WP_CLI::success(
    sprintf(
        'Addon %s (asciifolding=%s, elision=%s, stemmer=%s, fuzziness=%s). Reindex with: wp elasticpress sync --setup --yes',
        $settings['enabled'] ? 'ENABLED' : 'DISABLED',
        $settings['asciifolding'] ? 'on' : 'off',
        $settings['elision'] ? 'on' : 'off',
        $settings['stemmer'],
        $settings['fuzziness']
    )
);
