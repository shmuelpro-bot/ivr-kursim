<?php
/**
 * api.php – REST JSON API לפרסום דירות (CORS)
 */

require_once __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function ok(array $d = []): never {
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];
$body = array_merge($_GET, $_POST, $body);
$action = trim($body['action'] ?? '');

// ── Token (HMAC, 30 days) ──────────────────────────────────────
function mkToken(string $phone): string {
    $d = base64_encode($phone . ':' . time());
    return $d . '.' . hash_hmac('sha256', $d, 'mg_' . ADMIN_PASS);
}
function phoneFromToken(string $t): string|false {
    $p = explode('.', $t, 2);
    if (count($p) !== 2) return false;
    if (!hash_equals(hash_hmac('sha256', $p[0], 'mg_' . ADMIN_PASS), $p[1])) return false;
    $dec = base64_decode($p[0]);
    $parts = explode(':', $dec, 2);
    if (count($parts) !== 2 || time() - intval($parts[1]) > 86400 * 30) return false;
    return $parts[0];
}
function authPhone(array $b): string {
    $phone = phoneFromToken($b['token'] ?? '');
    if (!$phone) fail('לא מאומת – נא להתחבר מחדש', 401);
    return $phone;
}

// ── KV (Redis → file fallback) ──────────────────────────────────
function kv_get(string $k): mixed {
    if (hasRedis()) {
        $r = redisExec(['GET', $k]);
        return ($r !== null && $r !== '') ? json_decode($r, true) : null;
    }
    return fileKvGet($k);
}
function kv_set(string $k, mixed $v, int $ttl = 0): void {
    if (hasRedis()) {
        $cmd = $ttl > 0 ? ['SET', $k, json_encode($v), 'EX', $ttl] : ['SET', $k, json_encode($v)];
        redisExec($cmd);
    } else {
        fileKvSet($k, $v, $ttl);
    }
}
function kv_del(string $k): void {
    if (hasRedis()) redisExec(['DEL', $k]);
    else fileKvDel($k);
}

// ── Phone normalization ────────────────────────────────────────
function normPhone(string $p): string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 10 && str_starts_with($p, '0')) return '+972' . substr($p, 1);
    if (strlen($p) === 9)                                return '+972' . $p;
    if (str_starts_with($p, '972') && strlen($p) === 12) return '+' . $p;
    return $p;
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── שליחת OTP ─────────────────────────────────────────────
    case 'send_otp':
        $phone = normPhone(trim($body['phone'] ?? ''));
        if (strlen($phone) < 12) fail('מספר טלפון לא תקין');
        $code = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        kv_set('otp:' . $phone, $code, 300);
        $devCode = '';
        if (hasTwilio()) {
            sendSMS($phone, "קו דירות לשבת – קוד אימות: {$code}\nתקף 5 דקות.");
        } else {
            $devCode = $code; // מצב בדיקה ללא Twilio
        }
        ok(['dev_code' => $devCode]);
        break;

    // ── אימות OTP ─────────────────────────────────────────────
    case 'verify_otp':
        $phone  = normPhone(trim($body['phone'] ?? ''));
        $code   = trim($body['code'] ?? '');
        $stored = kv_get('otp:' . $phone);
        if (!$stored || !hash_equals((string)$stored, $code)) fail('קוד שגוי או פג תוקף');
        kv_del('otp:' . $phone);
        ok(['token' => mkToken($phone), 'phone' => $phone]);
        break;

    // ── מידע לטופס ────────────────────────────────────────────
    case 'form_data':
        ok([
            'cities'       => CITIES,
            'neighborhoods'=> NEIGHBORHOODS,
            'apt_types'    => APT_TYPES,
            'rental_types' => RENTAL_TYPES,
        ]);
        break;

    // ── פרסום דירה ────────────────────────────────────────────
    case 'publish':
        $phone   = authPhone($body);
        $city    = intval($body['city']     ?? 0);
        $aptType = intval($body['apt_type'] ?? 0);
        if (!$city    || !isset(CITIES[$city]))       fail('נא לבחור עיר');
        if (!$aptType || !isset(APT_TYPES[$aptType])) fail('נא לבחור סוג דירה');

        $aptId = bin2hex(random_bytes(8));
        $apt = [
            'id'           => $aptId,
            'city'         => $city,
            'neighborhood' => intval($body['neighborhood'] ?? 0),
            'apt_type'     => $aptType,
            'beds'         => max(1, min(12, intval($body['beds']     ?? 1))),
            'bedrooms'     => max(0, min(10, intval($body['bedrooms'] ?? 1))),
            'rental_type'  => max(1, min(3,  intval($body['rental_type'] ?? 1))),
            'price'        => max(0, intval($body['price'] ?? 0)),
            'floor'        => mb_substr(strip_tags($body['floor']        ?? ''), 0, 20),
            'features'     => array_map('strip_tags', (array)($body['features'] ?? [])),
            'description'  => mb_substr(strip_tags($body['description'] ?? ''), 0, 800),
            'contact_name' => mb_substr(strip_tags($body['contact_name'] ?? ''), 0, 60),
            'owner_phone'  => $phone,
            'pub_phone'    => $phone,
            'has_images'   => false,
            'image_count'  => 0,
            'expires'      => nextShabbatEnd(),
            'created'      => time(),
        ];

        // תמונות (base64 שנשלח מהדפדפן)
        $imgs = array_slice(
            array_filter((array)($body['images'] ?? []),
                fn($x) => is_string($x) && str_starts_with($x, 'data:image/')),
            0, 4
        );
        if (!empty($imgs)) {
            kv_set('apt_imgs:' . $aptId, $imgs, 86400 * 8);
            $apt['has_images']  = true;
            $apt['image_count'] = count($imgs);
        }

        $all   = getAllApts();
        $all[] = $apt;
        saveApts($all);
        ok(['apt_id' => $aptId, 'expires' => $apt['expires']]);
        break;

    // ── הדירות שלי ────────────────────────────────────────────
    case 'my_listings':
        $phone = authPhone($body);
        $all   = getAllApts();
        $mine  = array_values(array_filter($all, fn($a) => ($a['pub_phone'] ?? '') === $phone));
        foreach ($mine as &$apt) {
            $apt['images'] = $apt['has_images'] ? (kv_get('apt_imgs:' . $apt['id']) ?? []) : [];
        }
        ok([
            'listings'     => $mine,
            'cities'       => CITIES,
            'apt_types'    => APT_TYPES,
            'rental_types' => RENTAL_TYPES,
        ]);
        break;

    // ── מחיקת דירה ────────────────────────────────────────────
    case 'delete_apt':
        $phone = authPhone($body);
        $aptId = trim($body['apt_id'] ?? '');
        if (!$aptId) fail('חסר מזהה');
        $all = array_values(array_filter(getAllApts(),
            fn($a) => !($a['id'] === $aptId && ($a['pub_phone'] ?? '') === $phone)
        ));
        saveApts($all);
        kv_del('apt_imgs:' . $aptId);
        ok();
        break;

    default:
        fail('פעולה לא מוכרת', 404);
}
