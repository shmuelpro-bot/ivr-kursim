<?php
/**
 * מערכת IVR - קו הקורסים והסדנאות
 * ימות המשיח - פורמט INI נכון
 */

define('API_KEY', '0772519703_78098632');
define('BASE_URL', 'https://www.call2all.co.il/ym/api/');
define('NEDARIM_MOSAD_ID', '7007382');
define('NEDARIM_API_PASS', 'nb252');
define('NEDARIM_API_URL', 'https://matara.pro/nedarimplus/online/api.aspx');
define('PRICE_WEEK', 25);
define('PRICE_OPENING_AD', 25);
define('SELF_URL', 'https://ivr-kursim.onrender.com/ivr_main.php');
define('ADMIN_PHONE', '0500000000'); // מספר טלפון מנהל - יש לעדכן
define('ADMIN_PIN', '1234');         // קוד כניסה לפאנל ניהול - יש לעדכן

define('CATEGORIES', [
    1 => 'קורסים ושיעורי תורה',
    2 => 'שיעורים פרטיים ולימוד לבר מצווה',
    3 => 'סדנאות ופיתוח אישי',
    4 => 'קייטנות וחוגים לילדים',
    5 => 'קורסים מקצועיים',
]);

function callAPI($endpoint, $params = []) {
    $params['token'] = API_KEY;
    $url = BASE_URL . $endpoint . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    return json_decode($response, true);
}

function sendSMS($phone, $message) {
    callAPI('SendSms', ['phones' => $phone, 'message' => $message]);
}

function getActiveAds($category = null) {
    $adsRaw = callAPI('GetVar', ['var' => 'ads_list']);
    $ads = isset($adsRaw['value']) ? json_decode($adsRaw['value'], true) : [];
    if (!is_array($ads)) $ads = [];
    $now = time();
    $active = [];
    foreach ($ads as $ad) {
        if ($ad['expires'] > $now) {
            if ($category === null || $ad['category'] == $category) {
                $active[] = $ad;
            }
        }
    }
    usort($active, function($a, $b) { return $b['created'] - $a['created']; });
    return $active;
}

function getActiveOpeningAd() {
    $adRaw = callAPI('GetVar', ['var' => 'opening_ad']);
    $ad = isset($adRaw['value']) ? json_decode($adRaw['value'], true) : null;
    if ($ad && $ad['expires'] > time()) return $ad;
    return null;
}

function isUserRegistered($phone) {
    $result = callAPI('GetVar', ['var' => 'user_' . $phone]);
    return isset($result['value']) && $result['value'] !== '';
}

function registerUser($phone) {
    $user = ['phone' => $phone, 'alerts' => [], 'registered' => time()];
    callAPI('SetVar', ['var' => 'user_' . $phone, 'value' => json_encode($user)]);
    addToUsersList($phone);
}

function getUser($phone) {
    $result = callAPI('GetVar', ['var' => 'user_' . $phone]);
    return isset($result['value']) ? json_decode($result['value'], true) : null;
}

function getTotalUsers() {
    $result = callAPI('GetVar', ['var' => 'total_users']);
    return isset($result['value']) ? intval($result['value']) : 0;
}

function incrementTotalUsers() {
    $current = getTotalUsers();
    callAPI('SetVar', ['var' => 'total_users', 'value' => $current + 1]);
}

function getUsersList() {
    $result = callAPI('GetVar', ['var' => 'users_list']);
    $list = isset($result['value']) ? json_decode($result['value'], true) : [];
    return is_array($list) ? $list : [];
}

function addToUsersList($phone) {
    $list = getUsersList();
    if (!in_array($phone, $list)) {
        $list[] = $phone;
        callAPI('SetVar', ['var' => 'users_list', 'value' => json_encode($list)]);
    }
}

