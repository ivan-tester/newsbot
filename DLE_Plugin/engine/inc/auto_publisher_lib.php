<?php
// Shared helpers for the Auto Publisher plugin.
// Included by both engine/modules/auto_publisher.php (the API endpoint)
// and engine/inc/auto_publisher_admin.php (the settings page).

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

if (!defined('AP_CONFIG_FILE')) {
    define('AP_CONFIG_FILE', ROOT_DIR . '/engine/data/auto_publisher_config.php');
}

// Not read from auto_publisher.xml's own <version> at runtime (the running
// PHP has no access to its own install manifest) — bump this by hand
// alongside <version> on every release. Exposed via the ping response so
// the bot can refuse to publish to a site running a plugin too old to
// support whatever the bot is about to send it, instead of just failing.
if (!defined('AP_PLUGIN_VERSION')) {
    define('AP_PLUGIN_VERSION', '1.12.0');
}

function ap_default_config()
{
    return [
        'token' => '',
        'category' => 0,
        // Only used when category_multi is on — a category ID list applied
        // when the bot doesn't send its own per-item override (see
        // ap_resolve_categories() below).
        'categories' => [],
        // Off by default (backward compatible): a post gets exactly one
        // category (the classic single-`category` field above). On: the
        // bot may send several category IDs for one post, comma-joined
        // into dle_post.category (DLE's own column already supports this
        // natively — see .claude/skills/dle-plugin/SKILL.md).
        'category_multi' => false,
        'author' => 'AutoPublisherBot',
        'auto_approve' => true,
        // How a generated cover image (see ap_save_image()) gets attached to
        // the post: 'body' prepends an <img> tag to short_story/full_story
        // (original behavior); 'xfields_text'/'xfields_image' write it into
        // a category custom field (dle_post.xfields) instead — the field
        // itself must already exist in the category's settings
        // (admin.php?mod=xfields), this plugin only fills its value.
        'image_mode' => 'body',
        'image_xfield_name' => 'image',
    ];
}

// True once a usable default category exists for the configured mode —
// shared between the admin page's own "not configured yet" warning and the
// publish endpoint's category_not_configured check, so the two can't drift.
function ap_category_configured($cfg)
{
    if (!empty($cfg['category_multi'])) {
        return !empty($cfg['categories']) && is_array($cfg['categories']);
    }
    return (int)$cfg['category'] > 0;
}

// Builds the { id => name } map of the site's real categories (from the
// $cat_info global DLE already populates for every module — see the skill
// doc's "Available globals inside a module") — used both by the admin
// page's own <select> and by the ping response, so a "🔄 Синхронизировать с
// DLE" tap on the bot side sees exactly the categories that actually exist.
function ap_categories_map($catInfo)
{
    $out = [];
    if (!empty($catInfo) && is_array($catInfo)) {
        foreach ($catInfo as $id => $cat) {
            $out[(int)$id] = isset($cat['name']) ? (string)$cat['name'] : ('Категория ' . (int)$id);
        }
    }
    ksort($out);
    return $out;
}

// Picks which category ID(s) a new post gets, as a comma-joined string
// ready for dle_post.category — DLE's own column format for multiple
// categories (see .claude/skills/dle-plugin/SKILL.md). `requested` is
// whatever the bot's own request provided (single int, array of ints, or
// absent/null) — an explicit per-item choice always wins over the
// configured default, but only using IDs that actually exist in
// $categoryMap; if every requested ID turns out invalid, falls back to the
// configured default exactly as if nothing had been requested. Returns ''
// if nothing usable is available either way (caller treats that as
// category_not_configured).
function ap_resolve_categories($cfg, $requested, $categoryMap)
{
    if ($requested !== null && $requested !== '') {
        $ids = is_array($requested) ? $requested : [$requested];
        $valid = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($categoryMap[$id])) {
                $valid[] = $id;
            }
        }
        if (!empty($valid)) {
            return implode(',', array_unique($valid));
        }
    }
    if (!empty($cfg['category_multi'])) {
        $ids = array_filter(array_map('intval', (array)$cfg['categories']), function ($id) use ($categoryMap) {
            return $id > 0 && isset($categoryMap[$id]);
        });
        return implode(',', array_unique($ids));
    }
    $id = (int)$cfg['category'];
    return ($id > 0 && isset($categoryMap[$id])) ? (string)$id : '';
}

function ap_load_config()
{
    if (!is_file(AP_CONFIG_FILE)) {
        return ap_default_config();
    }
    $data = include AP_CONFIG_FILE;
    if (!is_array($data)) {
        return ap_default_config();
    }
    return array_merge(ap_default_config(), $data);
}

