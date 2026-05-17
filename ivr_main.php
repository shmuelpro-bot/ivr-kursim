<?php
/**
 * מערכת IVR - קו דירות לשבת
 * ימות המשיח - פורמט INI
 */

define('API_KEY',  '0772519703_78098632');
define('BASE_URL', 'https://www.call2all.co.il/ym/api/');
define('SELF_URL', 'https://ivr-kursim.onrender.com/ivr_main.php');

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
    1 => 'ירושלים',
    2 => 'בני ברק',
    3 => 'אלעד',
    4 => 'מודיעין עילית',
    5 => 'ביתר עילית',
    6 => 'בית שמש',
    7 => 'צפת',
    8 => 'אשדוד',
    9 => 'נתניה',
]);

define('NEIGHBORHOODS', [
    1 => [1=>'מאה שערים', 2=>'גאולה', 3=>'קרית מטרסדורף', 4=>'רמות', 5=>'הר נוף', 6=>'בית וגן', 7=>'קרית יובל', 8=>'פסגת זאב'],
    2 => [1=>'מרכז', 2=>'קרית הרצוג', 3=>'קרית ויזניץ', 4=>'זכרון מאיר', 5=>'פארק נווה גן'],
    3 => [1=>'מרכז', 2=>'שכונה א', 3=>'שכונה ב'],
    4 => [1=>'קרית ספר', 2=>'מתתיהו', 3=>'חשמונאים'],
    5 => [1=>'מרכז', 2=>'שכונה א', 3=>'שכונה ב'],
    6 => [1=>'רמת בית שמש א', 2=>'רמת בית שמש ב', 3=>'רמת בית שמש ג', 4=>'מרכז העיר'],
    7 => [1=>'מרכז', 2=>'קרית חב"ד', 3=>'שכונת צאנז'],
    8 => [1=>'מרכז', 2=>'שכונה יא', 3=>'שכונה יב', 4=>'שכונה ז'],
    9 => [1=>'מרכז', 2=>'קרית נורדאו', 3=>'עיר ימים'],
]);

// ================================================================
// Utilities
// ================================================================

function callAPI(string $endpoint, array $params = []): ?array {
    $params['token'] = API_KEY;
    $url = BASE_URL . $endpoint . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    return $response !== false ? json_decode($response, true) : null;
}

function sendSMS(string $phone, string $message): void {
    callAPI('SendSms', ['phones' => $phone, 'message' => $message]);
}

function stepUrl(string $step, array $extra = []): string {
    $params = array_merge(['step' => $step], $extra);
    return SELF_URL . '?' . http_build_query($params);
}

function respond(array $ini): void {
    header('Content-Type: text/plain; charset=utf-8');
    $out = '';
    foreach ($ini as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $out .= $key . '=' . $v . "\n";
            }
        } else {
            $out .= $key . '=' . $value . "\n";
        }
    }
    echo $out;
    exit;
}

function isShabbat(): bool {
    $tz   = new DateTimeZone('Asia/Jerusalem');
    $now  = new DateTime('now', $tz);
    $dow  = (int)$now->format('N');
    $mins = (int)$now->format('G') * 60 + (int)$now->format('i');
    if ($dow === 5 && $mins >= 17 * 60 + 30) return true;
    if ($dow === 6 && $mins <  20 * 60 + 30) return true;
    return false;
}

function nextShabbatEnd(): int {
    $tz      = new DateTimeZone('Asia/Jerusalem');
    $now     = new DateTime('now', $tz);
    $dow     = (int)$now->format('N');
    $mins    = (int)$now->format('G') * 60 + (int)$now->format('i');
    $daysToSat = (6 - $dow + 7) % 7;
    if ($daysToSat === 0 && $mins >= 20 * 60 + 30) $daysToSat = 7;
    $end = clone $now;
    $end->modify("+{$daysToSat} days");
    $end->setTime(20, 30, 0);
    return $end->getTimestamp();
}

