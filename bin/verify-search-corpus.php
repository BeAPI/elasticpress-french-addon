<?php
/**
 * Verify French search corpus trap queries against ElasticPress.
 *
 * Usage:
 *   wp eval-file bin/verify-search-corpus.php epfr-profile-addon --path=wordpress
 *   wp eval-file bin/verify-search-corpus.php epfr-profile-baseline --path=wordpress
 *
 * Assertions are scoped to trap posts (not top-N of the full noisy index):
 * - expect_include: each slug must match the query via ElasticPress (post__in check)
 * - expect_exclude: excluded slugs must not appear among trap matches for the query
 *
 * Optional EPFR_VERIFY_PROFILE=addon|baseline environment variable.
 * Optional per-query "fuzziness" in the traps JSON overrides ep_post_fuzziness_arg.
 *
 * @package ElasticPress_French_Addon
 */

// Note: no declare(strict_types=1) — wp eval-file uses eval() and rejects it.

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run via: wp eval-file bin/verify-search-corpus.php\n");
    exit(1);
}

$root       = defined('EPFR_PLUGIN_DIR') ? rtrim(EPFR_PLUGIN_DIR, '/\\') : dirname(__DIR__);
$traps_path = $root . '/tests/fixtures/french-search-traps.json';
$profile    = getenv('EPFR_VERIFY_PROFILE') ?: 'addon';

foreach (array_merge($GLOBALS['argv'] ?? [], $_SERVER['argv'] ?? []) as $arg) {
    if ('epfr-profile-baseline' === $arg) {
        $profile = 'baseline';
    } elseif ('epfr-profile-addon' === $arg) {
        $profile = 'addon';
    }
}

if (! in_array($profile, [ 'addon', 'baseline' ], true)) {
    WP_CLI::error('Invalid EPFR_VERIFY_PROFILE. Use addon or baseline.');
}

if (! is_file($traps_path)) {
    WP_CLI::error("Missing traps fixture: {$traps_path}");
}

$traps = json_decode((string) file_get_contents($traps_path), true);
if (! is_array($traps) || empty($traps['queries']) || ! is_array($traps['queries'])) {
    WP_CLI::error('Invalid traps fixture JSON (queries missing).');
}

$trap_posts = isset($traps['posts']) && is_array($traps['posts']) ? $traps['posts'] : [];
$slug_to_id = [];
foreach ($trap_posts as $post) {
    $slug = isset($post['slug']) ? sanitize_title((string) $post['slug']) : '';
    if ('' === $slug) {
        continue;
    }
    $existing = get_page_by_path($slug, OBJECT, 'post');
    if ($existing instanceof WP_Post) {
        $slug_to_id[ $slug ] = (int) $existing->ID;
    }
}

if (count($slug_to_id) < count($trap_posts)) {
    WP_CLI::warning(
        sprintf(
            'Only %d / %d trap posts found in WordPress. Run: composer seed:corpus',
            count($slug_to_id),
            count($trap_posts)
        )
    );
}

$all_trap_ids = array_values($slug_to_id);

// Date decay demotes older traps under newer bulk noise; disable for deterministic asserts.
add_filter('ep_is_decaying_enabled', '__return_false');

WP_CLI::log(sprintf('Profile: %s', $profile));
WP_CLI::log(sprintf('Running %d trap queries…', count($traps['queries'])));

$passed = 0;
$failed = 0;

