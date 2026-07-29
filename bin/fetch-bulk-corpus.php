<?php
/**
 * Fetch short French Wikipedia extracts into a compressed bulk corpus.
 *
 * Usage:
 *   php bin/fetch-bulk-corpus.php [--count=980] [--resume] [--output=path]
 *
 * @package ElasticPress_French_Addon
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$root   = dirname(__DIR__);
$args   = epfr_parse_cli_args($argv ?? []);
$count  = isset($args['count']) ? max(1, (int) $args['count']) : 980;
$resume = isset($args['resume']);
$output = $args['output'] ?? $root . '/tests/fixtures/french-search-bulk.json.gz';

$posts    = [];
$seen_ids = [];

if ($resume && is_file($output)) {
    $existing = epfr_load_bulk_file($output);
    foreach ($existing as $post) {
        if (! isset($post['pageid'])) {
            continue;
        }
        $page_id            = (int) $post['pageid'];
        $seen_ids[$page_id] = true;
        $posts[]            = $post;
    }
    fwrite(STDOUT, sprintf("Resuming with %d existing entries.\n", count($posts)));
}

$user_agent = 'ElasticPressFrenchAddon/1.0 (https://github.com/beapi/elasticpress-french-addon; corpus-builder)';
$endpoint   = 'https://fr.wikipedia.org/w/api.php';
$batch_size = 20;
$attempts   = 0;
$max_attempts = (int) max(50, $count * 3);

while (count($posts) < $count && $attempts < $max_attempts) {
    ++$attempts;
    $query = http_build_query(
        [
        'action'        => 'query',
        'format'        => 'json',
        'generator'     => 'random',
        'grnnamespace'  => 0,
        'grnlimit'      => $batch_size,
        'prop'          => 'extracts|info',
        'exintro'       => 1,
        'explaintext'   => 1,
        'exchars'       => 500,
        'inprop'        => 'url',
        ]
    );

    $url  = $endpoint . '?' . $query;
    $json = epfr_http_get_json($url, $user_agent);
    if (null === $json) {
        fwrite(STDERR, "Request failed, retrying…\n");
        usleep(500000);
        continue;
    }

    $pages = $json['query']['pages'] ?? [];
    foreach ($pages as $page) {
        if (count($posts) >= $count) {
            break;
        }

        $page_id = isset($page['pageid']) ? (int) $page['pageid'] : 0;
        $title   = isset($page['title']) ? (string) $page['title'] : '';
        $extract = isset($page['extract']) ? trim((string) $page['extract']) : '';
        $fullurl = isset($page['fullurl']) ? (string) $page['fullurl'] : '';

        if ($page_id <= 0 || isset($seen_ids[$page_id])) {
            continue;
        }
        if ('' === $title || false !== stripos($title, '(homonymie)')) {
            continue;
        }
        if (mb_strlen($extract) < 200) {
            continue;
        }

        // Drop truncated ellipsis noise at the end of MediaWiki extracts.
        $extract = rtrim($extract, " \t\n\r\0\x0B….");
        $title   = epfr_utf8_clean($title);
        $extract = epfr_utf8_clean($extract);

        $seen_ids[$page_id] = true;
        $posts[]            = [
        'slug'       => 'epfr-bulk-' . $page_id,
        'title'      => $title,
        'content'    => $extract . "\n\nSource : " . $fullurl . ' — CC BY-SA 4.0',
        'source_url' => $fullurl,
        'pageid'     => $page_id,
        ];
    }

    fwrite(
        STDOUT,
        sprintf(
            "Fetched batch %d — kept %d / %d\n",
            $attempts,
            count($posts),
            $count
        )
    );

    usleep(250000);
    epfr_write_bulk_file($output, $posts);
}

if (count($posts) < $count) {
    fwrite(
        STDERR,
        sprintf(
            "Stopped early with %d / %d posts after %d attempts.\n",
            count($posts),
            $count,
            $attempts
        )
    );
    exit(1);
}

epfr_write_bulk_file($output, $posts);
fwrite(STDOUT, sprintf("Wrote %d posts to %s\n", count($posts), $output));
exit(0);

/**
 * @param  array<int, string> $argv
 * @return array<string, string|bool>
 */
function epfr_parse_cli_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (0 !== strpos($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (false === strpos($arg, '=')) {
            $args[$arg] = true;
            continue;
        }
        [$key, $value] = explode('=', $arg, 2);
        $args[$key]    = $value;
    }
    return $args;
}

/**
 * @return array<int, array<string, mixed>>
 */
function epfr_load_bulk_file(string $path): array
{
    $raw = file_get_contents($path);
    if (false === $raw) {
        return [];
    }

    if (substr($path, -3) === '.gz') {
        $decoded = gzdecode($raw);
        if (false === $decoded) {
            return [];
        }
        $raw = $decoded;
    }

    $data = json_decode($raw, true);
    if (! is_array($data)) {
        return [];
    }

    if (isset($data['posts']) && is_array($data['posts'])) {
        return $data['posts'];
    }

    return array_values($data);
}

/**
 * @param array<int, array<string, mixed>> $posts
 */
function epfr_write_bulk_file(string $path, array $posts): void
{
    $dir = dirname($path);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        fwrite(STDERR, "Unable to create directory: {$dir}\n");
        exit(1);
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $payload = json_encode(
        [
        'version'      => 1,
        'source'       => 'fr.wikipedia.org',
        'license'      => 'CC BY-SA 4.0',
        'generated_at' => gmdate('c'),
        'count'        => count($posts),
        'posts'        => array_values($posts),
        ],
        $flags
    );

    if (false === $payload) {
        fwrite(STDERR, 'JSON encode failed: ' . json_last_error_msg() . "\n");
        exit(1);
    }

    if (substr($path, -3) === '.gz') {
        $payload = gzencode($payload, 9);
        if (false === $payload) {
            fwrite(STDERR, "gzip encode failed.\n");
            exit(1);
        }
    }

    if (false === file_put_contents($path, $payload)) {
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }
}

/**
 * @return array<string, mixed>|null
 */
function epfr_http_get_json(string $url, string $user_agent): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (false === $ch) {
            return null;
        }
        curl_setopt_array(
            $ch,
            [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => $user_agent,
            CURLOPT_HTTPHEADER     => [ 'Accept: application/json' ],
            ]
        );
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if (false === $body || $code < 200 || $code >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create(
            [
            'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: {$user_agent}\r\nAccept: application/json\r\n",
            'timeout' => 30,
            ],
            ]
        );
        $body = @file_get_contents($url, false, $context);
        if (false === $body) {
            return null;
        }
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Normalize string to valid UTF-8 for JSON encoding.
 */
function epfr_utf8_clean(string $value): string
{
    if (function_exists('iconv')) {
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (false !== $cleaned) {
            return $cleaned;
        }
    }

    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return $value;
}