function getApts(): array {
    $raw  = callAPI('GetVar', ['var' => 'apts_list']);
    $apts = (isset($raw['value']) && $raw['value'] !== '') ? json_decode($raw['value'], true) : [];
    if (!is_array($apts)) $apts = [];
    $now = time();
    return array_values(array_filter($apts, fn($a) => ($a['expires'] ?? 0) > $now));
}

function saveApts(array $apts): void {
    callAPI('SetVar', ['var' => 'apts_list', 'value' => json_encode(array_values($apts))]);
}

function filterApts(array $apts, array $f): array {
    return array_values(array_filter($apts, function (array $a) use ($f): bool {
        if (!empty($f['rental_type'])  && $f['rental_type']  != $a['rental_type'])           return false;
        if (!empty($f['city'])         && $f['city']         != $a['city'])                   return false;
        if (!empty($f['neighborhood']) && $f['neighborhood'] != $a['neighborhood'])           return false;
        if (!empty($f['apt_type'])     && $f['apt_type']     != $a['apt_type'])               return false;
        if (!empty($f['beds_min'])     && $a['beds']         <  (int)$f['beds_min'])          return false;
        if (!empty($f['bedrooms_min']) && $a['bedrooms']     <  (int)$f['bedrooms_min'])      return false;
        if (!empty($f['price_max'])    && $a['price'] > 0    && $a['price'] > (int)$f['price_max']) return false;
        return true;
    }));
}

function cityName(int $id): string    { return CITIES[$id]          ?? ''; }
function nhName(int $c, int $n): string { return NEIGHBORHOODS[$c][$n] ?? ''; }
function aptTypeName(int $id): string  { return APT_TYPES[$id]       ?? ''; }
function rentalName(int $id): string   { return RENTAL_TYPES[$id]    ?? ''; }

function paymentMsg(): string {
    return 'שימו לב! בסגירת עסקה יש לשלם 31 שקל מכל צד. '
         . 'ניתן לשלם בטלפון 03-6285809 שלוחה 5, '
         . 'או בנדרים פלוס לקופת מאגרים.';
}

// ================================================================
// Routing
// ================================================================

$step  = $_GET['step'] ?? 'main';
$phone = $_GET['PhoneNumber'] ?? '';

