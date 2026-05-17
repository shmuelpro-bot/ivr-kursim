<?php
/**
 * lib.php – קוד משותף: מערכת IVR דירות לשבת
 * יש להכניס קובץ זה לפני כל קוד אחר.
 */

// ── Configuration (env vars override defaults) ─────────────────
define('API_KEY',    getenv('CALL2ALL_TOKEN')  ?: '0772519703_78098632');
define('BASE_URL',   'https://www.call2all.co.il/ym/api/');
define('SELF_URL',   getenv('IVR_SELF_URL')    ?: 'https://ivr-kursim.onrender.com/ivr_main.php');
define('ADMIN_PASS', getenv('ADMIN_PASSWORD')  ?: 'Shabbat@2024!');
define('CRON_SECRET',getenv('CRON_SECRET')     ?: 'cron_change_me');

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

// 18 ערים, 9 בכל עמוד IVR
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

// ── API ────────────────────────────────────────────────────────

function callAPI(string $ep, array $p = []): ?array {
    $p['token'] = API_KEY;
    $r = @file_get_contents(BASE_URL . $ep . '?' . http_build_query($p));
    return ($r !== false) ? json_decode($r, true) : null;
}

function sendSMS(string $phone, string $msg): void {
    callAPI('SendSms', ['phones' => $phone, 'message' => $msg]);
}

// ── IVR helpers ────────────────────────────────────────────────

function stepUrl(string $step, array $extra = []): string {
    return SELF_URL . '?' . http_build_query(array_merge(['step' => $step], $extra));
}

function respond(array $ini): void {
    header('Content-Type: text/plain; charset=utf-8');
    $out = '';
    foreach ($ini as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) $out .= $key . '=' . $v . "\n";
        } else {
            $out .= $key . '=' . $value . "\n";
        }
    }
    echo $out;
    exit;
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

// ── Data store ─────────────────────────────────────────────────

function getAllApts(): array {
    $raw  = callAPI('GetVar', ['var' => 'apts_list']);
    $apts = (isset($raw['value']) && $raw['value'] !== '') ? json_decode($raw['value'], true) : [];
    return is_array($apts) ? $apts : [];
}

function getApts(): array {
    $now = time();
    return array_values(array_filter(getAllApts(), fn($a) => ($a['expires'] ?? 0) > $now));
}

function saveApts(array $apts): void {
    callAPI('SetVar', ['var' => 'apts_list', 'value' => json_encode(array_values($apts))]);
}

function filterApts(array $apts, array $f): array {
    return array_values(array_filter($apts, function (array $a) use ($f): bool {
        if (!empty($f['rental_type'])  && $f['rental_type']  != $a['rental_type'])                    return false;
        if (!empty($f['city'])         && $f['city']         != $a['city'])                            return false;
        if (!empty($f['neighborhood']) && $f['neighborhood'] != $a['neighborhood'])                    return false;
        if (!empty($f['apt_type'])     && $f['apt_type']     != $a['apt_type'])                        return false;
        if (!empty($f['beds_min'])     && $a['beds']         <  (int)$f['beds_min'])                   return false;
        if (!empty($f['bedrooms_min']) && $a['bedrooms']     <  (int)$f['bedrooms_min'])               return false;
        if (!empty($f['price_max'])    && $a['price'] > 0    && $a['price'] > (int)$f['price_max'])    return false;
        return true;
    }));
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

// ── City pagination (9 cities per IVR page, keys 1–9) ──────────

function totalCityPages(): int {
    return (int)ceil(count(CITIES) / CITIES_PER_PAGE);
}

/**
 * Returns array keyed 1–9 with ['id'=>cityId, 'name'=>cityName]
 * for the given 1-based page number.
 */
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

// ── Payment message ────────────────────────────────────────────

function paymentMsg(): string {
    return 'שימו לב! בסגירת עסקה יש לשלם 31 שקל מכל צד. '
         . 'ניתן לשלם בטלפון 03-6285809 שלוחה 5, '
         . 'או בנדרים פלוס לקופת מאגרים.';
}