function ap_save_config($data)
{
    $data = array_merge(ap_default_config(), $data);
    $dir = dirname(AP_CONFIG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $export = "<?php\nreturn " . var_export($data, true) . ";\n";
    file_put_contents(AP_CONFIG_FILE, $export);
}

function ap_generate_token()
{
    return bin2hex(random_bytes(32));
}

// Shorthand used throughout auto_publisher_admin.php.
function ap_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Converts blank-line-separated paragraphs — the shape the bot's rewrite
// prompt asks the model for (see the main ChristNews repo's
// src/pipeline/rewrite.js, which explicitly asks for "a blank line between
// each" paragraph) — into <p> blocks. Without this, short_story/full_story
// get the literal "\n\n" text stored as-is, which renders as nothing in
// the site's HTML template: whitespace outside a tag is insignificant in
// HTML, so a multi-paragraph article reads as one unbroken block on the
// site even though the exact same text renders with clean paragraph
// breaks in Telegram (whose own message rendering honors literal
// newlines directly, no HTML markup needed there).
function ap_paragraphs_to_html($text)
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $paragraphs = preg_split('/\n{2,}/', $text);
    $paragraphs = array_map(function ($p) {
        return nl2br(trim($p));
    }, $paragraphs);
    $paragraphs = array_filter($paragraphs, function ($p) {
        return $p !== '';
    });
    if (!$paragraphs) {
        return '';
    }
    return '<p>' . implode('</p><p>', $paragraphs) . '</p>';
}

// Sends a JSON response and terminates the request.
function ap_respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Looks up a previously published item by its external id, joined against
// dle_post so we can return a fresh URL even if the post moved.
function ap_find_existing($db, $externalId)
{
    $row = $db->super_query(
        'SELECT ap.post_id, p.alt_name, p.category FROM ' . PREFIX . '_auto_publisher_posts ap ' .
        'LEFT JOIN ' . PREFIX . '_post p ON p.id = ap.post_id ' .
        'WHERE ap.external_id = ' . (int)$externalId
    );
    return $row ? $row : null;
}

// Canonical DLE article URL — works regardless of ЧПУ/SEO URL settings,
// so it doesn't need to know the site's specific ЧПУ pattern.
// Always returns an absolute URL: a relative one (e.g. when http_home_url
// isn't configured in DLE's own admin settings) silently fails to render as
// a clickable link when embedded in a Telegram HTML message.
function ap_build_post_url($postId)
{
    global $config;
    $home = isset($config['http_home_url']) && $config['http_home_url'] !== ''
        ? rtrim($config['http_home_url'], '/')
        : ap_detect_home_url();
    return $home . '/index.php?newsid=' . (int)$postId;
}

// Fallback when DLE's own http_home_url setting is empty — derives an
// absolute origin from the current request instead of returning a bare path.
function ap_detect_home_url()
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    return $host !== '' ? ($scheme . '://' . $host) : '';
}

// Transliterates a Russian title into a URL-safe Latin slug (dle_post.alt_name).
function ap_slugify($title)
{
    static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $title = mb_strtolower((string)$title, 'UTF-8');
    $translit = strtr($title, $map);
    $translit = preg_replace('/[^a-z0-9]+/', '-', $translit);
    $translit = trim($translit, '-');
    if ($translit === '') {
        $translit = 'news-' . time();
    }
    return mb_substr($translit, 0, 180);
}

