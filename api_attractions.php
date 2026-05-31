<?php
/**
 * api_attractions.php – REST JSON API לאטרקציות לציבור החרדי
 */

require_once __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Categories ────────────────────────────────────────────────
define('ATR_CATS', [
    'family'   => '👨‍👩‍👧‍👦 פעילות משפחתית',
    'nature'   => '🌿 טבע ושדות',
    'park'     => '🎡 פארק ומשחקים',
    'museum'   => '🏛️ מוזיאון ותערוכה',
    'food'     => '🍕 מסעדות ואוכל',
    'sport'    => '🏊 ספורט ובריכה',
    'art'      => '🎨 סדנאות ויצירה',
    'holy'     => '🕍 מקומות קדושים',
    'culture'  => '🎭 תרבות ובידור',
    'event'    => '📅 אירועים',
    'shopping' => '🛍️ קניות ושוק',
    'kids'     => '🧒 לילדים',
]);

// ── Helpers ───────────────────────────────────────────────────
function ok(array $d = []): never {
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $s = 400): never {
    http_response_code($s);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];
$body = array_merge($_GET, $_POST, $body);
$action = trim($body['action'] ?? '');

// ── KV ────────────────────────────────────────────────────────
function kv(string $k): mixed {
    if (hasRedis()) {
        $r = redisExec(['GET', $k]);
        return ($r !== null && $r !== '') ? json_decode($r, true) : null;
    }
    return fileKvGet($k);
}
function kvs(string $k, mixed $v, int $ttl = 0): void {
    if (hasRedis()) {
        $c = $ttl > 0 ? ['SET', $k, json_encode($v), 'EX', $ttl] : ['SET', $k, json_encode($v)];
        redisExec($c);
    } else { fileKvSet($k, $v, $ttl); }
}
function kvd(string $k): void {
    if (hasRedis()) redisExec(['DEL', $k]);
    else fileKvDel($k);
}

// ── Attractions storage ───────────────────────────────────────
function getAllAtrs(): array {
    $list = kv('atr_list');
    return is_array($list) ? $list : [];
}
function saveAtrs(array $list): void {
    kvs('atr_list', array_values($list));
}

