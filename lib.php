<?php
/**
 * lib.php – קוד משותף: מערכת IVR דירות לשבת (Twilio)
 */

// ── Configuration ──────────────────────────────────────────────
define('TWILIO_SID',   getenv('TWILIO_ACCOUNT_SID')        ?: '');
define('TWILIO_TOKEN', getenv('TWILIO_AUTH_TOKEN')          ?: '');
define('TWILIO_FROM',  getenv('TWILIO_PHONE_NUMBER')        ?: '');
define('SELF_URL',     getenv('IVR_SELF_URL')               ?: 'https://ivr-kursim-shabat.onrender.com/ivr_main.php');
define('ADMIN_PASS',   getenv('ADMIN_PASSWORD')             ?: 'Shabbat@2024!');
define('CRON_SECRET',  getenv('CRON_SECRET')                ?: 'cron_change_me');
define('REDIS_URL',    rtrim(getenv('UPSTASH_REDIS_REST_URL')   ?: '', '/'));
define('REDIS_TOKEN',  getenv('UPSTASH_REDIS_REST_TOKEN')   ?: '');

// ── File-based storage (fallback when Redis is not configured) ──
define('DATA_DIR', __DIR__ . '/data');

function dataDir(): string {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
        // Block web access to the data directory
        @file_put_contents(DATA_DIR . '/.htaccess', "Deny from all\n");
    }
    return DATA_DIR;
}

function fileKvGet(string $key): mixed {
    $path = dataDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    if (!file_exists($path)) return null;
    $data = @json_decode(file_get_contents($path), true);
    if (!$data) return null;
    if (isset($data['ttl']) && $data['ttl'] > 0 && time() > $data['ttl']) {
        @unlink($path);
        return null;
    }
    return $data['v'] ?? null;
}

function fileKvSet(string $key, mixed $value, int $ttlSec = 0): void {
    $path = dataDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    @file_put_contents($path, json_encode([
        'v'   => $value,
        'ttl' => $ttlSec > 0 ? time() + $ttlSec : 0,
    ]), LOCK_EX);
}

function fileKvDel(string $key): void {
    $path = dataDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    @unlink($path);
}

function hasRedis(): bool {
    return REDIS_URL !== '' && REDIS_TOKEN !== '';
}

function hasTwilio(): bool {
    return TWILIO_SID !== '' && TWILIO_TOKEN !== '' && TWILIO_FROM !== '';
}

// ── Lookup tables ──────────────────────────────────────────────

define('RENTAL_TYPES', [
    1 => 'שבת בלבד',
    2 => 'שבת החל מיום חמישי',
    3 => 'כל השבוע ראשון עד שבת',
]);

define('APT_TYPES', [
    1 => 'דירה רגילה',
    2 => 'דירה חדשה',
    3 => 'דירה משופצת',
    4 => 'דירת אירוח',
    5 => 'צימר',
    6 => 'דירה במושב',
    7 => 'דירה לחג הקרוב',
    8 => 'דירה לבין הזמנים',
]);

define('CITIES', [
    1  => 'ירושלים',
    2  => 'בני ברק',
    3  => 'אלעד',
    4  => 'מודיעין עילית',
    5  => 'ביתר עילית',
    6  => 'בית שמש',
    7  => 'צפת',
    8  => 'אשדוד',
    9  => 'נתניה',
    10 => 'תל אביב',
    11 => 'חיפה',
    12 => 'פתח תקווה',
    13 => 'ראשון לציון',
    14 => 'חדרה',
    15 => 'טבריה',
    16 => 'באר שבע',
    17 => 'אופקים',
    18 => 'עפולה',
]);

define('CITIES_PER_PAGE', 9);

define('NEIGHBORHOODS', [
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

// ── Upstash Redis ──────────────────────────────────────────────

function redisExec(array $cmd): mixed {
    if (!REDIS_URL || !REDIS_TOKEN) return null;
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: Bearer " . REDIS_TOKEN . "\r\nContent-Type: application/json",
        'content' => json_encode([$cmd]),
    ]]);
    $r = @file_get_contents(REDIS_URL . '/pipeline', false, $ctx);
    if (!$r) return null;
    $d = json_decode($r, true);
    return $d[0]['result'] ?? null;
}

function getAllApts(): array {
    if (hasRedis()) {
        $raw  = redisExec(['GET', 'apts_list']);
        $apts = ($raw !== null && $raw !== '') ? json_decode($raw, true) : [];
    } else {
        $apts = fileKvGet('apts_list') ?? [];
    }
    return is_array($apts) ? $apts : [];
}

function getApts(): array {
    $now = time();
    return array_values(array_filter(getAllApts(), fn($a) => ($a['expires'] ?? 0) > $now));
}

function saveApts(array $apts): void {
    $list = array_values($apts);
    if (hasRedis()) {
        redisExec(['SET', 'apts_list', json_encode($list)]);
    } else {
        fileKvSet('apts_list', $list);
    }
}

