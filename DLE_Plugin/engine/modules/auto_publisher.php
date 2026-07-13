<?php
// Auto Publisher — receives an already-approved item from an external
// moderation bot and inserts it as a live news post.
// Reachable at POST /index.php?do=auto_publisher
if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

if ($dle_module !== 'auto_publisher') {
    return;
}

require_once ENGINE_DIR . '/inc/auto_publisher_lib.php';

if (ob_get_level()) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ap_respond(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$cfg = ap_load_config();

// Accept the token via header (preferred) or a POST field, for callers that
// can't easily set custom headers.
$providedToken = '';
if (!empty($_SERVER['HTTP_X_AUTOPUBLISHER_TOKEN'])) {
    $providedToken = $_SERVER['HTTP_X_AUTOPUBLISHER_TOKEN'];
} elseif (!empty($_POST['token'])) {
    $providedToken = $_POST['token'];
}

if ($cfg['token'] === '' || !hash_equals($cfg['token'], (string)$providedToken)) {
    ap_respond(401, ['success' => false, 'error' => 'invalid_token']);
}

$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

// Ping mode: lets a caller validate the URL + token, see the real category
// list, and check the installed plugin version — without creating
// anything. Used both when connecting a new site from the bot's UI and by
// its "🔄 Синхронизировать с DLE" / "🧪 Тест соединения" buttons on an
// already-added target.
if (!empty($data['ping'])) {
    ap_respond(200, [
        'success' => true,
        'category_configured' => ap_category_configured($cfg),
        'plugin_version' => AP_PLUGIN_VERSION,
        'categories' => ap_categories_map($cat_info),
        'multi_category' => !empty($cfg['category_multi']),
    ]);
}

$externalId = isset($data['external_id']) ? (int)$data['external_id'] : 0;
$title = isset($data['title']) ? trim((string)$data['title']) : '';
$body = isset($data['body']) ? trim((string)$data['body']) : '';
// teaser -> short_story (listing preview), body -> full_story (the article
// page). Older callers (or a caller that just omits it) won't send teaser —
// fall back to body itself so short_story is never left blank.
$teaser = isset($data['teaser']) ? trim((string)$data['teaser']) : '';
if ($teaser === '') {
    $teaser = $body;
}
$sourceUrl = isset($data['source_url']) ? trim((string)$data['source_url']) : '';
// Optional per-item category override (single ID or array of IDs) — an
// AI-suggested-then-moderator-confirmed category from the bot's side, see
// ap_resolve_categories() for exactly how this interacts with the
// configured default. Absent on older callers, which keeps working exactly
// as before (falls through to the configured default).
$requestedCategory = isset($data['category']) ? $data['category'] : (isset($data['categories']) ? $data['categories'] : null);
// Optional AI-generated cover image, base64-encoded — see
// src/pipeline/generateImage.js. Absent on older callers or when generation
// failed/was skipped; ap_save_image() returns null for any of those or on a
// save failure, and the post is created without an image either way.
$imageData = isset($data['image']) ? (string)$data['image'] : '';

if ($externalId <= 0) {
    ap_respond(422, ['success' => false, 'error' => 'invalid_external_id']);
}
if ($title === '' || mb_strlen($title, 'UTF-8') > 300) {
    ap_respond(422, ['success' => false, 'error' => 'invalid_title']);
}
if ($body === '' || mb_strlen($body, 'UTF-8') > 20000) {
    ap_respond(422, ['success' => false, 'error' => 'invalid_body']);
}
if ($teaser === '' || mb_strlen($teaser, 'UTF-8') > 2000) {
    ap_respond(422, ['success' => false, 'error' => 'invalid_teaser']);
}
if ($sourceUrl !== '' && !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    ap_respond(422, ['success' => false, 'error' => 'invalid_source_url']);
}
$categoryMap = ap_categories_map($cat_info);
$categoryValue = ap_resolve_categories($cfg, $requestedCategory, $categoryMap);
if ($categoryValue === '') {
    ap_respond(500, ['success' => false, 'error' => 'category_not_configured']);
}

global $db;

// Idempotency: a retry with the same external_id must never create a
// second post — just hand back the one that already exists.
$existing = ap_find_existing($db, $externalId);
if ($existing) {
    ap_respond(200, [
        'success' => true,
        'post_id' => (int)$existing['post_id'],
        'url' => ap_build_post_url($existing['post_id']),
        'duplicate' => true,
    ]);
}

$altName = ap_unique_alt_name($db, ap_slugify($title));
$approve = !empty($cfg['auto_approve']) ? '1' : '0';
$author = $cfg['author'] !== '' ? $cfg['author'] : 'AutoPublisherBot';
$date = date('Y-m-d H:i:s');

// Applied after the length checks above (an image tag is small relative to
// the 2000/20000-char teaser/body caps) so it never counts against the
// caller's own content budget. image_mode picks where it ends up: inline
// in the article text (default, 'body'), or a category custom field
// (xfields) instead — see ap_build_xfields_value() for the two xfields
// variants' exact value shape and caveats.
$image = ap_save_image($imageData, $externalId);
$xfieldsValue = '';
if ($image !== null) {
    if ($cfg['image_mode'] === 'xfields_text' || $cfg['image_mode'] === 'xfields_image') {
        $xfieldsValue = ap_build_xfields_value($cfg['image_mode'], $cfg['image_xfield_name'], $image);
    } else {
        $imageTag = '<img src="' . htmlspecialchars($image['url'], ENT_QUOTES, 'UTF-8') . '" alt="" />';
        $teaser = $imageTag . $teaser;
        $body = $imageTag . $body;
    }
}

$db->query(
    'INSERT INTO ' . PREFIX . '_post ' .
    '(autor, title, short_story, full_story, xfields, date, category, alt_name, approve, allow_comm, allow_main, comm_num) VALUES (' .
    "'" . $db->safesql($author) . "', " .
    "'" . $db->safesql($title) . "', " .
    "'" . $db->safesql($teaser) . "', " .
    "'" . $db->safesql($body) . "', " .
    "'" . $db->safesql($xfieldsValue) . "', " .
    "'" . $db->safesql($date) . "', " .
    "'" . $db->safesql($categoryValue) . "', " .
    "'" . $db->safesql($altName) . "', " .
    "'" . $approve . "', " .
    "'1', '1', '0')"
);

$postId = (int)$db->insert_id();

if ($postId <= 0) {
    ap_respond(500, ['success' => false, 'error' => 'insert_failed']);
}

$db->query(
    'INSERT INTO ' . PREFIX . '_auto_publisher_posts (external_id, post_id, created_at) VALUES (' .
    (int)$externalId . ', ' . $postId . ', ' . time() . ')'
);

ap_respond(200, [
    'success' => true,
    'post_id' => $postId,
    'url' => ap_build_post_url($postId),
    'duplicate' => false,
]);
