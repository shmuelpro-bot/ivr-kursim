<?php
/**
 * api.php – REST JSON API לפרסום דירות (CORS)
 * Actions: form_data | email_login | my_listings | publish | delete_apt
 */

require_once __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Image serve: GET api.php?img={aptId}&n={index} ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['img'])) {
    $key  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['img']);
    $raw  = redisExec(['GET', 'apt_imgs:' . $key]);
    $imgs = is_string($raw) ? json_decode($raw, true) : null;
    $idx  = max(0, intval($_GET['n'] ?? 0));
    $b64  = is_array($imgs) ? ($imgs[$idx] ?? null) : null;
    if (!is_string($b64) || !str_starts_with($b64, 'data:image')) {
        http_response_code(404); exit;
    }
    if (!preg_match('/^data:(image\/\w+);base64,(.+)$/s', $b64, $m)) {
        http_response_code(400); exit;
    }
    header('Content-Type: ' . $m[1]);
    header('Cache-Control: public, max-age=604800');
    echo base64_decode($m[2]);
    exit;
}

function ok(array $d = []): never {
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('POST only', 405); }

$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];
$body = array_merge($_GET, $_POST, $body);
$action = trim($body['action'] ?? '');

// ── Token (HMAC stateless, 30 days) ───────────────────────────
function mkToken(string $id): string {
    $d = base64_encode($id . ':' . time());
    return $d . '.' . hash_hmac('sha256', $d, 'mg_' . ADMIN_PASS);
}
function idFromToken(string $t): string|false {
    $p = explode('.', $t, 2);
    if (count($p) !== 2) return false;
    if (!hash_equals(hash_hmac('sha256', $p[0], 'mg_' . ADMIN_PASS), $p[1])) return false;
    $dec   = base64_decode($p[0]);
    $parts = explode(':', $dec, 2);
    if (count($parts) !== 2 || time() - intval($parts[1]) > 86400 * 30) return false;
    return $parts[0];
}
function authUser(array $b): string {
    $id = idFromToken($b['token'] ?? '');
    if (!$id) fail('לא מאומת – נא להתחבר מחדש', 401);
    return $id;
}

// ── Image helpers (JSON array stored in Redis) ─────────────────
function saveImgs(string $aptId, array $images): int {
    $valid = array_values(array_filter(
        array_slice($images, 0, 4),
        fn($x) => is_string($x) && str_starts_with($x, 'data:image/')
    ));
    if (!empty($valid)) {
        redisExec(['SET', 'apt_imgs:' . $aptId, json_encode($valid), 'EX', 86400 * 8]);
    }
    return count($valid);
}

function loadImgs(string $aptId, bool $hasImages): array {
    if (!$hasImages) return [];
    $raw  = redisExec(['GET', 'apt_imgs:' . $aptId]);
    $imgs = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($imgs) ? $imgs : [];
}

function delImgs(string $aptId): void {
    redisExec(['DEL', 'apt_imgs:' . $aptId]);
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── כניסה עם אימייל (ללא סיסמה) ──────────────────────────
    case 'email_login':
        $email = strtolower(trim($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('כתובת אימייל לא תקינה');
        ok(['token' => mkToken($email), 'email' => $email]);
        break;

    // ── מידע לטופס ────────────────────────────────────────────
    case 'form_data':
        ok([
            'cities'        => CITIES,
            'neighborhoods' => NEIGHBORHOODS,
            'apt_types'     => APT_TYPES,
            'rental_types'  => RENTAL_TYPES,
        ]);
        break;

    // ── פרסום דירה ────────────────────────────────────────────
    case 'publish': {
        $userId  = authUser($body);
        $city    = intval($body['city']     ?? 0);
        $aptType = intval($body['apt_type'] ?? 0);
        if (!$city    || !isset(CITIES[$city]))       fail('נא לבחור עיר');
        if (!$aptType || !isset(APT_TYPES[$aptType])) fail('נא לבחור סוג דירה');

        $validFeats = ['parking','ac','elevator','balcony','wifi','washing','dishwasher',
                       'bathtub','shabbat_mode','crib','synagogue','garden','handicapped','quiet'];
        $features = array_values(array_intersect(
            array_map('strip_tags', (array)($body['features'] ?? [])),
            $validFeats
        ));

        $aptId  = bin2hex(random_bytes(8));
        $imgCnt = saveImgs($aptId, (array)($body['images'] ?? []));

        $apt = [
            'id'           => $aptId,
            'city'         => $city,
            'neighborhood' => intval($body['neighborhood'] ?? 0),
            'apt_type'     => $aptType,
            'beds'         => max(1, min(12, intval($body['beds']        ?? 1))),
            'bedrooms'     => max(0, min(10, intval($body['bedrooms']    ?? 1))),
            'rental_type'  => max(1, min(3,  intval($body['rental_type'] ?? 1))),
            'price'        => max(0, intval($body['price'] ?? 0)),
            'floor'        => mb_substr(strip_tags($body['floor']         ?? ''), 0, 20),
            'features'     => $features,
            'description'  => mb_substr(strip_tags($body['description']  ?? ''), 0, 800),
            'contact_name' => mb_substr(strip_tags($body['contact_name'] ?? ''), 0, 60),
            'pub_email'    => $userId,
            'pub_phone'    => '',
            'has_images'   => $imgCnt > 0,
            'image_count'  => $imgCnt,
            'expires'      => nextShabbatEnd(),
            'created'      => time(),
        ];

        $all   = getAllApts();
        $all[] = $apt;
        saveApts($all);
        ok(['apt_id' => $aptId, 'expires' => $apt['expires']]);
        break;
    }

    // ── הדירות שלי ────────────────────────────────────────────
    case 'my_listings': {
        $userId = authUser($body);
        $mine   = array_values(array_filter(getAllApts(), fn($a) =>
            ($a['pub_email'] ?? $a['pub_phone'] ?? '') === $userId
        ));
        foreach ($mine as &$apt) {
            $apt['images'] = loadImgs($apt['id'], (bool)($apt['has_images'] ?? false));
        }
        unset($apt);
        ok([
            'listings'     => $mine,
            'cities'       => CITIES,
            'apt_types'    => APT_TYPES,
            'rental_types' => RENTAL_TYPES,
        ]);
        break;
    }

    // ── מחיקת דירה ────────────────────────────────────────────
    case 'delete_apt': {
        $userId = authUser($body);
        $aptId  = trim($body['apt_id'] ?? '');
        if (!$aptId) fail('חסר מזהה');
        $found = false;
        $all   = array_values(array_filter(getAllApts(),
            function ($a) use ($userId, $aptId, &$found) {
                if ($a['id'] === $aptId &&
                    ($a['pub_email'] ?? $a['pub_phone'] ?? '') === $userId) {
                    $found = true; return false;
                }
                return true;
            }
        ));
        if (!$found) fail('פרסום לא נמצא', 404);
        saveApts($all);
        delImgs($aptId);
        ok();
        break;
    }

    default:
        fail('פעולה לא מוכרת', 404);
}
