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

// ── Token auth (same pattern as api.php) ─────────────────────
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
    $ph = phoneFromToken($b['token'] ?? '');
    if (!$ph) fail('לא מאומת – נא להתחבר', 401);
    return $ph;
}
function normPhone(string $p): string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 10 && str_starts_with($p, '0')) return '+972' . substr($p, 1);
    if (strlen($p) === 9)                                return '+972' . $p;
    if (str_starts_with($p, '972') && strlen($p) === 12) return '+' . $p;
    return $p;
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── שליחת OTP (SMS) ───────────────────────────────────────
    case 'send_otp':
        $phone = normPhone(trim($body['phone'] ?? ''));
        if (strlen($phone) < 12) fail('מספר טלפון לא תקין');
        $code = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        kvs('otp_a:' . $phone, $code, 300);
        $devCode = '';
        if (hasTwilio()) {
            sendSMS($phone, "מאגרים – קוד אימות: {$code}\nתקף 5 דקות.");
        } else {
            $devCode = $code;
        }
        ok(['dev_code' => $devCode]);
        break;

    // ── שיחת טלפון OTP ────────────────────────────────────────
    case 'send_otp_call':
        $phone = normPhone(trim($body['phone'] ?? ''));
        if (strlen($phone) < 12) fail('מספר טלפון לא תקין');
        if (!hasTwilio()) fail('שיחות טלפון אינן זמינות כרגע');
        $code   = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        kvs('otp_a:' . $phone, $code, 300);
        $vToken = bin2hex(random_bytes(16));
        kvs('vtk:' . $vToken, $code, 120);
        $twimlUrl = 'https://ivr-kursim.onrender.com/otp_voice.php?t=' . urlencode($vToken);
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Calls.json';
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Basic " . base64_encode(TWILIO_SID . ':' . TWILIO_TOKEN)
                       . "\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query(['From'=>TWILIO_FROM,'To'=>$phone,'Url'=>$twimlUrl]),
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) fail('שגיאה בהתחברות לשירות שיחות');
        $resp = json_decode($r, true);
        if (!empty($resp['status']) && in_array($resp['status'], ['failed','canceled']))
            fail('לא ניתן להתקשר: ' . ($resp['message'] ?? ''));
        ok(['call' => true]);
        break;

    // ── אימות OTP ─────────────────────────────────────────────
    case 'verify_otp':
        $phone  = normPhone(trim($body['phone'] ?? ''));
        $code   = trim($body['code'] ?? '');
        $stored = kv('otp_a:' . $phone);
        if (!$stored || !hash_equals((string)$stored, $code)) fail('קוד שגוי או פג תוקף');
        kvd('otp_a:' . $phone);
        ok(['token' => mkToken($phone), 'phone' => $phone]);
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
        $phone = authPhone($body);
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
        $phone = authPhone($body);
        $all   = getAllAtrs();
        $mine  = array_values(array_filter($all, fn($a) => ($a['pub_phone'] ?? '') === $phone));
        foreach ($mine as &$atr) {
            $atr['images'] = $atr['has_images'] ? (kv('atr_imgs:' . $atr['id']) ?? []) : [];
        }
        ok(['attractions' => $mine, 'categories' => ATR_CATS, 'cities' => CITIES]);
        break;

    // ── מחיקת אטרקציה ─────────────────────────────────────────
    case 'delete_attraction':
        $phone = authPhone($body);
        $id    = trim($body['id'] ?? '');
        if (!$id) fail('חסר מזהה');
        $all = array_values(array_filter(getAllAtrs(),
            fn($a) => !($a['id'] === $id && ($a['pub_phone'] ?? '') === $phone)
        ));
        saveAtrs($all);
        kvd('atr_imgs:' . $id);
        ok();
        break;

    default:
        fail('פעולה לא מוכרת', 404);
}
