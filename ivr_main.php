<?php
/**
 * מערכת IVR - קו הקורסים והסדנאות
 * Twilio TwiML
 */

// ===== הגדרות - יש למלא לפני העלאה =====
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // מ-Twilio Console
define('TWILIO_AUTH_TOKEN',   'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');   // מ-Twilio Console
define('TWILIO_FROM',         '+1xxxxxxxxxx');                       // מספר Twilio שלך
define('NEDARIM_MOSAD_ID',    '7007382');
define('NEDARIM_API_PASS',    'nb252');
define('NEDARIM_API_URL',     'https://matara.pro/nedarimplus/online/api.aspx');
define('PRICE_WEEK',          25);
define('PRICE_OPENING_AD',    25);
define('SELF_URL',            'https://ivr-kursim.onrender.com/ivr_main.php');
define('ADMIN_PHONE',         '+972500000000'); // יש לעדכן
define('ADMIN_PIN',           '1234');          // יש לעדכן
define('DATA_DIR',            __DIR__ . '/data');

define('CATEGORIES', [
    1 => 'קורסים ושיעורי תורה',
    2 => 'שיעורים פרטיים ולימוד לבר מצווה',
    3 => 'סדנאות ופיתוח אישי',
    4 => 'קייטנות וחוגים לילדים',
    5 => 'קורסים מקצועיים',
]);

// ===== אחסון (קבצי JSON) =====
// הערה: ב-Render Free Tier האחסון זמני. להפעלת אחסון קבוע — הוסף Disk ב-Render Dashboard.

function getData(string $key) {
    $file = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key) . '.json';
    if (!file_exists($file)) return null;
    $raw = file_get_contents($file);
    return $raw !== false ? json_decode($raw, true) : null;
}

function setData(string $key, $value): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $file = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key) . '.json';
    file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ===== SMS דרך Twilio REST API =====

function sendSMS(string $to, string $body): void {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['From' => TWILIO_FROM, 'To' => $to, 'Body' => $body]),
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ===== מודעות =====

function getAllAds(): array {
    return getData('ads_list') ?? [];
}

function getActiveAds(?int $category = null): array {
    $now    = time();
    $active = array_filter(getAllAds(), fn($a) =>
        ($a['expires'] ?? 0) > $now && ($category === null || $a['category'] == $category)
    );
    usort($active, fn($a, $b) => ($b['created'] ?? 0) - ($a['created'] ?? 0));
    return array_values($active);
}

function getActiveOpeningAd(): ?array {
    $ad = getData('opening_ad');
    return ($ad && ($ad['expires'] ?? 0) > time()) ? $ad : null;
}

function deleteAd(string $adId): void {
    setData('ads_list', array_values(array_filter(getAllAds(), fn($a) => $a['id'] !== $adId)));
}

// ===== משתמשים =====

function phoneKey(string $phone): string {
    return 'user_' . preg_replace('/\D/', '', $phone);
}

function isUserRegistered(string $phone): bool {
    return getData(phoneKey($phone)) !== null;
}

function registerUser(string $phone): void {
    setData(phoneKey($phone), ['phone' => $phone, 'alerts' => [], 'registered' => time()]);
    $list = getData('users_list') ?? [];
    if (!in_array($phone, $list)) {
        $list[] = $phone;
        setData('users_list', $list);
    }
}

function getUser(string $phone): ?array {
    return getData(phoneKey($phone));
}

function saveUser(string $phone, array $user): void {
    setData(phoneKey($phone), $user);
}

function getTotalUsers(): int {
    return (int)(getData('total_users') ?? 0);
}

function incrementTotalUsers(): void {
    setData('total_users', getTotalUsers() + 1);
}

function notifySubscribers(int $category): void {
    $catName = CATEGORIES[$category] ?? '';
    foreach ((getData('users_list') ?? []) as $p) {
        $user   = getUser($p);
        $alerts = $user['alerts'] ?? [];
        if (isset($alerts[$category]) || isset($alerts[6])) {
            sendSMS($p, "פרסום חדש זמין בקטגוריית {$catName}. להאזנה התקשר לקו הקורסים.");
        }
    }
}