// Appends -2, -3, ... until the alt_name is free. Capped to avoid a
// pathological infinite loop; falls back to a random suffix past the cap.
function ap_unique_alt_name($db, $baseSlug)
{
    $slug = $baseSlug;
    for ($attempt = 1; $attempt <= 50; $attempt++) {
        $safe = $db->safesql($slug);
        $row = $db->super_query('SELECT id FROM ' . PREFIX . "_post WHERE alt_name = '{$safe}' LIMIT 1");
        if (!$row) {
            return $slug;
        }
        $slug = $baseSlug . '-' . ($attempt + 1);
    }
    return $baseSlug . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

// Decodes a base64-encoded AI-generated cover image (see the bot's
// src/pipeline/generateImage.js) and saves it into its own subfolder under
// DLE's uploads tree — kept separate from anything else writing into
// uploads/ so this plugin never collides with unrelated files. Returns
// ['url' => absolute URL, 'relative_path' => path relative to uploads/,
// 'width' => int, 'height' => int, 'bytes' => int] on success, or null on
// any failure (invalid data, not a real image, unsupported format, write
// failure) — the caller treats a missing image the same as one that was
// never sent, never blocking the post itself.
function ap_save_image($base64Data, $externalId)
{
    if ($base64Data === '') {
        return null;
    }
    $binary = base64_decode($base64Data, true);
    if ($binary === false || $binary === '') {
        return null;
    }
    $info = @getimagesizefromstring($binary);
    if (!$info) {
        return null;
    }
    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extByMime[$info['mime']] ?? null;
    if ($ext === null) {
        return null;
    }
    // Saved under uploads/posts/auto_publisher/ (our own dedicated
    // subfolder, but nested under DLE's own "posts" root), not
    // uploads/auto_publisher/ directly — confirmed by reading DLE 20.0's
    // real source (get_uploaded_image_info(), engine/inc/include/
    // functions.inc.php): every caller that resolves a "Картинка"-type
    // xfield value (both the admin edit-news widget, xfields.class.php's
    // DLEXFields::FieldsList(), and the public-template xfvalue_*_url_*
    // rendering) calls it with no root_folder override, so it defaults to
    // 'posts' and checks uploads/posts/{value} — a value that isn't
    // relative to that exact folder resolves to a path that doesn't
    // exist, and DLE silently swaps in its own noimage.jpg placeholder
    // instead of erroring. Found live: a real post's xfields value looked
    // correct in the raw DB column, but the admin panel's own image
    // widget showed "no image available" — this is why.
    $dir = ROOT_DIR . '/uploads/posts/auto_publisher';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    $filename = 'ap-' . (int)$externalId . '-' . time() . '.' . $ext;
    if (@file_put_contents($dir . '/' . $filename, $binary) === false) {
        return null;
    }
    global $config;
    $home = isset($config['http_home_url']) && $config['http_home_url'] !== ''
        ? rtrim($config['http_home_url'], '/')
        : ap_detect_home_url();
    return [
        'url' => $home . '/uploads/posts/auto_publisher/' . $filename,
        // Relative to uploads/posts/ — see the comment above for why it
        // has to be resolvable under that exact root, not uploads/ itself.
        'relative_path' => 'auto_publisher/' . $filename,
        'width' => (int)$info[0],
        'height' => (int)$info[1],
        'bytes' => strlen($binary),
    ];
}

function ap_valid_image_modes()
{
    return ['body', 'xfields_text', 'xfields_image'];
}

// Mirrors DLE's own human-readable size suffix (confirmed from a live
// site's stored xfields value, e.g. "1.18 Mb") closely enough for display
// purposes — exact rounding behavior isn't guaranteed to match DLE's own
// formatter byte-for-byte, but nothing parses this back out numerically.
function ap_format_file_size($bytes)
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' Mb';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' Kb';
    }
    return $bytes . ' b';
}

// Builds the dle_post.xfields value for the image, per the configured
// image_mode — 'body' (nothing to build, caller prepends an <img> tag to
// short_story/full_story instead) returns ''.
//
// Format: DLE's own confirmed pipe-delimited `name|value` (a category's
// other xfields, if any, aren't touched — this is always a brand-new post,
// so there's nothing existing to preserve).
//
// 'xfields_text' stores the plain image URL, matching a "Текст" xfield
// that a template wraps itself (e.g. `<img src="[xfvalue_name]">`).
//
// 'xfields_image' stores `{relative_path}|0|0|{width}x{height}|{size}` —
// confirmed against a real value from a live DLE "Картинка"-type xfield
// (`2025-09/ai.webp|0|0|2048x2048|1.18 Mb`, seen in that site's own
// template: `[xfvalue_image_url_image]`). The `0|0` pair is DLE's own
// crop-offset fields — always 0|0 here since this plugin never crops.
// width/height/bytes come straight from the decoded image (ap_save_image()),
// not from the size the bot *requested*, in case the model didn't produce
// exactly that.
//
// IMPORTANT: DLE's own xfieldsdataload() (engine/modules/functions.php /
// engine/inc/include/functions.inc.php — confirmed by reading both) parses
// the whole xfields cell as `explode('|', $xfielddata)` with **no limit**,
// then does `list($name, $value) = $that_array` — only the first two
// pieces survive, anything after the second raw `|` is silently dropped.
// DLE's own field-value editors sidestep this by storing a literal `|`
// inside a value as the HTML entity `&#124;`, which xfieldsdataload() then
// decodes back to `|` via str_replace *after* splitting. So the four
// internal separators of the five-part image value below must be `&#124;`,
// not a raw `|`, or everything past the relative_path is lost — confirmed
// live: a raw-`|` value ended up stored/read back as just the bare
// relative_path (crop/dimensions/size silently gone).
function ap_build_xfields_value($imageMode, $xfieldName, $image)
{
    if ($image === null) {
        return '';
    }
    $name = $xfieldName !== '' ? $xfieldName : 'image';
    if ($imageMode === 'xfields_text') {
        return $name . '|' . $image['url'];
    }
    if ($imageMode === 'xfields_image') {
        $value = $image['relative_path'] . '&#124;0&#124;0&#124;' . $image['width'] . 'x' . $image['height'] .
            '&#124;' . ap_format_file_size($image['bytes']);
        return $name . '|' . $value;
    }
    return '';
}