switch ($step) {

    // ─────────────────────────────────────────────────────────────
    // MAIN MENU
    // ─────────────────────────────────────────────────────────────
    case 'main':
        respond([
            'type'             => 'menu',
            'id_list_message'  => [
                'שלום וברכה! ברוכים הבאים לקו דירות לשבת.',
                'לחיפוש דירה הקש 1.',
                'לפרסום דירה הקש 2.',
                'לחזרה על הפקודות הקש 9.',
            ],
            'id_list_1' => stepUrl('search_notice'),
            'id_list_2' => stepUrl('list_notice'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    // ─────────────────────────────────────────────────────────────
    // PAYMENT NOTICE – before search
    // ─────────────────────────────────────────────────────────────
    case 'search_notice':
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                paymentMsg(),
                'להמשיך לחיפוש הקש 1.',
                'לחזרה לתפריט הקש 9.',
            ],
            'id_list_1' => stepUrl('search_rental_type'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    // ─────────────────────────────────────────────────────────────
    // PAYMENT NOTICE – before listing
    // ─────────────────────────────────────────────────────────────
    case 'list_notice':
        if (isShabbat()) {
            respond([
                'type'            => 'menu',
                'id_list_message' => [
                    'מערכת הפרסום סגורה בשבת.',
                    'ניתן לפרסם דירות בימות החול בלבד.',
                ],
                'goto' => stepUrl('main'),
            ]);
        }
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                paymentMsg(),
                'להמשיך לפרסום הקש 1.',
                'לחזרה לתפריט הקש 9.',
            ],
            'id_list_1' => stepUrl('list_rental_type'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    // ================================================================
    //  LISTING FLOW  (owner registers an apartment)
    // ================================================================

    case 'list_rental_type':
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'שאלה 1 – זמן השכרה.',
                'הקש 1 לשבת בלבד.',
                'הקש 2 לשבת החל מיום חמישי.',
                'הקש 3 לכל השבוע.',
            ],
            'id_list_1' => stepUrl('list_city', ['rt' => 1]),
            'id_list_2' => stepUrl('list_city', ['rt' => 2]),
            'id_list_3' => stepUrl('list_city', ['rt' => 3]),
        ]);
        break;

    case 'list_city': {
        $rt   = $_GET['rt'] ?? 1;
        $msgs = ['שאלה 2 – בחר עיר.'];
        $res  = ['type' => 'menu'];
        foreach (CITIES as $cid => $cname) {
            $msgs[] = 'הקש ' . $cid . ' ל' . $cname . '.';
            $res['id_list_' . $cid] = stepUrl('list_neighborhood', ['rt' => $rt, 'ci' => $cid]);
        }
        $res['id_list_message'] = $msgs;
        respond($res);
        break;
    }

    case 'list_neighborhood': {
        $rt   = $_GET['rt'] ?? 1;
        $ci   = intval($_GET['ci'] ?? 1);
        $nhs  = NEIGHBORHOODS[$ci] ?? [];
        if (empty($nhs)) {
            respond(['type' => 'menu', 'goto' => stepUrl('list_street_ask', ['rt' => $rt, 'ci' => $ci, 'nh' => 0])]);
        }
        $msgs = ['שאלה 3 – בחר שכונה.'];
        $res  = ['type' => 'menu'];
        foreach ($nhs as $nid => $nname) {
            $msgs[] = 'הקש ' . $nid . ' ל' . $nname . '.';
            $res['id_list_' . $nid] = stepUrl('list_street_ask', ['rt' => $rt, 'ci' => $ci, 'nh' => $nid]);
        }
        $res['id_list_message'] = $msgs;
        respond($res);
        break;
    }

    case 'list_street_ask': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0];
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'שאלה 4 – האם תרצה להוסיף שם רחוב?',
                'הקש 1 להקלטת שם הרחוב.',
                'הקש 2 לדילוג.',
            ],
            'id_list_1' => stepUrl('list_street_record', $p),
            'id_list_2' => stepUrl('list_apt_type', array_merge($p, ['sr' => ''])),
        ]);
        break;
    }

    case 'list_street_record': {
        $p      = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0];
        $recFile = 'str_' . time() . '_' . rand(100, 999);
        respond([
            'type'            => 'menu',
            'id_list_message' => ['לאחר הצפצוף הקלט את שם הרחוב ולחץ סולמית.'],
            'record_type'     => 'record',
            'record_file'     => $recFile,
            'record_max_time' => '8',
            'goto'            => stepUrl('list_apt_type', array_merge($p, ['sr' => $recFile])),
        ]);
        break;
    }

    case 'list_apt_type': {
        $p = [
            'rt' => $_GET['rt'] ?? 1,
            'ci' => $_GET['ci'] ?? 1,
            'nh' => $_GET['nh'] ?? 0,
            'sr' => $_GET['sr'] ?? '',
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'שאלה 5 – סוג הדירה.',
                'הקש 1 לדירה רגילה.',
                'הקש 2 לדירה חדשה.',
                'הקש 3 לדירה משופצת.',
                'הקש 4 לדירת אירוח.',
                'הקש 5 לצימר.',
                'הקש 6 לדירה במושב.',
                'הקש 7 לדירה לחג הקרוב.',
                'הקש 8 לדירה לבין הזמנים.',
            ],
            'id_list_1' => stepUrl('list_beds', array_merge($p, ['at' => 1])),
            'id_list_2' => stepUrl('list_beds', array_merge($p, ['at' => 2])),
            'id_list_3' => stepUrl('list_beds', array_merge($p, ['at' => 3])),
            'id_list_4' => stepUrl('list_beds', array_merge($p, ['at' => 4])),
            'id_list_5' => stepUrl('list_beds', array_merge($p, ['at' => 5])),
            'id_list_6' => stepUrl('list_beds', array_merge($p, ['at' => 6])),
            'id_list_7' => stepUrl('list_beds', array_merge($p, ['at' => 7])),
            'id_list_8' => stepUrl('list_beds', array_merge($p, ['at' => 8])),
        ]);
        break;
    }

    case 'list_beds': {
        $p = [
            'rt' => $_GET['rt'] ?? 1,
            'ci' => $_GET['ci'] ?? 1,
            'nh' => $_GET['nh'] ?? 0,
            'sr' => $_GET['sr'] ?? '',
            'at' => $_GET['at'] ?? 1,
        ];
        respond([
            'type'             => 'menu',
            'id_list_message'  => ['שאלה 6 – הקלד מספר מיטות כולל מזרנים ולחץ סולמית.'],
            'read_type'        => 'dtmf',
            'read_max_digits'  => '2',
            'read_variable'    => 'BEDS',
            'goto'             => stepUrl('list_bedrooms', $p),
        ]);
        break;
    }

    case 'list_bedrooms': {
        $p = [
            'rt'   => $_GET['rt']   ?? 1,
            'ci'   => $_GET['ci']   ?? 1,
            'nh'   => $_GET['nh']   ?? 0,
            'sr'   => $_GET['sr']   ?? '',
            'at'   => $_GET['at']   ?? 1,
            'BEDS' => $_GET['BEDS'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['שאלה 7 – הקלד מספר חדרי שינה. לסטודיו הקש 0. ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '2',
            'read_variable'   => 'ROOMS',
            'goto'            => stepUrl('list_price', $p),
        ]);
        break;
    }

    case 'list_price': {
        $p = [
            'rt'    => $_GET['rt']    ?? 1,
            'ci'    => $_GET['ci']    ?? 1,
            'nh'    => $_GET['nh']    ?? 0,
            'sr'    => $_GET['sr']    ?? '',
            'at'    => $_GET['at']    ?? 1,
            'BEDS'  => $_GET['BEDS']  ?? 0,
            'ROOMS' => $_GET['ROOMS'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['שאלה 8 – הקלד מחיר ללילה בשקלים ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '5',
            'read_variable'   => 'PRICE',
            'goto'            => stepUrl('list_confirm', $p),
        ]);
        break;
    }

    case 'list_confirm': {
        $rt    = intval($_GET['rt']    ?? 1);
        $ci    = intval($_GET['ci']    ?? 1);
        $nh    = intval($_GET['nh']    ?? 0);
        $at    = intval($_GET['at']    ?? 1);
        $beds  = intval($_GET['BEDS']  ?? 0);
        $rooms = intval($_GET['ROOMS'] ?? 0);
        $price = intval($_GET['PRICE'] ?? 0);

        $nhTxt    = $nh > 0  ? nhName($ci, $nh) : 'לא צוין';
        $roomsTxt = $rooms === 0 ? 'סטודיו' : $rooms . ' חדרי שינה';
        $priceTxt = $price > 0  ? $price . ' שקל ללילה' : 'מחיר לא צוין';

        $p = [
            'rt' => $rt, 'ci' => $ci, 'nh' => $nh,
            'sr' => $_GET['sr'] ?? '',
            'at' => $at, 'BEDS' => $beds, 'ROOMS' => $rooms, 'PRICE' => $price,
        ];

        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'סיכום הדירה שלך.',
                'עיר: ' . cityName($ci) . '.',
                'שכונה: ' . $nhTxt . '.',
                'סוג דירה: ' . aptTypeName($at) . '.',
                'מספר מיטות: ' . $beds . '.',
                $roomsTxt . '.',
                $priceTxt . '.',
                'זמן השכרה: ' . rentalName($rt) . '.',
                'לאישור ושמירה הקש 1.',
                'לביטול הקש 9.',
            ],
            'id_list_1' => stepUrl('list_save', $p),
            'id_list_9' => stepUrl('main'),
        ]);
        break;
    }

    case 'list_save': {
        $rt    = intval($_GET['rt']    ?? 1);
        $ci    = intval($_GET['ci']    ?? 1);
        $nh    = intval($_GET['nh']    ?? 0);
        $sr    = $_GET['sr']           ?? '';
        $at    = intval($_GET['at']    ?? 1);
        $beds  = intval($_GET['BEDS']  ?? 0);
        $rooms = intval($_GET['ROOMS'] ?? 0);
        $price = intval($_GET['PRICE'] ?? 0);

        $apts = getApts();
        $now  = time();
        $id   = $now . '_' . rand(1000, 9999);

        $apts[] = [
            'id'           => $id,
            'owner_phone'  => $phone,
            'rental_type'  => $rt,
            'city'         => $ci,
            'neighborhood' => $nh,
            'street_rec'   => $sr,
            'apt_type'     => $at,
            'beds'         => $beds,
            'bedrooms'     => $rooms,
            'price'        => $price,
            'created'      => $now,
            'expires'      => nextShabbatEnd(),
        ];
        saveApts($apts);

        $loc = cityName($ci) . ($nh > 0 ? ', ' . nhName($ci, $nh) : '');
        sendSMS($phone, "דירתך ב{$loc} פורסמה! מזהה: {$id}. הפרסום יסתיים בצאת השבת.");

        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'הדירה פורסמה בהצלחה!',
                'הפרסום פעיל עד צאת השבת.',
                'נשלח אישור ב SMS.',
            ],
            'goto' => stepUrl('main'),
        ]);
        break;
    }

    // ================================================================
    //  SEARCH FLOW  (tenant searches for an apartment)
    // ================================================================

    case 'search_rental_type':
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'סנן לפי זמן השכרה.',
                'הקש 0 לכל הדירות.',
                'הקש 1 לשבת בלבד.',
                'הקש 2 לשבת החל מיום חמישי.',
                'הקש 3 לכל השבוע.',
            ],
            'id_list_0' => stepUrl('search_city', ['fr' => 0]),
            'id_list_1' => stepUrl('search_city', ['fr' => 1]),
            'id_list_2' => stepUrl('search_city', ['fr' => 2]),
            'id_list_3' => stepUrl('search_city', ['fr' => 3]),
        ]);
        break;

    case 'search_city': {
        $fr   = $_GET['fr'] ?? 0;
        $msgs = ['סנן לפי עיר. הקש 0 לכל הערים.'];
        $res  = ['type' => 'menu'];
        $res['id_list_0'] = stepUrl('search_apt_type', ['fr' => $fr, 'fc' => 0, 'fn' => 0]);
        foreach (CITIES as $cid => $cname) {
            $msgs[] = 'הקש ' . $cid . ' ל' . $cname . '.';
            $res['id_list_' . $cid] = stepUrl('search_neighborhood', ['fr' => $fr, 'fc' => $cid]);
        }
        $res['id_list_message'] = $msgs;
        respond($res);
        break;
    }

    case 'search_neighborhood': {
        $fr  = $_GET['fr'] ?? 0;
        $fc  = intval($_GET['fc'] ?? 0);
        $nhs = $fc > 0 ? (NEIGHBORHOODS[$fc] ?? []) : [];
        if (empty($nhs)) {
            respond(['type' => 'menu', 'goto' => stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => 0])]);
        }
        $msgs = ['סנן לפי שכונה. הקש 0 לכל השכונות.'];
        $res  = ['type' => 'menu'];
        $res['id_list_0'] = stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => 0]);
        foreach ($nhs as $nid => $nname) {
            $msgs[] = 'הקש ' . $nid . ' ל' . $nname . '.';
            $res['id_list_' . $nid] = stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => $nid]);
        }
        $res['id_list_message'] = $msgs;
        respond($res);
        break;
    }

    case 'search_apt_type': {
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0];
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'סנן לפי סוג דירה.',
                'הקש 0 לכל הסוגים.',
                'הקש 1 לדירה רגילה.',
                'הקש 2 לדירה חדשה.',
                'הקש 3 לדירה משופצת.',
                'הקש 4 לדירת אירוח.',
                'הקש 5 לצימר.',
                'הקש 6 לדירה במושב.',
                'הקש 7 לדירה לחג הקרוב.',
                'הקש 8 לדירה לבין הזמנים.',
            ],
            'id_list_0' => stepUrl('search_beds', array_merge($p, ['fa' => 0])),
            'id_list_1' => stepUrl('search_beds', array_merge($p, ['fa' => 1])),
            'id_list_2' => stepUrl('search_beds', array_merge($p, ['fa' => 2])),
            'id_list_3' => stepUrl('search_beds', array_merge($p, ['fa' => 3])),
            'id_list_4' => stepUrl('search_beds', array_merge($p, ['fa' => 4])),
            'id_list_5' => stepUrl('search_beds', array_merge($p, ['fa' => 5])),
            'id_list_6' => stepUrl('search_beds', array_merge($p, ['fa' => 6])),
            'id_list_7' => stepUrl('search_beds', array_merge($p, ['fa' => 7])),
            'id_list_8' => stepUrl('search_beds', array_merge($p, ['fa' => 8])),
        ]);
        break;
    }

    case 'search_beds': {
        $p = [
            'fr' => $_GET['fr'] ?? 0,
            'fc' => $_GET['fc'] ?? 0,
            'fn' => $_GET['fn'] ?? 0,
            'fa' => $_GET['fa'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['הקלד מספר מיטות מינימום ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '2',
            'read_variable'   => 'FB',
            'goto'            => stepUrl('search_bedrooms', $p),
        ]);
        break;
    }

    case 'search_bedrooms': {
        $p = [
            'fr' => $_GET['fr'] ?? 0,
            'fc' => $_GET['fc'] ?? 0,
            'fn' => $_GET['fn'] ?? 0,
            'fa' => $_GET['fa'] ?? 0,
            'FB' => $_GET['FB'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['הקלד מספר חדרי שינה מינימום ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '2',
            'read_variable'   => 'FBR',
            'goto'            => stepUrl('search_price', $p),
        ]);
        break;
    }

    case 'search_price': {
        $p = [
            'fr'  => $_GET['fr']  ?? 0,
            'fc'  => $_GET['fc']  ?? 0,
            'fn'  => $_GET['fn']  ?? 0,
            'fa'  => $_GET['fa']  ?? 0,
            'FB'  => $_GET['FB']  ?? 0,
            'FBR' => $_GET['FBR'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['הקלד מחיר מקסימום ללילה בשקלים ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '5',
            'read_variable'   => 'FP',
            'goto'            => stepUrl('search_results', array_merge($p, ['idx' => 0])),
        ]);
        break;
    }

    case 'search_results': {
        $fr  = intval($_GET['fr']  ?? 0);
        $fc  = intval($_GET['fc']  ?? 0);
        $fn  = intval($_GET['fn']  ?? 0);
        $fa  = intval($_GET['fa']  ?? 0);
        $fb  = intval($_GET['FB']  ?? 0);
        $fbr = intval($_GET['FBR'] ?? 0);
        $fp  = intval($_GET['FP']  ?? 0);
        $idx = intval($_GET['idx'] ?? 0);

        $filters = [
            'rental_type'   => $fr,
            'city'          => $fc,
            'neighborhood'  => $fn,
            'apt_type'      => $fa,
            'beds_min'      => $fb,
            'bedrooms_min'  => $fbr,
            'price_max'     => $fp,
        ];

        $results = filterApts(getApts(), $filters);
        $total   = count($results);

        $fParams = ['fr' => $fr, 'fc' => $fc, 'fn' => $fn, 'fa' => $fa,
                    'FB' => $fb, 'FBR' => $fbr, 'FP' => $fp];

        if ($total === 0) {
            respond([
                'type'            => 'menu',
                'id_list_message' => [
                    'לא נמצאו דירות התואמות את הסינון שלך.',
                    'לחיפוש חדש הקש 1.',
                    'לתפריט הראשי הקש 9.',
                ],
                'id_list_1' => stepUrl('search_notice'),
                'id_list_9' => stepUrl('main'),
            ]);
        }

        if ($idx >= $total) {
            respond([
                'type'            => 'menu',
                'id_list_message' => [
                    'הגעת לסוף הרשימה.',
                    'לחיפוש חדש הקש 1.',
                    'לתפריט הראשי הקש 9.',
                ],
                'id_list_1' => stepUrl('search_notice'),
                'id_list_9' => stepUrl('main'),
            ]);
        }

        $apt      = $results[$idx];
        $num      = $idx + 1;
        $cn       = cityName($apt['city']);
        $nn       = $apt['neighborhood'] > 0 ? nhName($apt['city'], $apt['neighborhood']) : '';
        $loc      = $cn . ($nn ? ', שכונת ' . $nn : '');
        $priceTxt = $apt['price'] > 0 ? $apt['price'] . ' שקל ללילה' : 'מחיר לא צוין';
        $roomsTxt = $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חדרי שינה';

        $nextP = array_merge($fParams, ['idx' => $idx + 1]);
        $prevP = array_merge($fParams, ['idx' => max(0, $idx - 1)]);
        $curP  = array_merge($fParams, ['idx' => $idx]);

        $msgs = [
            'דירה ' . $num . ' מתוך ' . $total . '.',
            'מיקום: ' . $loc . '.',
            'סוג: ' . aptTypeName($apt['apt_type']) . '.',
            'מיטות: ' . $apt['beds'] . '.',
            $roomsTxt . '.',
            $priceTxt . '.',
            'זמן השכרה: ' . rentalName($apt['rental_type']) . '.',
        ];

        if (!empty($apt['street_rec'])) {
            $msgs[] = 'רחוב: ';
            $msgs[] = 't:' . $apt['street_rec'];
        }

        $msgs[] = 'לדירה הבאה הקש 1.';
        if ($idx > 0) $msgs[] = 'לדירה הקודמת הקש 2.';
        $msgs[] = 'לקבלת פרטי קשר עם בעל הדירה הקש 3.';
        $msgs[] = 'לתפריט הראשי הקש 9.';

        $res = [
            'type'            => 'menu',
            'id_list_message' => $msgs,
            'id_list_1'       => stepUrl('search_results', $nextP),
            'id_list_3'       => stepUrl('search_contact', array_merge($curP, ['own' => $apt['owner_phone']])),
            'id_list_9'       => stepUrl('main'),
        ];
        if ($idx > 0) $res['id_list_2'] = stepUrl('search_results', $prevP);

        respond($res);
        break;
    }

    case 'search_contact': {
        $ownerPhone = $_GET['own'] ?? '';
        $fParams = [
            'fr'  => $_GET['fr']  ?? 0,
            'fc'  => $_GET['fc']  ?? 0,
            'fn'  => $_GET['fn']  ?? 0,
            'fa'  => $_GET['fa']  ?? 0,
            'FB'  => $_GET['FB']  ?? 0,
            'FBR' => $_GET['FBR'] ?? 0,
            'FP'  => $_GET['FP']  ?? 0,
            'idx' => $_GET['idx'] ?? 0,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                paymentMsg(),
                'מספר הטלפון של בעל הדירה הוא ' . $ownerPhone . '.',
                'לחזרה לרשימה הקש 1.',
                'לתפריט הראשי הקש 9.',
            ],
            'id_list_1' => stepUrl('search_results', $fParams),
            'id_list_9' => stepUrl('main'),
        ]);
        break;
    }

    // ─────────────────────────────────────────────────────────────
    default:
        respond(['type' => 'menu', 'goto' => stepUrl('main')]);
        break;
}