// ===== TwiML helpers =====

function stepUrl(string $step, array $extra = []): string {
    return SELF_URL . '?' . http_build_query(array_merge($extra, ['step' => $step]));
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function say(string $text): string {
    return '<Say language="he-IL" voice="Polly.Yael">' . e($text) . '</Say>';
}

function sayLines(array $lines): string {
    return implode("\n", array_map('say', $lines));
}

function gather(string $action, string $inner, int $numDigits = 1, int $timeout = 8): string {
    return '<Gather numDigits="' . $numDigits . '" action="' . e($action) . '" method="GET" timeout="' . $timeout . '">'
        . "\n" . $inner . "\n</Gather>";
}

function menu(array $lines, string $handleStep, array $extra = []): string {
    return gather(stepUrl($handleStep, $extra), sayLines($lines))
        . "\n" . '<Redirect method="GET">' . e(stepUrl('main')) . '</Redirect>';
}

function go(string $url): string {
    return '<Redirect method="GET">' . e($url) . '</Redirect>';
}

function twiml(string $inner): void {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Response>\n" . $inner . "\n</Response>\n";
    exit;
}

// ===== Entry point =====

$step  = $_GET['step'] ?? 'main';
$phone = $_REQUEST['From'] ?? $_GET['PhoneNumber'] ?? '';

switch ($step) {

    // ===== תפריט ראשי =====

    case 'main':
        incrementTotalUsers();
        $total  = getTotalUsers();
        $openAd = getActiveOpeningAd();
        $inner  = '';
        if ($openAd && !empty($openAd['recording_url'])) {
            $inner .= '<Play>' . e($openAd['recording_url']) . '</Play>' . "\n";
        }
        $inner .= menu([
            'שלום! ברוכים הבאים לקו הקורסים, השיעורים והסדנאות.',
            'עד כה התקשרו אלינו ' . $total . ' מתקשרים.',
            'לשמיעת הפרסומים הקש 1.',
            'לפרסום קורס, שיעור, סדנה או חוג הקש 2.',
            'לאזור האישי והרשמה להתראות הקש 3.',
            'למידע ותעריפים הקש 4.',
            'להשארת הודעה למנהל הקש 5.',
        ], 'main_handle');
        twiml($inner);

    case 'main_handle':
        $d = $_GET['Digits'] ?? '';
        $map = ['1' => 'menu1', '2' => 'menu2_start', '3' => 'menu3_start',
                '4' => 'menu4_info', '5' => 'menu5_voicemail', '0' => 'admin_login'];
        twiml(go(stepUrl($map[$d] ?? 'main')));

    // ===== האזנה לפרסומים =====

    case 'menu1':
        twiml(menu([
            'בחר את הקטגוריה שברצונך לשמוע.',
            'קורסים ושיעורי תורה - הקש 1.',
            'שיעורים פרטיים ולימוד לבר מצווה - הקש 2.',
            'סדנאות ופיתוח אישי - הקש 3.',
            'קייטנות וחוגים לילדים - הקש 4.',
            'קורסים מקצועיים - הקש 5.',
            'לשמיעת כל הפרסומים - הקש 6.',
            'לחזרה לתפריט הראשי - הקש 9.',
        ], 'menu1_handle'));

    case 'menu1_handle':
        $d   = $_GET['Digits'] ?? '';
        $map = ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 0];
        if (!isset($map[$d])) twiml(go(stepUrl('main')));
        twiml(go(stepUrl('listen', ['cat' => $map[$d], 'idx' => 0])));

    case 'listen':
        $cat     = intval($_GET['cat'] ?? 0);
        $idx     = intval($_GET['idx'] ?? 0);
        $ads     = $cat > 0 ? getActiveAds($cat) : getActiveAds();
        $catName = $cat > 0 ? (CATEGORIES[$cat] ?? '') : 'כל הקטגוריות';

        if (empty($ads)) {
            twiml(say('אין כרגע פרסומים בקטגוריית ' . $catName . '. חוזרים לתפריט.')
                . "\n" . go(stepUrl('menu1')));
        }
        if ($idx >= count($ads)) {
            twiml(say('הגעת לסוף הפרסומים בקטגוריה זו. חוזרים לתפריט.')
                . "\n" . go(stepUrl('menu1')));
        }

        $ad    = $ads[$idx];
        $num   = $idx + 1;
        $total = count($ads);
        $inner = say('פרסום ' . $num . ' מתוך ' . $total . '.') . "\n";
        if (!empty($ad['recording_url'])) {
            $inner .= '<Play>' . e($ad['recording_url']) . '</Play>' . "\n";
        }
        $inner .= say('ליצירת קשר עם המפרסם חייג: ' . $ad['phone'] . '.') . "\n";
        $inner .= menu([
            'לחזרה על ההקלטה הקש כוכבית.',
            'לפרסום הבא הקש 1.',
            'להתקשרות ישירה למפרסם הקש 2.',
            'לחזרה לתפריט הקש 9.',
        ], 'listen_handle', ['cat' => $cat, 'idx' => $idx, 'ad_phone' => $ad['phone']]);
        twiml($inner);

    case 'listen_handle':
        $d       = $_GET['Digits'] ?? '';
        $cat     = intval($_GET['cat'] ?? 0);
        $idx     = intval($_GET['idx'] ?? 0);
        $adPhone = $_GET['ad_phone'] ?? '';
        if ($d === '1') twiml(go(stepUrl('listen', ['cat' => $cat, 'idx' => $idx + 1])));
        if ($d === '2') twiml(say('מחבר אותך כעת למפרסם. אנא המתן.') . "\n<Dial>" . e($adPhone) . '</Dial>');
        if ($d === '9') twiml(go(stepUrl('menu1')));
        twiml(go(stepUrl('listen', ['cat' => $cat, 'idx' => $idx])));

    // ===== פרסום מודעה =====

    case 'menu2_start':
        twiml(menu([
            'ברוכים הבאים למערכת הפרסום.',
            'עלות פרסום שבועי היא 25 שקלים.',
            'להמשך הקש 1. לחזרה לתפריט הקש 9.',
        ], 'menu2_start_handle'));

    case 'menu2_start_handle':
        $d = $_GET['Digits'] ?? '';
        if ($d === '9') twiml(go(stepUrl('main')));
        twiml(
            gather(stepUrl('menu2_category'), say('הקש את מספר הטלפון שלך ולחץ סולמית.'), 11, 20)
            . "\n" . go(stepUrl('menu2_start'))
        );

    case 'menu2_category':
        $pub = $_GET['Digits'] ?? $phone;
        twiml(menu([
            'בחר את קטגוריית הפרסום שלך.',
            'קורסים ושיעורי תורה - הקש 1.',
            'שיעורים פרטיים - הקש 2.',
            'סדנאות ופיתוח אישי - הקש 3.',
            'קייטנות וחוגים לילדים - הקש 4.',
            'קורסים מקצועיים - הקש 5.',
        ], 'menu2_cat_handle', ['pub' => $pub]));

    case 'menu2_cat_handle':
        $pub = $_GET['pub'] ?? $phone;
        $d   = $_GET['Digits'] ?? '';
        if (!in_array($d, ['1','2','3','4','5'])) twiml(go(stepUrl('menu2_category', ['Digits' => $pub])));
        twiml(go(stepUrl('menu2_region', ['cat' => $d, 'pub' => $pub])));

    case 'menu2_region':
        $cat = $_GET['cat'] ?? 1;
        $pub = $_GET['pub'] ?? $phone;
        twiml(menu([
            'בחר את האזור הגיאוגרפי של הפרסום שלך.',
            'ירושלים והסביבה - הקש 1.',
            'מרכז הארץ - הקש 2.',
            'צפון - הקש 3.',
            'דרום - הקש 4.',
        ], 'menu2_region_handle', ['cat' => $cat, 'pub' => $pub]));

    case 'menu2_region_handle':
        $cat = $_GET['cat'] ?? 1;
        $pub = $_GET['pub'] ?? $phone;
        $d   = $_GET['Digits'] ?? '';
        if (!in_array($d, ['1','2','3','4'])) twiml(go(stepUrl('menu2_region', ['cat' => $cat, 'pub' => $pub])));
        twiml(go(stepUrl('menu2_duration', ['cat' => $cat, 'pub' => $pub, 'reg' => $d])));

    case 'menu2_duration':
        $cat = $_GET['cat'] ?? 1;
        $pub = $_GET['pub'] ?? $phone;
        $reg = $_GET['reg'] ?? 1;
        twiml(menu([
            'לכמה ימים תרצה לפרסם?',
            'יום אחד - הקש 1.',
            'יומיים - הקש 2.',
            'שלושה ימים - הקש 3.',
            'ארבעה ימים - הקש 4.',
            'חמישה ימים - הקש 5.',
            'ששה ימים - הקש 6.',
            'שבוע שלם - הקש 7.',
        ], 'menu2_dur_handle', ['cat' => $cat, 'pub' => $pub, 'reg' => $reg]));

    case 'menu2_dur_handle':
        $cat = $_GET['cat'] ?? 1;
        $pub = $_GET['pub'] ?? $phone;
        $reg = $_GET['reg'] ?? 1;
        $d   = $_GET['Digits'] ?? '';
        if (!in_array($d, ['1','2','3','4','5','6','7'])) {
            twiml(go(stepUrl('menu2_duration', ['cat' => $cat, 'pub' => $pub, 'reg' => $reg])));
        }
        twiml(go(stepUrl('menu2_record', ['cat' => $cat, 'pub' => $pub, 'reg' => $reg, 'days' => $d])));

    case 'menu2_record':
        $action = stepUrl('menu2_review', [
            'cat'  => $_GET['cat'] ?? 1,
            'pub'  => $_GET['pub'] ?? $phone,
            'reg'  => $_GET['reg'] ?? 1,
            'days' => $_GET['days'] ?? 7,
        ]);
        twiml(
            say('לאחר הצפצוף הקלט את הפרסומת שלך. משך מקסימלי דקה וחצי. לסיום ההקלטה לחץ סולמית.')
            . "\n" . '<Record action="' . e($action) . '" method="GET" maxLength="90" finishOnKey="#" playBeep="true"/>'
        );

    case 'menu2_review':
        $cat    = $_GET['cat'] ?? 1;
        $pub    = $_GET['pub'] ?? $phone;
        $reg    = $_GET['reg'] ?? 1;
        $days   = $_GET['days'] ?? 7;
        $recUrl = $_GET['RecordingUrl'] ?? '';
        $recSid = $_GET['RecordingSid'] ?? '';
        $inner  = !empty($recUrl) ? '<Play>' . e($recUrl) . '</Play>' . "\n" : '';
        $inner .= menu([
            'להאזנה להקלטה שלך הקש 1.',
            'להקלטה מחדש הקש 2.',
            'לאישור ומעבר לתשלום הקש 3.',
        ], 'menu2_review_handle', ['cat' => $cat, 'pub' => $pub, 'reg' => $reg, 'days' => $days, 'ru' => $recUrl, 'rs' => $recSid]);
        twiml($inner);

    case 'menu2_review_handle':
        $d    = $_GET['Digits'] ?? '';
        $base = ['cat' => $_GET['cat'] ?? 1, 'pub' => $_GET['pub'] ?? $phone,
                 'reg' => $_GET['reg'] ?? 1,  'days' => $_GET['days'] ?? 7];
        $ru   = $_GET['ru'] ?? '';
        $rs   = $_GET['rs'] ?? '';
        if ($d === '1') {
            twiml('<Play>' . e($ru) . '</Play>' . "\n"
                . go(stepUrl('menu2_review', array_merge($base, ['RecordingUrl' => $ru, 'RecordingSid' => $rs]))));
        }
        if ($d === '2') twiml(go(stepUrl('menu2_record', $base)));
        if ($d === '3') twiml(go(stepUrl('menu2_payment', array_merge($base, ['ru' => $ru]))));
        twiml(go(stepUrl('menu2_review', array_merge($base, ['RecordingUrl' => $ru, 'RecordingSid' => $rs]))));

    case 'menu2_payment':
        $pub    = $_GET['pub'] ?? $phone;
        $days   = intval($_GET['days'] ?? 7);
        $amount = round((PRICE_WEEK / 7) * $days);
        $params = ['MosadId' => NEDARIM_MOSAD_ID, 'ApiPassword' => NEDARIM_API_PASS,
                   'Action' => 'ChargeByPhone', 'Phone' => $pub,
                   'Amount' => $amount, 'Designation' => 'פרסום קורס ' . $days . ' ימים', 'Currency' => '1'];
        $xml    = @simplexml_load_string(@file_get_contents(NEDARIM_API_URL . '?' . http_build_query($params)));
        if ($xml && (string)$xml->Status === '000') {
            twiml(say('תשלומך התקבל בהצלחה.') . "\n"
                . go(stepUrl('menu2_success', ['cat' => $_GET['cat'] ?? 1, 'pub' => $pub,
                   'reg' => $_GET['reg'] ?? 1, 'days' => $days, 'ru' => $_GET['ru'] ?? ''])));
        }
        $lx   = @simplexml_load_string(@file_get_contents(NEDARIM_API_URL . '?' . http_build_query(array_merge($params, ['Action' => 'GetPaymentLink']))));
        $link = $lx ? (string)$lx->URL : '';
        if ($link) sendSMS($pub, "לתשלום פרסום הקורס ({$amount} ₪) לחץ: {$link}");
        twiml(sayLines([
            'לא נמצא כרטיס אשראי מעודכן עבור מספר זה.',
            'שלחנו לך קישור לתשלום מאובטח ב-SMS.',
            'לאחר השלמת התשלום, אנא התקשר שוב לפרסום.',
        ]) . "\n" . go(stepUrl('main')));

    case 'menu2_success':
        $pub    = $_GET['pub'] ?? $phone;
        $cat    = intval($_GET['cat'] ?? 1);
        $days   = intval($_GET['days'] ?? 7);
        $ru     = $_GET['ru'] ?? '';
        $adId   = time() . '_' . rand(1000, 9999);
        $allAds = getAllAds();
        $allAds[] = ['id' => $adId, 'phone' => $pub, 'category' => $cat,
                     'region' => intval($_GET['reg'] ?? 1), 'recording_url' => $ru,
                     'days' => $days, 'created' => time(), 'expires' => time() + ($days * 86400)];
        setData('ads_list', $allAds);
        $expDate = date('d/m/Y', time() + ($days * 86400));
        sendSMS($pub, "פרסומך התקבל ופורסם בהצלחה! אסמכתא: {$adId}. תוקף עד: {$expDate}.");
        notifySubscribers($cat);
        twiml(sayLines([
            'תודה! הפרסומת שלך פורסמה בהצלחה ותישמע במשך ' . $days . ' ימים.',
            'אישור ואסמכתא נשלחו אליך ב-SMS.',
        ]) . "\n" . go(stepUrl('main')));

    // ===== אזור אישי =====

    case 'menu3_start':
        twiml(
            gather(stepUrl('menu3_check'), say('ברוכים הבאים לאזור האישי. הקש את מספר הטלפון שלך ולחץ סולמית.'), 11, 20)
            . "\n" . go(stepUrl('main'))
        );

    case 'menu3_check':
        $up = $_GET['Digits'] ?? $phone;
        if (!isUserRegistered($up)) {
            twiml(menu([
                'מספר זה אינו רשום במערכת.',
                'להרשמה חינמית הקש 1.',
                'לחזרה לתפריט הראשי הקש 9.',
            ], 'menu3_check_handle', ['up' => $up]));
        }
        twiml(go(stepUrl('menu3_home', ['up' => $up])));

    case 'menu3_check_handle':
        $up = $_GET['up'] ?? $phone;
        $d  = $_GET['Digits'] ?? '';
        if ($d === '1') twiml(go(stepUrl('menu3_register', ['up' => $up])));
        twiml(go(stepUrl('main')));

    case 'menu3_register':
        $up = $_GET['up'] ?? $phone;
        registerUser($up);
        twiml(say('נרשמת בהצלחה! ברוך הבא למערכת.') . "\n" . go(stepUrl('menu3_home', ['up' => $up])));

    case 'menu3_home':
        $up = $_GET['up'] ?? $phone;
        twiml(menu([
            'ברוך הבא לאזור האישי שלך.',
            'להרשמה לקבלת התראות על פרסומים חדשים הקש 1.',
            'לביטול התראות קיימות הקש 2.',
            'לחזרה לתפריט הראשי הקש 9.',
        ], 'menu3_home_handle', ['up' => $up]));

    case 'menu3_home_handle':
        $up = $_GET['up'] ?? $phone;
        $d  = $_GET['Digits'] ?? '';
        if ($d === '1') twiml(go(stepUrl('menu3_alerts', ['up' => $up])));
        if ($d === '2') twiml(go(stepUrl('menu3_cancel', ['up' => $up])));
        twiml(go(stepUrl('main')));

    case 'menu3_alerts':
        $up = $_GET['up'] ?? $phone;
        twiml(menu([
            'בחר את הקטגוריה שעליה תרצה לקבל התראות.',
            'קורסים ושיעורי תורה - הקש 1.',
            'שיעורים פרטיים - הקש 2.',
            'סדנאות ופיתוח אישי - הקש 3.',
            'קייטנות וחוגים לילדים - הקש 4.',
            'קורסים מקצועיים - הקש 5.',
            'כל הקטגוריות - הקש 6.',
        ], 'menu3_alerts_handle', ['up' => $up]));

    case 'menu3_alerts_handle':
        $up  = $_GET['up'] ?? $phone;
        $cat = intval($_GET['Digits'] ?? 0);
        if ($cat < 1 || $cat > 6) twiml(go(stepUrl('menu3_alerts', ['up' => $up])));
        $user = getUser($up);
        if ($user) {
            if ($cat === 6) { foreach (array_keys(CATEGORIES) as $c) $user['alerts'][$c] = 1; }
            else            { $user['alerts'][$cat] = 1; }
            saveUser($up, $user);
        }
        $catName = $cat === 6 ? 'כל הקטגוריות' : (CATEGORIES[$cat] ?? '');
        twiml(say('נרשמת בהצלחה לקבלת התראות על פרסומים חדשים ב' . $catName . '. תודה!')
            . "\n" . go(stepUrl('main')));

    case 'menu3_cancel':
        $up   = $_GET['up'] ?? $phone;
        $user = getUser($up);
        if ($user) { $user['alerts'] = []; saveUser($up, $user); }
        twiml(say('כל ההתראות שלך בוטלו. תמיד תוכל להירשם מחדש.') . "\n" . go(stepUrl('main')));

    // ===== מידע ותעריפים =====

    case 'menu4_info':
        twiml(menu([
            'מידע על תעריפי המערכת.',
            'פרסום שבועי - 25 שקלים.',
            'פרסומת בפתיח הקו למשך 24 שעות - 25 שקלים.',
            'הרשמה לאזור האישי - חינם.',
            'לרכישת פרסומת פתיח הקש 1.',
            'לחזרה לתפריט הראשי הקש 9.',
        ], 'menu4_info_handle'));

    case 'menu4_info_handle':
        $d = $_GET['Digits'] ?? '';
        twiml(go(stepUrl($d === '1' ? 'menu4_opening_ad' : 'main')));

    case 'menu4_opening_ad':
        twiml(
            gather(stepUrl('menu4_record_opening'), sayLines([
                'פרסומת הפתיח תישמע לכל מתקשר בתחילת השיחה.',
                'עלות: 25 שקלים ל-24 שעות.',
                'הקש את מספר הטלפון שלך ולחץ סולמית.',
            ]), 11, 20)
            . "\n" . go(stepUrl('main'))
        );

    case 'menu4_record_opening':
        $op     = $_GET['Digits'] ?? $phone;
        $action = stepUrl('menu4_pay_opening', ['op' => $op]);
        twiml(
            say('לאחר הצפצוף הקלט את פרסומת הפתיח שלך. משך מקסימלי 10 שניות. לסיום לחץ סולמית.')
            . "\n" . '<Record action="' . e($action) . '" method="GET" maxLength="10" finishOnKey="#" playBeep="true"/>'
        );

    case 'menu4_pay_opening':
        $op     = $_GET['op'] ?? $phone;
        $recUrl = $_GET['RecordingUrl'] ?? '';
        $params = ['MosadId' => NEDARIM_MOSAD_ID, 'ApiPassword' => NEDARIM_API_PASS,
                   'Action' => 'ChargeByPhone', 'Phone' => $op,
                   'Amount' => PRICE_OPENING_AD, 'Designation' => 'פרסומת פתיח 24 שעות', 'Currency' => '1'];
        $xml    = @simplexml_load_string(@file_get_contents(NEDARIM_API_URL . '?' . http_build_query($params)));
        if ($xml && (string)$xml->Status === '000') {
            setData('opening_ad', ['phone' => $op, 'recording_url' => $recUrl,
                                   'created' => time(), 'expires' => time() + 86400]);
            sendSMS($op, "פרסומת הפתיח שלך פעילה ומשודרת! תוקף: 24 שעות.");
            twiml(say('תשלומך התקבל. פרסומת הפתיח שלך תשודר מיידית למשך 24 שעות. תודה!')
                . "\n" . go(stepUrl('main')));
        }
        $lx   = @simplexml_load_string(@file_get_contents(NEDARIM_API_URL . '?' . http_build_query(array_merge($params, ['Action' => 'GetPaymentLink']))));
        $link = $lx ? (string)$lx->URL : '';
        if ($link) sendSMS($op, "לתשלום פרסומת הפתיח (" . PRICE_OPENING_AD . " ₪) לחץ: {$link}");
        twiml(sayLines([
            'לא נמצא כרטיס אשראי מעודכן.',
            'שלחנו לך קישור לתשלום ב-SMS.',
            'לאחר התשלום, אנא התקשר שוב.',
        ]) . "\n" . go(stepUrl('main')));

    // ===== הודעה למנהל =====

    case 'menu5_voicemail':
        $action = stepUrl('menu5_notify', ['caller' => $phone]);
        twiml(
            say('השאר את הודעתך למנהל המערכת לאחר הצפצוף. לסיום לחץ סולמית.')
            . "\n" . '<Record action="' . e($action) . '" method="GET" maxLength="120" finishOnKey="#" playBeep="true"/>'
        );

    case 'menu5_notify':
        $caller = $_GET['caller'] ?? '';
        $recUrl = $_GET['RecordingUrl'] ?? '';
        $info   = $caller ? " ממתקשר: {$caller}" : '';
        sendSMS(ADMIN_PHONE, "הודעה קולית חדשה{$info}. האזן: {$recUrl}");
        twiml(say('תודה! הודעתך הועברה למנהל המערכת.') . "\n" . go(stepUrl('main')));

    // ===== פאנל ניהול =====

    case 'admin_login':
        twiml(
            gather(stepUrl('admin_verify'), say('כניסה לממשק הניהול. הקש את קוד הסיסמה ולחץ סולמית.'), 4, 15)
            . "\n" . go(stepUrl('main'))
        );

    case 'admin_verify':
        $code = $_GET['Digits'] ?? '';
        if ($code !== ADMIN_PIN) {
            twiml(say('קוד שגוי. חוזרים לתפריט הראשי.') . "\n" . go(stepUrl('main')));
        }
        twiml(go(stepUrl('admin_menu')));

    case 'admin_menu':
        $total = count(array_filter(getAllAds(), fn($a) => ($a['expires'] ?? 0) > time()));
        twiml(menu([
            'ממשק ניהול מערכת.',
            'יש כרגע ' . $total . ' מודעות פעילות במערכת.',
            'לסקירה ועריכת מודעות הקש 1.',
            'לניהול מודעת הפתיח הקש 2.',
            'לחזרה לתפריט הראשי הקש 9.',
        ], 'admin_menu_handle'));

    case 'admin_menu_handle':
        $d = $_GET['Digits'] ?? '';
        if ($d === '1') twiml(go(stepUrl('admin_ads', ['idx' => 0])));
        if ($d === '2') twiml(go(stepUrl('admin_opening')));
        twiml(go(stepUrl('main')));

    case 'admin_ads':
        $idx       = intval($_GET['idx'] ?? 0);
        $now       = time();
        $activeAds = array_values(array_filter(getAllAds(), fn($a) => ($a['expires'] ?? 0) > $now));
        if (empty($activeAds)) {
            twiml(say('אין מודעות פעילות במערכת כרגע.') . "\n" . go(stepUrl('admin_menu')));
        }
        if ($idx >= count($activeAds)) {
            twiml(say('הגעת לסוף רשימת המודעות.') . "\n" . go(stepUrl('admin_menu')));
        }
        $ad      = $activeAds[$idx];
        $catName = CATEGORIES[$ad['category']] ?? 'לא ידוע';
        $expDate = date('d/m/Y', $ad['expires']);
        $inner   = sayLines([
            'מודעה ' . ($idx + 1) . ' מתוך ' . count($activeAds) . '.',
            'קטגוריה: ' . $catName . '.',
            'מפרסם: ' . $ad['phone'] . '.',
            'תוקף עד: ' . $expDate . '.',
        ]) . "\n";
        if (!empty($ad['recording_url'])) {
            $inner .= '<Play>' . e($ad['recording_url']) . '</Play>' . "\n";
        }
        $inner .= menu([
            'למודעה הבאה הקש 1.',
            'למחיקת מודעה זו הקש 5.',
            'לחזרה לתפריט הניהול הקש 9.',
        ], 'admin_ads_handle', ['idx' => $idx, 'ad_id' => $ad['id']]);
        twiml($inner);

    case 'admin_ads_handle':
        $d    = $_GET['Digits'] ?? '';
        $idx  = intval($_GET['idx'] ?? 0);
        $adId = $_GET['ad_id'] ?? '';
        if ($d === '1') twiml(go(stepUrl('admin_ads', ['idx' => $idx + 1])));
        if ($d === '5') twiml(go(stepUrl('admin_delete', ['ad_id' => $adId, 'idx' => $idx])));
        twiml(go(stepUrl('admin_menu')));

    case 'admin_delete':
        twiml(menu([
            'האם אתה בטוח שברצונך למחוק מודעה זו?',
            'לאישור מחיקה הקש 1.',
            'לביטול וחזרה הקש 9.',
        ], 'admin_delete_handle', ['ad_id' => $_GET['ad_id'] ?? '', 'idx' => $_GET['idx'] ?? 0]));

    case 'admin_delete_handle':
        $d    = $_GET['Digits'] ?? '';
        $adId = $_GET['ad_id'] ?? '';
        $idx  = intval($_GET['idx'] ?? 0);
        if ($d === '1') {
            deleteAd($adId);
            twiml(say('המודעה נמחקה.') . "\n" . go(stepUrl('admin_ads', ['idx' => max(0, $idx - 1)])));
        }
        twiml(go(stepUrl('admin_ads', ['idx' => $idx])));

    case 'admin_opening':
        $openAd = getActiveOpeningAd();
        if (!$openAd) {
            twiml(say('אין מודעת פתיח פעילה כרגע במערכת.') . "\n" . go(stepUrl('admin_menu')));
        }
        $inner  = sayLines([
            'מודעת הפתיח הפעילה תפוג ב-' . date('d/m H:i', $openAd['expires']) . '.',
            'מפרסם: ' . $openAd['phone'] . '.',
        ]) . "\n";
        if (!empty($openAd['recording_url'])) {
            $inner .= '<Play>' . e($openAd['recording_url']) . '</Play>' . "\n";
        }
        $inner .= menu([
            'למחיקת מודעת הפתיח הקש 5.',
            'לחזרה לתפריט הניהול הקש 9.',
        ], 'admin_opening_handle');
        twiml($inner);

    case 'admin_opening_handle':
        $d = $_GET['Digits'] ?? '';
        if ($d === '5') {
            setData('opening_ad', null);
            twiml(say('מודעת הפתיח נמחקה בהצלחה.') . "\n" . go(stepUrl('admin_menu')));
        }
        twiml(go(stepUrl('admin_menu')));

    default:
        twiml(go(stepUrl('main')));
}