function filterApts(array $apts, array $f): array {
    return array_values(array_filter($apts, function (array $a) use ($f): bool {
        if (!empty($f['rental_type'])  && $f['rental_type']  != $a['rental_type'])                 return false;
        if (!empty($f['city'])         && $f['city']         != $a['city'])                         return false;
        if (!empty($f['neighborhood']) && $f['neighborhood'] != $a['neighborhood'])                 return false;
        if (!empty($f['apt_type'])     && $f['apt_type']     != $a['apt_type'])                     return false;
        if (!empty($f['beds_min'])     && $a['beds']         <  (int)$f['beds_min'])                return false;
        if (!empty($f['bedrooms_min']) && $a['bedrooms']     <  (int)$f['bedrooms_min'])            return false;
        if (!empty($f['price_max'])    && $a['price'] > 0    && $a['price'] > (int)$f['price_max']) return false;
        return true;
    }));
}

// ── Twilio SMS ─────────────────────────────────────────────────

function sendSMS(string $phone, string $msg): void {
    if (!TWILIO_SID || !TWILIO_TOKEN || !TWILIO_FROM) return;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: Basic " . base64_encode(TWILIO_SID . ':' . TWILIO_TOKEN)
                   . "\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => http_build_query(['From' => TWILIO_FROM, 'To' => $phone, 'Body' => $msg]),
    ]]);
    @file_get_contents($url, false, $ctx);
}

// ── TwiML helpers ──────────────────────────────────────────────

function stepUrl(string $step, array $extra = []): string {
    return SELF_URL . '?' . http_build_query(array_merge(['step' => $step], $extra));
}

function respond(string $twiml): void {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response>' . $twiml . '</Response>';
    exit;
}

function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function say(string ...$lines): string {
    return implode('', array_map(
        fn($l) => '<Say language="he-IL">' . xe($l) . '</Say>',
        $lines
    ));
}

function redir(string $url): string {
    return '<Redirect method="GET">' . xe($url) . '</Redirect>';
}

// Single-digit menu
function menu(string $action, array $lines): string {
    $says = implode('', array_map(fn($l) => '<Say language="he-IL">' . xe($l) . '</Say>', $lines));
    return '<Gather numDigits="1" action="' . xe($action) . '" method="GET" timeout="10">'
         . $says . '</Gather>'
         . '<Redirect method="GET">' . xe($action) . '</Redirect>';
}

// Multi-digit numeric input, finish with #
function numInput(string $action, string $prompt): string {
    return '<Gather finishOnKey="#" action="' . xe($action) . '" method="GET" timeout="15">'
         . '<Say language="he-IL">' . xe($prompt) . '</Say>'
         . '</Gather>'
         . '<Redirect method="GET">' . xe($action) . '</Redirect>';
}

// ── Shabbat ────────────────────────────────────────────────────

function isShabbat(): bool {
    $tz   = new DateTimeZone('Asia/Jerusalem');
    $now  = new DateTime('now', $tz);
    $dow  = (int)$now->format('N');
    $mins = (int)$now->format('G') * 60 + (int)$now->format('i');
    return ($dow === 5 && $mins >= 17 * 60 + 30)
        || ($dow === 6 && $mins <  20 * 60 + 30);
}

function nextShabbatEnd(): int {
    $tz  = new DateTimeZone('Asia/Jerusalem');
    $now = new DateTime('now', $tz);
    $dow = (int)$now->format('N');
    $min = (int)$now->format('G') * 60 + (int)$now->format('i');
    $d   = (6 - $dow + 7) % 7;
    if ($d === 0 && $min >= 20 * 60 + 30) $d = 7;
    $end = clone $now;
    $end->modify("+{$d} days")->setTime(20, 30, 0);
    return $end->getTimestamp();
}

// ── Lookups ────────────────────────────────────────────────────

function cityName(int $id): string      { return CITIES[$id]           ?? ''; }
function nhName(int $c, int $n): string { return NEIGHBORHOODS[$c][$n] ?? ''; }
function aptTypeName(int $id): string   { return APT_TYPES[$id]        ?? ''; }
function rentalName(int $id): string    { return RENTAL_TYPES[$id]     ?? ''; }

function locationStr(array $a): string {
    $s  = cityName($a['city']);
    $nh = ($a['neighborhood'] ?? 0) > 0 ? nhName($a['city'], $a['neighborhood']) : '';
    return $s . ($nh ? ' / ' . $nh : '');
}

function totalCityPages(): int {
    return (int)ceil(count(CITIES) / CITIES_PER_PAGE);
}

function citiesForPage(int $page): array {
    $offset = ($page - 1) * CITIES_PER_PAGE;
    $slice  = array_slice(CITIES, $offset, CITIES_PER_PAGE, true);
    $result = [];
    $key    = 1;
    foreach ($slice as $cid => $cname) {
        $result[$key++] = ['id' => $cid, 'name' => $cname];
    }
    return $result;
}

function paymentMsg(): string {
    return 'שימו לב! בסגירת עסקה יש לשלם 31 שקל מכל צד. '
         . 'ניתן לשלם בטלפון 03-6285809 שלוחה 5, '
         . 'או בנדרים פלוס לקופת מאגרים.';
}