// ── Token auth ───────────────────────────────────────────────
function mkToken(string $id): string {
    $d = base64_encode($id . ':' . time());
    return $d . '.' . hash_hmac('sha256', $d, 'mg_' . ADMIN_PASS);
}
function idFromToken(string $t): string|false {
    $p = explode('.', $t, 2);
    if (count($p) !== 2) return false;
    if (!hash_equals(hash_hmac('sha256', $p[0], 'mg_' . ADMIN_PASS), $p[1])) return false;
    $dec = base64_decode($p[0]);
    $parts = explode(':', $dec, 2);
    if (count($parts) !== 2 || time() - intval($parts[1]) > 86400 * 30) return false;
    return $parts[0];
}
function authUser(array $b): string {
    $id = idFromToken($b['token'] ?? '');
    if (!$id) fail('לא מאומת – נא להתחבר', 401);
    return $id;
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── כניסה עם אימייל ───────────────────────────────────────
    case 'email_login':
        $email = strtolower(trim($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('כתובת אימייל לא תקינה');
        ok(['token' => mkToken($email), 'email' => $email]);
        break;

    // ── נתוני טופס ────────────────────────────────────────────
    case 'form_data':
        ok([
            'categories'   => ATR_CATS,
            'cities'       => CITIES,
            'neighborhoods'=> NEIGHBORHOODS,
        ]);
        break;

    // ── גלישת אטרקציות (ציבורי) ───────────────────────────────
    case 'get_attractions':
        $all  = getAllAtrs();
        $city = intval($body['city'] ?? 0);
        $cat  = trim($body['category'] ?? '');
        $nh   = intval($body['neighborhood'] ?? 0);
        if ($city) $all = array_values(array_filter($all, fn($a) => ($a['city'] ?? 0) == $city));
        if ($nh)   $all = array_values(array_filter($all, fn($a) => ($a['neighborhood'] ?? 0) == $nh));
        if ($cat)  $all = array_values(array_filter($all, fn($a) => ($a['category'] ?? '') === $cat));
        // Attach images
        foreach ($all as &$atr) {
            $atr['images'] = $atr['has_images'] ? (kv('atr_imgs:' . $atr['id']) ?? []) : [];
        }
        ok([
            'attractions'  => $all,
            'categories'   => ATR_CATS,
            'cities'       => CITIES,
            'neighborhoods'=> NEIGHBORHOODS,
            'total'        => count($all),
        ]);
        break;

    // ── הוספת אטרקציה ─────────────────────────────────────────
    case 'add_attraction':
        $phone = authUser($body);
        $name  = mb_substr(strip_tags(trim($body['name'] ?? '')), 0, 100);
        $cat   = trim($body['category'] ?? '');
        $city  = intval($body['city'] ?? 0);
        if (!$name)                    fail('נא להזין שם אטרקציה');
        if (!isset(ATR_CATS[$cat]))    fail('נא לבחור קטגוריה');
        if (!$city || !isset(CITIES[$city])) fail('נא לבחור עיר');

        $id = bin2hex(random_bytes(8));
        $atr = [
            'id'           => $id,
            'name'         => $name,
            'category'     => $cat,
            'city'         => $city,
            'neighborhood' => intval($body['neighborhood'] ?? 0),
            'address'      => mb_substr(strip_tags($body['address']     ?? ''), 0, 150),
            'description'  => mb_substr(strip_tags($body['description'] ?? ''), 0, 1000),
            'price'        => mb_substr(strip_tags($body['price']       ?? ''), 0, 80),
            'hours'        => mb_substr(strip_tags($body['hours']       ?? ''), 0, 150),
            'phone'        => mb_substr(strip_tags($body['contact_phone'] ?? ''), 0, 20),
            'website'      => filter_var($body['website'] ?? '', FILTER_SANITIZE_URL),
            'pub_email'    => $phone,
            'pub_phone'    => $phone,
            'has_images'   => false,
            'image_count'  => 0,
            'created'      => time(),
            'expires'      => time() + 86400 * 90, // 90 ימים
        ];

        // תמונות
        $imgs = array_slice(
            array_filter((array)($body['images'] ?? []),
                fn($x) => is_string($x) && str_starts_with($x, 'data:image/')),
            0, 6
        );
        if (!empty($imgs)) {
            kvs('atr_imgs:' . $id, $imgs, 86400 * 91);
            $atr['has_images']  = true;
            $atr['image_count'] = count($imgs);
        }

        $all   = getAllAtrs();
        $all[] = $atr;
        saveAtrs($all);

        // ── שמור ב-Airtable ──────────────────────────────────────
        $catLabels = [
            'family'   => 'פעילות משפחתית',
            'nature'   => 'טבע ושדות',
            'park'     => 'פארק ומשחקים',
            'museum'   => 'מוזיאון ותערוכה',
            'food'     => 'מסעדות ואוכל',
            'sport'    => 'ספורט ובריכה',
            'art'      => 'סדנאות ויצירה',
            'holy'     => 'מקומות קדושים',
            'culture'  => 'תרבות ובידור',
            'event'    => 'אירועים',
            'shopping' => 'קניות ושוק',
            'kids'     => 'לילדים',
        ];
        airtablePush(AIRTABLE_TBL_ATR, [
            'fldwyzUjSiAjyLnEZ' => $atr['name'],
            'fldOijnIWb408v4l8' => $catLabels[$cat]                                   ?? $cat,
            'fldh451rEJ6v1qbpj' => CITIES[$city]                                      ?? '',
            'fldsX0n5VoUOG2tzc' => NEIGHBORHOODS[$city][$atr['neighborhood']]         ?? '',
            'fldKJLN4wy9W7oMJQ' => $atr['address'],
            'fldREcy2SRXLdiYnF' => $atr['description'],
            'fldOK28qerKnI3vwf' => $atr['price'],
            'fldS15V96NQBXrevf' => $atr['hours'],
            'fldDwAfhWbuxVVBws' => $atr['phone'],
            'fldpTFZorhYEXb3pq' => $atr['website'],
            'fld0MNpsPN1bChchX' => $phone,
            'fld6a4FcYwlZeHg44' => date('Y-m-d'),
            'fldwNdlPAIwFn9V5l' => date('Y-m-d', $atr['expires']),
        ]);

        ok(['id' => $id]);
        break;

    // ── האטרקציות שלי ─────────────────────────────────────────
    case 'my_attractions':
        $phone = authUser($body);
        $all   = getAllAtrs();
        $mine  = array_values(array_filter($all, fn($a) =>
            ($a['pub_email'] ?? $a['pub_phone'] ?? '') === $phone
        ));
        foreach ($mine as &$atr) {
            $atr['images'] = $atr['has_images'] ? (kv('atr_imgs:' . $atr['id']) ?? []) : [];
        }
        ok(['attractions' => $mine, 'categories' => ATR_CATS, 'cities' => CITIES]);
        break;

    // ── מחיקת אטרקציה ─────────────────────────────────────────
    case 'delete_attraction':
        $phone = authUser($body);
        $id    = trim($body['id'] ?? '');
        if (!$id) fail('חסר מזהה');
        $all = array_values(array_filter(getAllAtrs(),
            fn($a) => !($a['id'] === $id &&
                ($a['pub_email'] ?? $a['pub_phone'] ?? '') === $phone)
        ));
        saveAtrs($all);
        kvd('atr_imgs:' . $id);
        ok();
        break;

    default:
        fail('פעולה לא מוכרת', 404);
}