function notifySubscribers($category) {
    $phones = getUsersList();
    foreach ($phones as $p) {
        $user = getUser($p);
        if (!$user) continue;
        $alerts = $user['alerts'] ?? [];
        if (isset($alerts[$category]) || isset($alerts[6])) {
            $catName = CATEGORIES[$category] ?? '';
            sendSMS($p, "פרסום חדש זמין בקטגוריית {$catName}. להאזנה התקשר לקו הקורסים.");
        }
    }
}

function getAllAds() {
    $raw = callAPI('GetVar', ['var' => 'ads_list']);
    $ads = isset($raw['value']) ? json_decode($raw['value'], true) : [];
    return is_array($ads) ? $ads : [];
}

function deleteAd($adId) {
    $ads = getAllAds();
    $ads = array_values(array_filter($ads, fn($a) => $a['id'] !== $adId));
    callAPI('SetVar', ['var' => 'ads_list', 'value' => json_encode($ads)]);
}

function stepUrl($step, $extra = []) {
    $params = array_merge($extra, ['step' => $step]);
    return SELF_URL . '?' . http_build_query($params);
}

function respond($ini) {
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

$step  = $_GET['step'] ?? 'main';
$phone = $_GET['PhoneNumber'] ?? '';

switch ($step) {

    case 'main':
        incrementTotalUsers();
        $total = getTotalUsers();
        $openAd = getActiveOpeningAd();
        $messages = ['שלום! ברוכים הבאים לקו הקורסים, השיעורים והסדנאות.'];
        if ($openAd) $messages[] = 't:' . $openAd['recording'];
        $messages[] = 'עד כה התקשרו אלינו ' . $total . ' מתקשרים.';
        $messages[] = 'לשמיעת הפרסומים הקש 1.';
        $messages[] = 'לפרסום קורס, שיעור, סדנה או חוג הקש 2.';
        $messages[] = 'לאזור האישי והרשמה להתראות הקש 3.';
        $messages[] = 'למידע ותעריפים הקש 4.';
        $messages[] = 'להשארת הודעה למנהל הקש 5.';
        respond([
            'type' => 'menu',
            'id_list_message' => $messages,
            'id_list_1' => stepUrl('menu1'),
            'id_list_2' => stepUrl('menu2_start'),
            'id_list_3' => stepUrl('menu3_start'),
            'id_list_4' => stepUrl('menu4_info'),
            'id_list_5' => stepUrl('menu5_voicemail'),
            'id_list_0' => stepUrl('admin_login'),
        ]);
        break;

    case 'menu1':
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'בחר את הקטגוריה שברצונך לשמוע.',
                'קורסים ושיעורי תורה - הקש 1.',
                'שיעורים פרטיים ולימוד לבר מצווה - הקש 2.',
                'סדנאות ופיתוח אישי - הקש 3.',
                'קייטנות וחוגים לילדים - הקש 4.',
                'קורסים מקצועיים - הקש 5.',
                'לשמיעת כל הפרסומים - הקש 6.',
                'לחזרה לתפריט הראשי - הקש 9.',
            ],
            'id_list_1' => stepUrl('listen', ['cat' => 1]),
            'id_list_2' => stepUrl('listen', ['cat' => 2]),
            'id_list_3' => stepUrl('listen', ['cat' => 3]),
            'id_list_4' => stepUrl('listen', ['cat' => 4]),
            'id_list_5' => stepUrl('listen', ['cat' => 5]),
            'id_list_6' => stepUrl('listen', ['cat' => 0]),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    case 'listen':
        $cat = intval($_GET['cat'] ?? 0);
        $idx = intval($_GET['idx'] ?? 0);
        $ads = $cat > 0 ? getActiveAds($cat) : getActiveAds();
        $catName = $cat > 0 ? (CATEGORIES[$cat] ?? '') : 'כל הקטגוריות';
        if (empty($ads)) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['אין כרגע פרסומים בקטגוריית ' . $catName . '. חוזרים לתפריט הקטגוריות.'],
                'goto' => stepUrl('menu1'),
            ]);
        }
        if ($idx >= count($ads)) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['הגעת לסוף הפרסומים בקטגוריה זו. חוזרים לתפריט.'],
                'goto' => stepUrl('menu1'),
            ]);
        }
        $ad = $ads[$idx];
        $num = $idx + 1;
        $total = count($ads);
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'פרסום ' . $num . ' מתוך ' . $total . '.',
                't:' . $ad['recording'],
                'ליצירת קשר עם המפרסם חייג: ' . $ad['phone'] . '.',
                'לחזרה על ההקלטה הקש כוכבית.',
                'לפרסום הבא הקש 1.',
                'להתקשרות ישירה למפרסם הקש 2.',
                'לחזרה לתפריט הקש 9.',
            ],
            'id_list_1' => stepUrl('listen', ['cat' => $cat, 'idx' => $idx + 1]),
            'id_list_2' => stepUrl('transfer', ['to' => $ad['phone'], 'cat' => $cat, 'idx' => $idx]),
            'id_list_*' => stepUrl('listen', ['cat' => $cat, 'idx' => $idx]),
            'id_list_9' => stepUrl('menu1'),
        ]);
        break;

    case 'menu2_start':
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'ברוכים הבאים למערכת הפרסום.',
                'עלות פרסום שבועי היא 25 שקלים.',
                'הקש את מספר הטלפון שלך ולאחר מכן לחץ על סולמית.',
            ],
            'read_type' => 'phone',
            'read_variable' => 'PUB_PHONE',
            'goto' => stepUrl('menu2_category'),
        ]);
        break;

    case 'menu2_category':
        $pubPhone = $_GET['PUB_PHONE'] ?? $phone;
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'בחר את קטגוריית הפרסום שלך.',
                'קורסים ושיעורי תורה - הקש 1.',
                'שיעורים פרטיים - הקש 2.',
                'סדנאות ופיתוח אישי - הקש 3.',
                'קייטנות וחוגים לילדים - הקש 4.',
                'קורסים מקצועיים - הקש 5.',
            ],
            'id_list_1' => stepUrl('menu2_region', ['cat' => 1, 'pub_phone' => $pubPhone]),
            'id_list_2' => stepUrl('menu2_region', ['cat' => 2, 'pub_phone' => $pubPhone]),
            'id_list_3' => stepUrl('menu2_region', ['cat' => 3, 'pub_phone' => $pubPhone]),
            'id_list_4' => stepUrl('menu2_region', ['cat' => 4, 'pub_phone' => $pubPhone]),
            'id_list_5' => stepUrl('menu2_region', ['cat' => 5, 'pub_phone' => $pubPhone]),
        ]);
        break;

    case 'menu2_region':
        $cat = $_GET['cat'] ?? 1;
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'בחר את האזור הגיאוגרפי של הפרסום שלך.',
                'ירושלים והסביבה - הקש 1.',
                'מרכז הארץ - הקש 2.',
                'צפון - הקש 3.',
                'דרום - הקש 4.',
            ],
            'id_list_1' => stepUrl('menu2_duration', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => 1]),
            'id_list_2' => stepUrl('menu2_duration', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => 2]),
            'id_list_3' => stepUrl('menu2_duration', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => 3]),
            'id_list_4' => stepUrl('menu2_duration', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => 4]),
        ]);
        break;

    case 'menu2_duration':
        $cat = $_GET['cat'] ?? 1;
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        $region = $_GET['region'] ?? 1;
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'לכמה ימים תרצה לפרסם?',
                'יום אחד - הקש 1.',
                'יומיים - הקש 2.',
                'שלושה ימים - הקש 3.',
                'ארבעה ימים - הקש 4.',
                'חמישה ימים - הקש 5.',
                'ששה ימים - הקש 6.',
                'שבוע שלם - הקש 7.',
            ],
            'id_list_1' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 1]),
            'id_list_2' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 2]),
            'id_list_3' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 3]),
            'id_list_4' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 4]),
            'id_list_5' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 5]),
            'id_list_6' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 6]),
            'id_list_7' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => 7]),
        ]);
        break;

    case 'menu2_record':
        $cat = $_GET['cat'] ?? 1;
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        $region = $_GET['region'] ?? 1;
        $days = $_GET['days'] ?? 7;
        $recFile = 'ad_' . time() . '_' . rand(1000, 9999);
        respond([
            'type' => 'menu',
            'id_list_message' => ['לאחר הצפצוף הקלט את הפרסומת שלך. משך מקסימלי דקה וחצי. לסיום ההקלטה לחץ סולמית.'],
            'record_type' => 'record',
            'record_file' => $recFile,
            'record_max_time' => '90',
            'goto' => stepUrl('menu2_review', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => $days, 'rec' => $recFile]),
        ]);
        break;

    case 'menu2_review':
        $cat = $_GET['cat'] ?? 1;
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        $region = $_GET['region'] ?? 1;
        $days = $_GET['days'] ?? 7;
        $rec = $_GET['rec'] ?? '';
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'להאזנה להקלטה שלך הקש 1.',
                'להקלטה מחדש הקש 2.',
                'לאישור ומעבר לתשלום הקש 3.',
            ],
            'id_list_1' => stepUrl('play_rec', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => $days, 'rec' => $rec]),
            'id_list_2' => stepUrl('menu2_record', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => $days]),
            'id_list_3' => stepUrl('menu2_payment', ['cat' => $cat, 'pub_phone' => $pubPhone, 'region' => $region, 'days' => $days, 'rec' => $rec]),
        ]);
        break;

    case 'play_rec':
        $rec = $_GET['rec'] ?? '';
        respond([
            'type' => 'menu',
            'id_list_message' => ['t:' . $rec],
            'goto' => stepUrl('menu2_review', $_GET),
        ]);
        break;

    case 'menu2_payment':
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        $days = intval($_GET['days'] ?? 7);
        $amount = round((PRICE_WEEK / 7) * $days);
        $params = [
            'MosadId' => NEDARIM_MOSAD_ID,
            'ApiPassword' => NEDARIM_API_PASS,
            'Action' => 'ChargeByPhone',
            'Phone' => $pubPhone,
            'Amount' => $amount,
            'Designation' => 'פרסום קורס ' . $days . ' ימים',
            'Currency' => '1',
        ];
        $url = NEDARIM_API_URL . '?' . http_build_query($params);
        $response = @file_get_contents($url);
        $xml = @simplexml_load_string($response);
        $success = ($xml && (string)$xml->Status === '000');
        if ($success) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['תשלומך התקבל בהצלחה.'],
                'goto' => stepUrl('menu2_success', $_GET),
            ]);
        } else {
            $linkParams = array_merge($params, ['Action' => 'GetPaymentLink']);
            $linkUrl = NEDARIM_API_URL . '?' . http_build_query($linkParams);
            $linkResponse = @file_get_contents($linkUrl);
            $linkXml = @simplexml_load_string($linkResponse);
            $link = $linkXml ? (string)$linkXml->URL : '';
            if ($link) sendSMS($pubPhone, "לתשלום פרסום הקורס ({$amount} ₪) לחץ: {$link}");
            respond([
                'type' => 'menu',
                'id_list_message' => [
                    'לא נמצא כרטיס אשראי מעודכן עבור מספר זה.',
                    'שלחנו לך קישור לתשלום מאובטח ב-SMS.',
                    'לאחר השלמת התשלום, אנא התקשר שוב לפרסום.',
                ],
                'goto' => stepUrl('main'),
            ]);
        }
        break;

    case 'menu2_success':
        $pubPhone = $_GET['pub_phone'] ?? $phone;
        $cat = intval($_GET['cat'] ?? 1);
        $region = intval($_GET['region'] ?? 1);
        $days = intval($_GET['days'] ?? 7);
        $rec = $_GET['rec'] ?? '';
        $adsRaw = callAPI('GetVar', ['var' => 'ads_list']);
        $allAds = isset($adsRaw['value']) ? json_decode($adsRaw['value'], true) : [];
        if (!is_array($allAds)) $allAds = [];
        $adId = time() . '_' . rand(1000, 9999);
        $allAds[] = [
            'id' => $adId, 'phone' => $pubPhone, 'category' => $cat,
            'region' => $region, 'recording' => $rec, 'days' => $days,
            'created' => time(), 'expires' => time() + ($days * 86400),
        ];
        callAPI('SetVar', ['var' => 'ads_list', 'value' => json_encode($allAds)]);
        $expDate = date('d/m/Y', time() + ($days * 86400));
        sendSMS($pubPhone, "פרסומך התקבל ופורסם בהצלחה! אסמכתא: {$adId}. תוקף עד: {$expDate}.");
        notifySubscribers($cat);
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'תודה! הפרסומת שלך פורסמה בהצלחה ותישמע במשך ' . $days . ' ימים.',
                'אישור ואסמכתא נשלחו אליך ב-SMS.',
            ],
            'goto' => stepUrl('main'),
        ]);
        break;

    case 'menu3_start':
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'ברוכים הבאים לאזור האישי.',
                'הקש את מספר הטלפון שלך ולחץ סולמית.',
            ],
            'read_type' => 'phone',
            'read_variable' => 'USER_PHONE',
            'goto' => stepUrl('menu3_check'),
        ]);
        break;

    case 'menu3_check':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        if (!isUserRegistered($userPhone)) {
            respond([
                'type' => 'menu',
                'id_list_message' => [
                    'מספר זה אינו רשום במערכת.',
                    'להרשמה חינמית הקש 1.',
                    'לחזרה לתפריט הראשי הקש 9.',
                ],
                'id_list_1' => stepUrl('menu3_register', ['user_phone' => $userPhone]),
                'id_list_9' => stepUrl('main'),
            ]);
        } else {
            respond(['type' => 'menu', 'goto' => stepUrl('menu3_logged_in', ['user_phone' => $userPhone])]);
        }
        break;

    case 'menu3_register':
        $userPhone = $_GET['user_phone'] ?? $phone;
        registerUser($userPhone);
        respond([
            'type' => 'menu',
            'id_list_message' => ['נרשמת בהצלחה! ברוך הבא למערכת.'],
            'goto' => stepUrl('menu3_logged_in', ['user_phone' => $userPhone]),
        ]);
        break;

    case 'menu3_logged_in':
        $userPhone = $_GET['user_phone'] ?? $phone;
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'ברוך הבא לאזור האישי שלך.',
                'להרשמה לקבלת התראות על פרסומים חדשים הקש 1.',
                'לביטול התראות קיימות הקש 2.',
                'לחזרה לתפריט הראשי הקש 9.',
            ],
            'id_list_1' => stepUrl('menu3_alerts', ['user_phone' => $userPhone]),
            'id_list_2' => stepUrl('menu3_cancel', ['user_phone' => $userPhone]),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    case 'menu3_alerts':
        $userPhone = $_GET['user_phone'] ?? $phone;
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'בחר את הקטגוריה שעליה תרצה לקבל התראות.',
                'קורסים ושיעורי תורה - הקש 1.',
                'שיעורים פרטיים - הקש 2.',
                'סדנאות ופיתוח אישי - הקש 3.',
                'קייטנות וחוגים לילדים - הקש 4.',
                'קורסים מקצועיים - הקש 5.',
                'כל הקטגוריות - הקש 6.',
            ],
            'id_list_1' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 1]),
            'id_list_2' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 2]),
            'id_list_3' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 3]),
            'id_list_4' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 4]),
            'id_list_5' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 5]),
            'id_list_6' => stepUrl('menu3_save_alert', ['user_phone' => $userPhone, 'alert_cat' => 6]),
        ]);
        break;

    case 'menu3_save_alert':
        $userPhone = $_GET['user_phone'] ?? $phone;
        $alertCat = intval($_GET['alert_cat'] ?? 6);
        $user = getUser($userPhone);
        if ($user) {
            if ($alertCat == 6) {
                foreach (array_keys(CATEGORIES) as $c) $user['alerts'][$c] = 1;
            } else {
                $user['alerts'][$alertCat] = 1;
            }
            callAPI('SetVar', ['var' => 'user_' . $userPhone, 'value' => json_encode($user)]);
        }
        $catName = $alertCat == 6 ? 'כל הקטגוריות' : (CATEGORIES[$alertCat] ?? '');
        respond([
            'type' => 'menu',
            'id_list_message' => ['נרשמת בהצלחה לקבלת התראות על פרסומים חדשים ב' . $catName . '. תודה!'],
            'goto' => stepUrl('main'),
        ]);
        break;

    case 'menu3_cancel':
        $userPhone = $_GET['user_phone'] ?? $phone;
        $user = getUser($userPhone);
        if ($user) {
            $user['alerts'] = [];
            callAPI('SetVar', ['var' => 'user_' . $userPhone, 'value' => json_encode($user)]);
        }
        respond([
            'type' => 'menu',
            'id_list_message' => ['כל ההתראות שלך בוטלו. תמיד תוכל להירשם מחדש.'],
            'goto' => stepUrl('main'),
        ]);
        break;

    case 'menu4_info':
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'מידע על תעריפי המערכת.',
                'פרסום שבועי - 25 שקלים.',
                'פרסומת בפתיח הקו למשך 24 שעות - 25 שקלים.',
                'הרשמה לאזור האישי - חינם.',
                'לרכישת פרסומת פתיח הקש 1.',
                'לחזרה לתפריט הראשי הקש 9.',
            ],
            'id_list_1' => stepUrl('menu4_opening_ad'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    case 'menu4_opening_ad':
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'פרסומת הפתיח תישמע לכל מתקשר בתחילת השיחה.',
                'עלות: 25 שקלים ל-24 שעות.',
                'הקש את מספר הטלפון שלך ולחץ סולמית.',
            ],
            'read_type' => 'phone',
            'read_variable' => 'OPEN_PHONE',
            'goto' => stepUrl('menu4_record_opening'),
        ]);
        break;

    case 'menu4_record_opening':
        $openPhone = $_GET['OPEN_PHONE'] ?? $phone;
        $recFile = 'opening_' . time();
        respond([
            'type' => 'menu',
            'id_list_message' => ['לאחר הצפצוף הקלט את פרסומת הפתיח שלך. משך מקסימלי 10 שניות. לסיום לחץ סולמית.'],
            'record_type' => 'record',
            'record_file' => $recFile,
            'record_max_time' => '10',
            'goto' => stepUrl('menu4_pay_opening', ['open_phone' => $openPhone, 'rec' => $recFile]),
        ]);
        break;

    case 'menu4_pay_opening':
        $openPhone = $_GET['open_phone'] ?? $phone;
        $rec = $_GET['rec'] ?? '';
        $params = [
            'MosadId' => NEDARIM_MOSAD_ID,
            'ApiPassword' => NEDARIM_API_PASS,
            'Action' => 'ChargeByPhone',
            'Phone' => $openPhone,
            'Amount' => PRICE_OPENING_AD,
            'Designation' => 'פרסומת פתיח 24 שעות',
            'Currency' => '1',
        ];
        $url = NEDARIM_API_URL . '?' . http_build_query($params);
        $response = @file_get_contents($url);
        $xml = @simplexml_load_string($response);
        $success = ($xml && (string)$xml->Status === '000');
        if ($success) {
            $ad = ['phone' => $openPhone, 'recording' => $rec, 'created' => time(), 'expires' => time() + 86400];
            callAPI('SetVar', ['var' => 'opening_ad', 'value' => json_encode($ad)]);
            sendSMS($openPhone, "פרסומת הפתיח שלך פעילה ומשודרת! תוקף: 24 שעות.");
            respond([
                'type' => 'menu',
                'id_list_message' => ['תשלומך התקבל. פרסומת הפתיח שלך תשודר מיידית למשך 24 שעות. תודה!'],
                'goto' => stepUrl('main'),
            ]);
        } else {
            $linkParams = array_merge($params, ['Action' => 'GetPaymentLink']);
            $linkUrl = NEDARIM_API_URL . '?' . http_build_query($linkParams);
            $linkResponse = @file_get_contents($linkUrl);
            $linkXml = @simplexml_load_string($linkResponse);
            $link = $linkXml ? (string)$linkXml->URL : '';
            if ($link) sendSMS($openPhone, "לתשלום פרסומת הפתיח (" . PRICE_OPENING_AD . " ₪) לחץ: {$link}");
            respond([
                'type' => 'menu',
                'id_list_message' => [
                    'לא נמצא כרטיס אשראי מעודכן.',
                    'שלחנו לך קישור לתשלום ב-SMS.',
                    'לאחר התשלום, אנא התקשר שוב.',
                ],
                'goto' => stepUrl('main'),
            ]);
        }
        break;

    case 'menu5_voicemail':
        $recFile = 'admin_msg_' . time() . '_' . rand(1000, 9999);
        respond([
            'type' => 'menu',
            'id_list_message' => ['השאר את הודעתך למנהל המערכת לאחר הצפצוף. לסיום לחץ סולמית.'],
            'record_type' => 'record',
            'record_file' => $recFile,
            'record_max_time' => '120',
            'goto' => stepUrl('menu5_notify', ['rec' => $recFile, 'caller' => $phone]),
        ]);
        break;

    case 'menu5_notify':
        $rec = $_GET['rec'] ?? '';
        $caller = $_GET['caller'] ?? '';
        $callerInfo = $caller ? " ממתקשר: {$caller}" : '';
        sendSMS(ADMIN_PHONE, "הודעה קולית חדשה{$callerInfo}. קובץ הקלטה: {$rec}");
        respond([
            'type' => 'menu',
            'id_list_message' => ['תודה! הודעתך הועברה למנהל המערכת.'],
            'goto' => stepUrl('main'),
        ]);
        break;

    case 'transfer':
        $to = $_GET['to'] ?? '';
        respond([
            'type' => 'menu',
            'id_list_message' => ['מחבר אותך כעת למפרסם. אנא המתן.'],
            'transfer' => $to,
        ]);
        break;

    // ===== פאנל ניהול =====

    case 'admin_login':
        respond([
            'type' => 'menu',
            'id_list_message' => ['כניסה לממשק הניהול. הקש את קוד הסיסמה ולחץ סולמית.'],
            'read_type' => 'phone',
            'read_variable' => 'ADMIN_CODE',
            'goto' => stepUrl('admin_verify'),
        ]);
        break;

    case 'admin_verify':
        $code = $_GET['ADMIN_CODE'] ?? '';
        if ($code !== ADMIN_PIN) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['קוד שגוי. חוזרים לתפריט הראשי.'],
                'goto' => stepUrl('main'),
            ]);
        }
        respond(['type' => 'menu', 'goto' => stepUrl('admin_menu')]);
        break;

    case 'admin_menu':
        $allAds = getAllAds();
        $now = time();
        $activeAds = array_values(array_filter($allAds, fn($a) => $a['expires'] > $now));
        $total = count($activeAds);
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'ממשק ניהול מערכת.',
                'יש כרגע ' . $total . ' מודעות פעילות במערכת.',
                'לסקירה ועריכת מודעות הקש 1.',
                'לניהול מודעת הפתיח הקש 2.',
                'לחזרה לתפריט הראשי הקש 9.',
            ],
            'id_list_1' => stepUrl('admin_ads', ['idx' => 0]),
            'id_list_2' => stepUrl('admin_opening'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    case 'admin_ads':
        $idx = intval($_GET['idx'] ?? 0);
        $allAds = getAllAds();
        $now = time();
        $activeAds = array_values(array_filter($allAds, fn($a) => $a['expires'] > $now));
        if (empty($activeAds)) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['אין מודעות פעילות במערכת כרגע.'],
                'goto' => stepUrl('admin_menu'),
            ]);
        }
        if ($idx >= count($activeAds)) {
            respond([
                'type' => 'menu',
                'id_list_message' => ['הגעת לסוף רשימת המודעות.'],
                'goto' => stepUrl('admin_menu'),
            ]);
        }
        $ad = $activeAds[$idx];
        $num = $idx + 1;
        $total = count($activeAds);
        $catName = CATEGORIES[$ad['category']] ?? 'לא ידוע';
        $expDate = date('d/m/Y', $ad['expires']);
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'מודעה ' . $num . ' מתוך ' . $total . '.',
                'קטגוריה: ' . $catName . '.',
                'מפרסם: ' . $ad['phone'] . '.',
                'תוקף עד: ' . $expDate . '.',
                't:' . $ad['recording'],
                'למודעה הבאה הקש 1.',
                'למחיקת מודעה זו הקש 5.',
                'לחזרה לתפריט הניהול הקש 9.',
            ],
            'id_list_1' => stepUrl('admin_ads', ['idx' => $idx + 1]),
            'id_list_5' => stepUrl('admin_delete', ['ad_id' => $ad['id'], 'idx' => $idx]),
            'id_list_9' => stepUrl('admin_menu'),
        ]);
        break;

    case 'admin_delete':
        $adId = $_GET['ad_id'] ?? '';
        $idx = intval($_GET['idx'] ?? 0);
        respond([
            'type' => 'menu',
            'id_list_message' => [
                'האם אתה בטוח שברצונך למחוק מודעה זו?',
                'לאישור מחיקה הקש 1.',
                'לביטול וחזרה הקש 9.',
            ],
            'id_list_1' => stepUrl('admin_delete_confirm', ['ad_id' => $adId, 'idx' => $idx]),
            'id_list_9' => stepUrl('admin_ads', ['idx' => $idx]),
        ]);
        break;

    case 'admin_delete_confirm':
        $adId = $_GET['ad_id'] ?? '';
        $idx = intval($_GET['idx'] ?? 0);
        deleteAd($adId);
        respond([
            'type' => 'menu',
            'id_list_message' => ['המודעה נמחקה.'],
            'goto' => stepUrl('admin_ads', ['idx' => max(0, $idx - 1)]),
        ]);
        break;

    case 'admin_opening':
        $openAd = getActiveOpeningAd();
        if ($openAd) {
            $expDate = date('d/m H:i', $openAd['expires']);
            respond([
                'type' => 'menu',
                'id_list_message' => [
                    'מודעת הפתיח הפעילה תפוג ב-' . $expDate . '.',
                    'מפרסם: ' . $openAd['phone'] . '.',
                    't:' . $openAd['recording'],
                    'למחיקת מודעת הפתיח הקש 5.',
                    'לחזרה לתפריט הניהול הקש 9.',
                ],
                'id_list_5' => stepUrl('admin_delete_opening'),
                'id_list_9' => stepUrl('admin_menu'),
            ]);
        } else {
            respond([
                'type' => 'menu',
                'id_list_message' => ['אין מודעת פתיח פעילה כרגע במערכת.'],
                'goto' => stepUrl('admin_menu'),
            ]);
        }
        break;

    case 'admin_delete_opening':
        callAPI('SetVar', ['var' => 'opening_ad', 'value' => '']);
        respond([
            'type' => 'menu',
            'id_list_message' => ['מודעת הפתיח נמחקה בהצלחה.'],
            'goto' => stepUrl('admin_menu'),
        ]);
        break;

    default:
        respond(['type' => 'menu', 'goto' => stepUrl('main')]);
        break;
}
