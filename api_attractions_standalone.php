<?php
/**
 * api_attractions_standalone.php
 * העלה קובץ זה לתיקיית השורש של האתר שלך (public_html)
 * אין צורך ב-Redis או שרת חיצוני – האחסון הוא בקבצים מקומיים
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── הגדרות ────────────────────────────────────────────────────
define('ATR_SECRET', 'mg_maagarim_2024_secret');
define('ATR_DATA_DIR', __DIR__ . '/atr_data');

// ── קטגוריות ─────────────────────────────────────────────────
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

// ── ערים ─────────────────────────────────────────────────────
define('ATR_CITIES', [
    1=>'ירושלים', 2=>'בני ברק', 3=>'אלעד', 4=>'מודיעין עילית',
    5=>'ביתר עילית', 6=>'בית שמש', 7=>'צפת', 8=>'אשדוד',
    9=>'נתניה', 10=>'תל אביב', 11=>'חיפה', 12=>'פתח תקווה',
    13=>'ראשון לציון', 14=>'חדרה', 15=>'טבריה', 16=>'באר שבע',
    17=>'אופקים', 18=>'עפולה',
]);

// ── שכונות ───────────────────────────────────────────────────
define('ATR_NEIGHBORHOODS', [
    1  => [1=>'מאה שערים', 2=>'גאולה', 3=>'קרית מטרסדורף', 4=>'רמות', 5=>'הר נוף', 6=>'בית וגן', 7=>'קרית יובל', 8=>'פסגת זאב'],
    2  => [1=>'מרכז', 2=>'קרית הרצוג', 3=>'קרית ויזניץ', 4=>'זכרון מאיר', 5=>'פארק נווה גן'],
    3  => [1=>'מרכז', 2=>'שכונה א', 3=>'שכונה ב'],
    4  => [1=>'קרית ספר', 2=>'מתתיהו', 3=>'חשמונאים'],
    5  => [1=>'מרכז', 2=>'שכונה א', 3=>'שכונה ב'],
    6  => [1=>'רמת בית שמש א', 2=>'רמת בית שמש ב', 3=>'רמת בית שמש ג', 4=>'מרכז העיר'],
    7  => [1=>'מרכז', 2=>'קרית חב"ד', 3=>'שכונת צאנז', 4=>'שכונה ד'],
    8  => [1=>'מרכז', 2=>'שכונה יא', 3=>'שכונה יב', 4=>'שכונה ז'],
    9  => [1=>'מרכז', 2=>'קרית נורדאו', 3=>'עיר ימים'],
    10 => [1=>'לב תל אביב', 2=>'פלורנטין', 3=>'נוה צדק', 4=>'יפו'],
    11 => [1=>'הדר הכרמל', 2=>'כרמל', 3=>'נווה שאנן', 4=>'רמות ויז\'ניץ'],
    12 => [1=>'מרכז', 2=>'כפר גנים', 3=>'שכונה ד'],
    13 => [1=>'מרכז', 2=>'נחלת יהודה', 3=>'שכונה ד'],
    14 => [1=>'מרכז', 2=>'שיכון ג'],
    15 => [1=>'מרכז', 2=>'קרית שמואל', 3=>'שכונת תל גנן'],
    16 => [1=>'מרכז', 2=>'רמות', 3=>'נאות לון'],
    17 => [1=>'מרכז', 2=>'שכונה ב'],
    18 => [1=>'מרכז', 2=>'שכונה ב'],
]);

// ── אחסון בקבצים ─────────────────────────────────────────────

function ensureDataDir(): void {
    $dir = ATR_DATA_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
        file_put_contents($dir . '/index.php', '<?php // silence');
    }
}

function dataFile(string $key): string {
    return ATR_DATA_DIR . '/' . preg_replace('/[^a-z0-9_\-]/', '_', $key) . '.json';
}

function kvGet(string $key): mixed {
    $f = dataFile($key);
    if (!file_exists($f)) return null;
    $raw = @file_get_contents($f);
    if (!$raw) return null;
    $d = json_decode($raw, true);
    if (isset($d['expires']) && $d['expires'] > 0 && time() > $d['expires']) {
        @unlink($f);
        return null;
    }
    return $d['value'] ?? null;
}

function kvSet(string $key, mixed $value, int $ttl = 0): void {
    ensureDataDir();
    file_put_contents(dataFile($key), json_encode([
        'value'   => $value,
        'expires' => $ttl > 0 ? time() + $ttl : 0,
    ], JSON_UNESCAPED_UNICODE));
}

function kvDel(string $key): void {
    $f = dataFile($key);
    if (file_exists($f)) @unlink($f);
}

// ── אטרקציות ─────────────────────────────────────────────────

function getAllAtrs(): array {
    $list = kvGet('atr_list');
    return is_array($list) ? $list : [];
}

function saveAtrs(array $list): void {
    kvSet('atr_list', array_values($list));
}

// ── Token ─────────────────────────────────────────────────────

function mkToken(string $email): string {
    $d = base64_encode($email . ':' . time());
    return $d . '.' . hash_hmac('sha256', $d, ATR_SECRET);
}

function emailFromToken(string $t): string|false {
    $p = explode('.', $t, 2);
    if (count($p) !== 2) return false;
    if (!hash_equals(hash_hmac('sha256', $p[0], ATR_SECRET), $p[1])) return false;
    $dec   = base64_decode($p[0]);
    $parts = explode(':', $dec, 2);
    if (count($parts) !== 2) return false;
    if (time() - intval($parts[1]) > 86400 * 30) return false;
    return $parts[0];
}

function authUser(array $b): string {
    $email = emailFromToken($b['token'] ?? '');
    if (!$email) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'לא מאומת – נא להתחבר'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $email;
}

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

// ── תמונות ───────────────────────────────────────────────────

function saveImages(array $images, string $id): array {
    ensureDataDir();
    $imgDir  = ATR_DATA_DIR . '/imgs';
    $imgUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/atr_data/imgs';
    if (!is_dir($imgDir)) {
        mkdir($imgDir, 0755, true);
        file_put_contents($imgDir . '/.htaccess', "Options -Indexes\n");
    }
    $saved = [];
    foreach (array_slice($images, 0, 6) as $img) {
        if (!is_string($img) || !str_starts_with($img, 'data:image/')) continue;
        $parts = explode(',', $img, 2);
        if (count($parts) !== 2) continue;
        $binary = base64_decode($parts[1], true);
        if (!$binary || strlen($binary) < 100) continue;
        $fname = $id . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents($imgDir . '/' . $fname, $binary) !== false) {
            $saved[] = $imgUrl . '/' . $fname;
        }
    }
    return $saved;
}

function deleteImages(string $atrId): void {
    $imgDir = ATR_DATA_DIR . '/imgs';
    if (!is_dir($imgDir)) return;
    foreach (glob($imgDir . '/' . $atrId . '_*') as $f) {
        @unlink($f);
    }
}

// ══ ROUTING ═══════════════════════════════════════════════════

$raw    = file_get_contents('php://input');
$body   = $raw ? (json_decode($raw, true) ?? []) : [];
$body   = array_merge($_GET, $_POST, $body);
$action = trim($body['action'] ?? '');

switch ($action) {

    // ── נתוני טופס ────────────────────────────────────────────
    case 'form_data':
        ok([
            'categories'    => ATR_CATS,
            'cities'        => ATR_CITIES,
            'neighborhoods' => ATR_NEIGHBORHOODS,
        ]);

    // ── כניסה עם אימייל ───────────────────────────────────────
    case 'email_login':
        $email = strtolower(trim($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('כתובת אימייל לא תקינה');
        ok(['token' => mkToken($email), 'email' => $email]);

    // ── גלישת אטרקציות (ציבורי) ───────────────────────────────
    case 'get_attractions':
        $all  = getAllAtrs();
        $city = intval($body['city'] ?? 0);
        $cat  = trim($body['category'] ?? '');
        $nh   = intval($body['neighborhood'] ?? 0);
        if ($city) $all = array_values(array_filter($all, fn($a) => ($a['city'] ?? 0) == $city));
        if ($nh)   $all = array_values(array_filter($all, fn($a) => ($a['neighborhood'] ?? 0) == $nh));
        if ($cat)  $all = array_values(array_filter($all, fn($a) => ($a['category'] ?? '') === $cat));
        foreach ($all as &$atr) {
            $atr['images'] = kvGet('atr_imgs:' . $atr['id']) ?? [];
        }
        ok([
            'attractions'   => $all,
            'categories'    => ATR_CATS,
            'cities'        => ATR_CITIES,
            'neighborhoods' => ATR_NEIGHBORHOODS,
            'total'         => count($all),
        ]);

    // ── הוספת אטרקציה ─────────────────────────────────────────
    case 'add_attraction':
        $email = authUser($body);
        $name  = mb_substr(strip_tags(trim($body['name'] ?? '')), 0, 100);
        $cat   = trim($body['category'] ?? '');
        $city  = intval($body['city'] ?? 0);
        if (!$name)                       fail('נא להזין שם אטרקציה');
        if (!isset(ATR_CATS[$cat]))       fail('נא לבחור קטגוריה');
        if (!$city || !isset(ATR_CITIES[$city])) fail('נא לבחור עיר');

        $id  = bin2hex(random_bytes(8));
        $imgs = saveImages((array)($body['images'] ?? []), $id);

        $atr = [
            'id'           => $id,
            'name'         => $name,
            'category'     => $cat,
            'city'         => $city,
            'neighborhood' => intval($body['neighborhood'] ?? 0),
            'address'      => mb_substr(strip_tags($body['address']       ?? ''), 0, 150),
            'description'  => mb_substr(strip_tags($body['description']   ?? ''), 0, 1000),
            'price'        => mb_substr(strip_tags($body['price']         ?? ''), 0, 80),
            'hours'        => mb_substr(strip_tags($body['hours']         ?? ''), 0, 150),
            'phone'        => mb_substr(strip_tags($body['contact_phone'] ?? ''), 0, 20),
            'website'      => filter_var($body['website'] ?? '', FILTER_SANITIZE_URL),
            'pub_email'    => $email,
            'has_images'   => !empty($imgs),
            'image_count'  => count($imgs),
            'created'      => time(),
            'expires'      => time() + 86400 * 365,
        ];

        if (!empty($imgs)) {
            kvSet('atr_imgs:' . $id, $imgs);
        }

        $all   = getAllAtrs();
        $all[] = $atr;
        saveAtrs($all);
        ok(['id' => $id]);

    // ── האטרקציות שלי ─────────────────────────────────────────
    case 'my_attractions':
        $email = authUser($body);
        $all   = getAllAtrs();
        $mine  = array_values(array_filter($all, fn($a) =>
            ($a['pub_email'] ?? '') === $email
        ));
        foreach ($mine as &$atr) {
            $atr['images'] = kvGet('atr_imgs:' . $atr['id']) ?? [];
        }
        ok(['attractions' => $mine, 'categories' => ATR_CATS, 'cities' => ATR_CITIES]);

    // ── מחיקת אטרקציה ─────────────────────────────────────────
    case 'delete_attraction':
        $email = authUser($body);
        $id    = trim($body['id'] ?? '');
        if (!$id) fail('חסר מזהה');
        $all = array_values(array_filter(getAllAtrs(),
            fn($a) => !($a['id'] === $id && ($a['pub_email'] ?? '') === $email)
        ));
        saveAtrs($all);
        kvDel('atr_imgs:' . $id);
        deleteImages($id);
        ok();

    default:
        fail('פעולה לא מוכרת', 404);
}
