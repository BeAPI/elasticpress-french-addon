<?php
/**
 * Seed WordPress with French search corpus posts (traps + bulk).
 *
 * Usage (from repo root, with WP path):
 *   wp eval-file bin/seed-search-corpus.php --path=wordpress
 *   wp eval-file bin/seed-search-corpus.php epfr-purge --path=wordpress
 *
 * @package ElasticPress_French_Addon
 */

// Note: no declare(strict_types=1) — wp eval-file uses eval() and rejects it.

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run via: wp eval-file bin/seed-search-corpus.php\n");
    exit(1);
}

$root = defined('EPFR_PLUGIN_DIR') ? rtrim(EPFR_PLUGIN_DIR, '/\\') : dirname(__DIR__);
$traps_path = $root . '/tests/fixtures/french-search-traps.json';
$bulk_path  = $root . '/tests/fixtures/french-search-bulk.json.gz';
$cli_args   = array_merge($GLOBALS['argv'] ?? [], $_SERVER['argv'] ?? []);
$purge      = in_array('--purge', $cli_args, true) || in_array('epfr-purge', $cli_args, true);

if (! is_file($traps_path)) {
    WP_CLI::error("Missing traps fixture: {$traps_path}");
}

if (! is_file($bulk_path)) {
    WP_CLI::error("Missing bulk fixture: {$bulk_path}. Run: composer fetch:bulk (or php bin/fetch-bulk-corpus.php)");
}

$traps = json_decode((string) file_get_contents($traps_path), true);
if (! is_array($traps) || empty($traps['posts']) || ! is_array($traps['posts'])) {
    WP_CLI::error('Invalid traps fixture JSON.');
}

$bulk_raw = file_get_contents($bulk_path);
if (false === $bulk_raw) {
    WP_CLI::error("Unable to read {$bulk_path}");
}
$bulk_json = gzdecode($bulk_raw);
if (false === $bulk_json) {
    WP_CLI::error("Unable to gunzip {$bulk_path}");
}
$bulk = json_decode($bulk_json, true);
if (! is_array($bulk) || empty($bulk['posts']) || ! is_array($bulk['posts'])) {
    WP_CLI::error('Invalid bulk fixture JSON.');
}

if (! defined('WP_IMPORTING')) {
    define('WP_IMPORTING', true);
}
wp_defer_term_counting(true);
wp_suspend_cache_invalidation(true);
add_filter('ep_sync_indexable_kill', '__return_true');

if ($purge) {
    $deleted = epfr_purge_corpus_posts();
    WP_CLI::log(sprintf('Purged %d corpus posts.', $deleted));
}

$created = 0;
$updated = 0;

foreach ($traps['posts'] as $post) {
    $result = epfr_upsert_corpus_post($post, 'trap');
    if ('created' === $result) {
        ++$created;
    } elseif ('updated' === $result) {
        ++$updated;
    }
}

foreach ($bulk['posts'] as $post) {
    $result = epfr_upsert_corpus_post($post, 'bulk');
    if ('created' === $result) {
        ++$created;
    } elseif ('updated' === $result) {
        ++$updated;
    }
}

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);

WP_CLI::success(
    sprintf(
        'Corpus seeded: %d created, %d updated (traps=%d, bulk=%d). Run elasticpress sync --setup next.',
        $created,
        $updated,
        count($traps['posts']),
        count($bulk['posts'])
    )
);

/**
 * @param  array<string, mixed> $post
 * @return string created|updated|skipped
 */
function epfr_upsert_corpus_post(array $post, string $kind): string
{
    $slug  = isset($post['slug']) ? sanitize_title((string) $post['slug']) : '';
    $title = isset($post['title']) ? (string) $post['title'] : '';
    $body  = isset($post['content']) ? (string) $post['content'] : '';

    if ('' === $slug || '' === $title) {
        return 'skipped';
    }

    $existing = get_page_by_path($slug, OBJECT, 'post');
    $data     = [
    'post_title'    => wp_strip_all_tags($title),
    'post_name'     => $slug,
    'post_content'  => $body,
    'post_status'   => 'publish',
    'post_type'     => 'post',
    'post_date'     => current_time('mysql'),
    'post_date_gmt' => current_time('mysql', true),
    ];

    if ($existing instanceof WP_Post) {
        $data['ID'] = $existing->ID;
        $post_id    = wp_update_post($data, true);
        if (is_wp_error($post_id)) {
            WP_CLI::warning($post_id->get_error_message());
            return 'skipped';
        }
        update_post_meta((int) $post_id, '_epfr_corpus', '1');
        update_post_meta((int) $post_id, '_epfr_corpus_kind', $kind);
        return 'updated';
    }

    $post_id = wp_insert_post($data, true);
    if (is_wp_error($post_id)) {
        WP_CLI::warning($post_id->get_error_message());
        return 'skipped';
    }

    update_post_meta((int) $post_id, '_epfr_corpus', '1');
    update_post_meta((int) $post_id, '_epfr_corpus_kind', $kind);
    return 'created';
}

function epfr_purge_corpus_posts(): int
{
    $query = new WP_Query(
        [
        'post_type'              => 'post',
        'post_status'            => 'any',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'meta_key'               => '_epfr_corpus',
        'meta_value'             => '1',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'ep_integrate'           => false,
        ]
    );

    $deleted = 0;
    foreach ($query->posts as $post_id) {
        $result = wp_delete_post((int) $post_id, true);
        if ($result) {
            ++$deleted;
        }
    }

    return $deleted;
}