foreach ($traps['queries'] as $query) {
    $id              = isset($query['id']) ? (string) $query['id'] : 'unknown';
    $case            = isset($query['case']) ? (string) $query['case'] : '';
    $search          = isset($query['s']) ? (string) $query['s'] : '';
    $expect_include  = isset($query['expect_include']) && is_array($query['expect_include']) ? $query['expect_include'] : [];
    $expect_exclude  = isset($query['expect_exclude']) && is_array($query['expect_exclude']) ? $query['expect_exclude'] : [];
    $query_fuzziness = array_key_exists('fuzziness', $query) ? $query['fuzziness'] : null;

    $fuzziness_filter = null;
    if (null !== $query_fuzziness) {
        $fuzziness_filter = static function () use ($query_fuzziness) {
            return $query_fuzziness;
        };
        add_filter('ep_post_fuzziness_arg', $fuzziness_filter, 1000);
    }

    $missing    = [];
    $unexpected = [];
    $hit_slugs  = [];
    $ep_ok      = true;

    // Inclusion: each expected trap must match the query on its own (analyzer presence).
    foreach ($expect_include as $slug) {
        $post_id = $slug_to_id[ $slug ] ?? 0;
        if ($post_id <= 0) {
            $missing[] = $slug . ' (missing post)';
            continue;
        }

        $single = epfr_verify_search_query(
            $search,
            [ $post_id ],
            1
        );

        if (empty($single->elasticsearch_success)) {
            $ep_ok = false;
        }

        $matched = false;
        foreach ($single->posts as $post) {
            if ($post instanceof WP_Post && $post->post_name === $slug) {
                $matched = true;
                $hit_slugs[] = $slug;
                break;
            }
        }

        if (! $matched) {
            $missing[] = $slug;
        }
    }

    // Exclusion: among trap posts only, excluded slugs must not match.
    if (! empty($expect_exclude) && ! empty($all_trap_ids)) {
        $among_traps = epfr_verify_search_query(
            $search,
            $all_trap_ids,
            count($all_trap_ids)
        );

        if (empty($among_traps->elasticsearch_success)) {
            $ep_ok = false;
        }

        $trap_hit_slugs = [];
        foreach ($among_traps->posts as $post) {
            if ($post instanceof WP_Post) {
                $trap_hit_slugs[] = $post->post_name;
                $hit_slugs[]      = $post->post_name;
            }
        }
        $hit_slugs = array_values(array_unique($hit_slugs));

        foreach ($expect_exclude as $slug) {
            if (in_array($slug, $trap_hit_slugs, true)) {
                $unexpected[] = $slug;
            }
        }
    }

    if (null !== $fuzziness_filter) {
        remove_filter('ep_post_fuzziness_arg', $fuzziness_filter, 1000);
    }

    if (! $ep_ok) {
        ++$failed;
        WP_CLI::warning(
            sprintf(
                'FAIL  [%s] %s  s="%s" (ElasticPress did not serve the query)',
                $case,
                $id,
                $search
            )
        );
        continue;
    }

    $ok = empty($missing) && empty($unexpected);
    if ($ok) {
        ++$passed;
        WP_CLI::log(
            sprintf(
                'PASS  [%s] %s  s="%s"  trap_hits=%s',
                $case,
                $id,
                $search,
                empty($hit_slugs) ? '(none)' : implode(',', array_unique($hit_slugs))
            )
        );
        continue;
    }

    ++$failed;
    WP_CLI::warning(
        sprintf(
            'FAIL  [%s] %s  s="%s"',
            $case,
            $id,
            $search
        )
    );
    if (! empty($missing)) {
        WP_CLI::log('  missing include: ' . implode(', ', $missing));
    }
    if (! empty($unexpected)) {
        WP_CLI::log('  unexpected exclude hits: ' . implode(', ', $unexpected));
    }
    WP_CLI::log('  trap hits: ' . (empty($hit_slugs) ? '(none)' : implode(', ', array_unique($hit_slugs))));
}

WP_CLI::log('');
WP_CLI::log(sprintf('Result (%s): %d passed, %d failed', $profile, $passed, $failed));

if ($failed > 0) {
    if ('baseline' === $profile) {
        WP_CLI::warning('Baseline verification reported failures (expected without the addon).');
        exit(0);
    }
    WP_CLI::error('Corpus verification failed.', false);
    exit(1);
}

WP_CLI::success('All trap queries passed.');

/**
 * Run an ElasticPress-backed search limited to the given post IDs.
 *
 * @param  string $search   Search string.
 * @param  int[]  $post_in  Post IDs.
 * @param  int    $per_page Posts per page.
 * @return WP_Query
 */
function epfr_verify_search_query(string $search, array $post_in, int $per_page): WP_Query
{
    return new WP_Query(
        [
        's'                      => $search,
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'post__in'               => array_map('intval', $post_in),
        'posts_per_page'         => max(1, $per_page),
        'ep_integrate'           => true,
        'ignore_sticky_posts'    => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        ]
    );
}
