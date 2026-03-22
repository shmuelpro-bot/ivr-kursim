<?php
/**
 * מערכת IVR - קו הקורסים והסדנאות
 * ימות המשיח - קוד מלא
 */

// ==============================
// הגדרות בסיסיות
// ==============================
define('78098632', '0772519703'); // הכנס את ה-API Key של ימות המשיח שלך
define('BASE_URL', 'https://www.call2all.co.il/ym/api/');
define('RECORDINGS_PATH', '/ivr/recordings/'); // נתיב להקלטות בימות המשיח

// נדרים פלוס
define('NEDARIM_MOSAD_ID',   '7007382');
define('NEDARIM_API_PASS',   'nb252');
define('NEDARIM_API_URL',    'https://matara.pro/nedarimplus/online/api.aspx');
define('PRICE_WEEK',         25);   // מחיר פרסום שבועי בש"ח
define('PRICE_OPENING_AD',   25);   // מחיר פרסומת פתיח ל-24 שעות

// קטגוריות
define('CATEGORIES', [
    1 => 'קורסים ושיעורי תורה',
    2 => 'שיעורים פרטיים ולימוד לבר מצווה',
    3 => 'סדנאות ופיתוח אישי',
    4 => 'קייטנות וחוגים לילדים',
    5 => 'קורסים מקצועיים',
]);

// אזורים
define('REGIONS', [
    1 => 'ירושלים והסביבה',
    2 => 'מרכז',
    3 => 'צפון',
    4 => 'דרום',
]);

// ==============================
// פונקציות עזר לימות המשיח
// ==============================

/**
 * שליחת פקודת Say לימות המשיח
 */
function say($text) {
    return "say:" . $text . "\n";
}

/**
 * השמעת הקלטה
 */
function playFile($filename) {
    return "play:" . RECORDINGS_PATH . $filename . "\n";
}

/**
 * קבלת קלט מהמשתמש
 */
function getInput($timeout = 5, $maxDigits = 1) {
    return "read:DIGIT,{$timeout},{$maxDigits}\n";
}

/**
 * הפניה לתפריט אחר
 */
function gotoMenu($menuName) {
    return "goto:{$menuName}\n";
}

/**
 * שמירת משתנה
 */
function setVar($name, $value) {
    return "var:{$name}={$value}\n";
}

/**
 * שליחת SMS
 */
function sendSMS($phone, $message) {
    $url = BASE_URL . "SendSms?token=" . API_KEY . "&phones=" . $phone . "&message=" . urlencode($message);
    @file_get_contents($url);
}

/**
 * תשלום דרך נדרים פלוס
 * מחזיר: ['success'=>true/false, 'transaction_id'=>..., 'error'=>...]
 */
function nedarimCharge($phone, $amount, $description) {
    $params = [
        'MosadId'     => NEDARIM_MOSAD_ID,
        'ApiPassword' => NEDARIM_API_PASS,
        'Action'      => 'ChargeByPhone',
        'Phone'       => $phone,
        'Amount'      => $amount,
        'Designation' => $description,
        'Currency'    => '1', // שקל
    ];
    $url = NEDARIM_API_URL . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    if (!$response) return ['success' => false, 'error' => 'no_response'];
    $xml = @simplexml_load_string($response);
    if (!$xml) return ['success' => false, 'error' => 'bad_response'];
    $status = (string)$xml->Status;
    if ($status === '000') {
        return ['success' => true, 'transaction_id' => (string)$xml->TransactionId];
    }
    return ['success' => false, 'error' => $status, 'message' => (string)$xml->StatusDesc];
}

/**
 * יצירת קישור תשלום נדרים פלוס (אם אין כרטיס רשום)
 */
function nedarimPaymentLink($phone, $amount, $description) {
    $params = [
        'MosadId'     => NEDARIM_MOSAD_ID,
        'ApiPassword' => NEDARIM_API_PASS,
        'Action'      => 'GetPaymentLink',
        'Phone'       => $phone,
        'Amount'      => $amount,
        'Designation' => $description,
        'Currency'    => '1',
    ];
    $url = NEDARIM_API_URL . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    if (!$response) return null;
    $xml = @simplexml_load_string($response);
    if (!$xml) return null;
    return (string)$xml->URL;
}

/**
 * קריאה ל-API של ימות המשיח
 */
function callAPI($endpoint, $params = []) {
    $params['token'] = API_KEY;
    $url = BASE_URL . $endpoint . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    return json_decode($response, true);
}

/**
 * קבלת מספר מתקשר
 */
function getCallerPhone() {
    return isset($_GET['PhoneNumber']) ? $_GET['PhoneNumber'] : '';
}

/**
 * קבלת הקלט שהוקש
 */
function getDigit() {
    return isset($_GET['DIGIT']) ? $_GET['DIGIT'] : '';
}

/**
 * ספירת סה"כ משתמשים (מאוחסן ב-DB של ימות המשיח)
 */
function getTotalUsers() {
    $result = callAPI('GetVar', ['var' => 'total_users']);
    return isset($result['value']) ? intval($result['value']) : 0;
}

function incrementTotalUsers() {
    $current = getTotalUsers();
    callAPI('SetVar', ['var' => 'total_users', 'value' => $current + 1]);
}

/**
 * קבלת פרסומות פעילות לפי קטגוריה
 */
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
    // מיון מהחדש לישן
    usort($active, function($a, $b) { return $b['created'] - $a['created']; });
    return $active;
}

/**
 * פרסומת פתיח פעילה
 */
function getActiveOpeningAd() {
    $adRaw = callAPI('GetVar', ['var' => 'opening_ad']);
    $ad = isset($adRaw['value']) ? json_decode($adRaw['value'], true) : null;
    if ($ad && $ad['expires'] > time()) return $ad;
    return null;
}

/**
 * שמירת פרסום חדש
 */
function saveNewAd($phone, $category, $region, $city, $recordingFile, $days) {
    $ads = getActiveAds(); // כל הפרסומות
    $adsRaw = callAPI('GetVar', ['var' => 'ads_list']);
    $allAds = isset($adsRaw['value']) ? json_decode($adsRaw['value'], true) : [];
    if (!is_array($allAds)) $allAds = [];
    
    $adId = time() . '_' . rand(1000, 9999);
    $newAd = [
        'id'        => $adId,
        'phone'     => $phone,
        'category'  => $category,
        'region'    => $region,
        'city'      => $city,
        'recording' => $recordingFile,
        'days'      => $days,
        'created'   => time(),
        'expires'   => time() + ($days * 86400),
    ];
    $allAds[] = $newAd;
    callAPI('SetVar', ['var' => 'ads_list', 'value' => json_encode($allAds)]);
    return $adId;
}

/**
 * בדיקה אם מספר טלפון רשום
 */
function isUserRegistered($phone) {
    $result = callAPI('GetVar', ['var' => 'user_' . $phone]);
    return isset($result['value']) && $result['value'] !== '';
}

/**
 * רישום משתמש חדש
 */
function registerUser($phone) {
    $user = ['phone' => $phone, 'alerts' => [], 'registered' => time()];
    callAPI('SetVar', ['var' => 'user_' . $phone, 'value' => json_encode($user)]);
}

/**
 * קבלת נתוני משתמש
 */
function getUser($phone) {
    $result = callAPI('GetVar', ['var' => 'user_' . $phone]);
    return isset($result['value']) ? json_decode($result['value'], true) : null;
}

/**
 * שמירת התראה למשתמש
 */
function saveUserAlert($phone, $category, $recordingFile) {
    $user = getUser($phone);
    if (!$user) return;
    $user['alerts'][$category] = $recordingFile;
    callAPI('SetVar', ['var' => 'user_' . $phone, 'value' => json_encode($user)]);
}

/**
 * שליחת התראות למשתמשים רשומים בקטגוריה
 */
function notifyUsersForCategory($category) {
    // ימות המשיח מגבילה — מומלץ להפעיל כ-cron נפרד
    // כאן רק מדגים את הלוגיקה
    $usersRaw = callAPI('GetVar', ['var' => 'all_users']);
    $allUsers = isset($usersRaw['value']) ? json_decode($usersRaw['value'], true) : [];
    foreach ($allUsers as $phone) {
        $user = getUser($phone);
        if ($user && isset($user['alerts'][$category])) {
            $catName = CATEGORIES[$category];
            callAPI('Call', [
                'phone'   => $phone,
                'message' => "פורסם קורס חדש בקטגוריית {$catName}. להאזנה הקש 1",
            ]);
        }
    }
}

// ==============================
// תפריטי IVR
// ==============================

$step  = isset($_GET['step'])  ? $_GET['step']  : 'main';
$digit = getDigit();
$phone = getCallerPhone();

// תוצאת ה-IVR שתוחזר לימות המשיח
$output = '';

switch ($step) {

    // ==========================================
    // תפריט ראשי
    // ==========================================
    case 'main':
        incrementTotalUsers();
        $totalUsers  = getTotalUsers();
        $openingAd   = getActiveOpeningAd();

        $output .= say("שלום וברכה! ברוכים הבאים לקו הקורסים והסדנאות.");

        if ($openingAd) {
            $output .= playFile($openingAd['recording']);
        }

        $output .= say("סך האנשים שהשתמשו במערכת הם " . $totalUsers . " אנשים.");
        $output .= say("לשמיעת הקורסים ושיעורים זמינים - הקש 1.");
        $output .= say("לפרסום קורס, שיעור, סדנה או קייטנה - הקש 2.");
        $output .= say("לכניסה לאזור האישי ורישום להתראות - הקש 3.");
        $output .= say("למידע על המערכת ותעריפים - הקש 4.");
        $output .= say("להשארת הודעה למנהל המערכת - הקש 5.");
        $output .= getInput(7);
        $output .= "if_digit:1:goto:menu1\n";
        $output .= "if_digit:2:goto:menu2_start\n";
        $output .= "if_digit:3:goto:menu3_start\n";
        $output .= "if_digit:4:goto:menu4_info\n";
        $output .= "if_digit:5:goto:menu5_voicemail\n";
        $output .= gotoMenu('main'); // חזרה אם לא הוקש כלום
        break;

    // ==========================================
    // תפריט 1 - האזנה לפרסומים
    // ==========================================
    case 'menu1':
        $output .= say("הקש 1 לקורסים ושיעורי תורה.");
        $output .= say("הקש 2 לשיעורים פרטיים כולל לימוד לבר מצווה.");
        $output .= say("הקש 3 לסדנאות ופיתוח אישי.");
        $output .= say("הקש 4 לקייטנות וחוגים לילדים.");
        $output .= say("הקש 5 לקורסים מקצועיים.");
        $output .= say("הקש 6 לכל הפרסומים ברצף.");
        $output .= say("הקש 9 לחזרה לתפריט הראשי.");
        $output .= getInput(7);
        for ($i = 1; $i <= 6; $i++) {
            $output .= "if_digit:{$i}:goto:listen_cat_{$i}\n";
        }
        $output .= "if_digit:9:goto:main\n";
        $output .= gotoMenu('menu1');
        break;

    case 'listen_cat_1':
    case 'listen_cat_2':
    case 'listen_cat_3':
    case 'listen_cat_4':
    case 'listen_cat_5':
        $catNum = intval(substr($step, -1));
        $ads    = getActiveAds($catNum);
        $catName = CATEGORIES[$catNum];

        if (empty($ads)) {
            $output .= say("אין כרגע פרסומים בקטגוריית {$catName}. חוזרים לתפריט.");
            $output .= gotoMenu('menu1');
        } else {
            $output .= say("מאזין לפרסומים בקטגוריית {$catName}. נמצאו " . count($ads) . " פרסומים.");
            foreach ($ads as $idx => $ad) {
                $num = $idx + 1;
                $output .= say("פרסום מספר {$num}.");
                $output .= playFile($ad['recording']);
                $output .= say("בסוף הפרסום של {$catName} ממספר " . $ad['phone'] . ".");
                $output .= say("להאזנה חוזרת הקש כוכבית. למעבר לפרסום הבא הקש 8. לחזרה לפרסום קודם הקש 2. לחזרה לתפריט הקש 9.");
                $output .= getInput(5);
                $output .= "if_digit:9:goto:menu1\n";
                // כוכבית = חזרה לאותו פרסום
                $output .= "if_digit:*:goto:{$step}\n";
            }
            $output .= say("הגעת לסוף הפרסומים בקטגוריה זו. חוזרים לתפריט.");
            $output .= gotoMenu('menu1');
        }
        break;

    case 'listen_cat_6':
        // כל הפרסומים ברצף
        $ads = getActiveAds();
        if (empty($ads)) {
            $output .= say("אין כרגע פרסומים פעילים במערכת.");
            $output .= gotoMenu('menu1');
        } else {
            $output .= say("משמיע את כל הפרסומים. נמצאו " . count($ads) . " פרסומים.");
            foreach ($ads as $idx => $ad) {
                $num = $idx + 1;
                $catName = CATEGORIES[$ad['category']] ?? 'כללי';
                $output .= say("פרסום מספר {$num} בקטגוריית {$catName}.");
                $output .= playFile($ad['recording']);
                $output .= say("מספר ליצירת קשר: " . $ad['phone'] . ".");
                $output .= say("להמשך הקש 1. לחזרה לתפריט הקש 9.");
                $output .= getInput(5);
                $output .= "if_digit:9:goto:menu1\n";
            }
            $output .= say("הגעת לסוף כל הפרסומים.");
            $output .= gotoMenu('menu1');
        }
        break;

    // ==========================================
    // תפריט 2 - פרסום קורס חדש
    // ==========================================
    case 'menu2_start':
        $output .= say("ברוכים הבאים למערכת הפרסום.");
        $output .= say("עלות פרסום: 25 שקל לשבוע אחד.");
        $output .= say("אנא הקש את מספר הטלפון שלך ולחץ על סולמית.");
        $output .= "read:PUB_PHONE,15,10\n";
        $output .= gotoMenu('menu2_category');
        break;

    case 'menu2_category':
        $output .= say("בחר קטגוריה לפרסום.");
        $output .= say("הקש 1 לקורסים ושיעורי תורה.");
        $output .= say("הקש 2 לשיעורים פרטיים כולל לימוד לבר מצווה.");
        $output .= say("הקש 3 לסדנאות ופיתוח אישי.");
        $output .= say("הקש 4 לקייטנות וחוגים לילדים.");
        $output .= say("הקש 5 לקורסים מקצועיים.");
        $output .= getInput(7);
        for ($i = 1; $i <= 5; $i++) {
            $output .= "if_digit:{$i}:setvar:PUB_CAT={$i}\n";
            $output .= "if_digit:{$i}:goto:menu2_region\n";
        }
        $output .= gotoMenu('menu2_category');
        break;

    case 'menu2_region':
        $output .= say("בחר אזור.");
        $output .= say("הקש 1 לירושלים והסביבה.");
        $output .= say("הקש 2 למרכז.");
        $output .= say("הקש 3 לצפון.");
        $output .= say("הקש 4 לדרום.");
        $output .= getInput(7);
        for ($i = 1; $i <= 4; $i++) {
            $output .= "if_digit:{$i}:setvar:PUB_REGION={$i}\n";
            $output .= "if_digit:{$i}:goto:menu2_city_first\n";
        }
        $output .= gotoMenu('menu2_region');
        break;

    case 'menu2_city_first':
        $output .= say("לעיר המתחילה באות א עד ל - הקש 1.");
        $output .= say("לעיר המתחילה באות מ עד ת - הקש 2.");
        $output .= getInput(5);
        $output .= "if_digit:1:goto:menu2_city_alef\n";
        $output .= "if_digit:2:goto:menu2_city_mem\n";
        $output .= gotoMenu('menu2_city_first');
        break;

    case 'menu2_city_alef':
        $output .= say("לעיר המתחילה באות א - הקש 1.");
        $output .= say("לעיר המתחילה באות ב - הקש 2.");
        $output .= say("לעיר המתחילה באות ג - הקש 3.");
        $output .= say("לעיר המתחילה באות ד - הקש 4.");
        $output .= say("לעיר המתחילה באות ה - הקש 5.");
        $output .= say("לעיר המתחילה באות ו - הקש 6.");
        $output .= say("לעיר המתחילה באות ז עד י - הקש 7.");
        $output .= say("לעיר המתחילה באות כ עד ל - הקש 8.");
        $output .= getInput(5);
        $output .= "if_digit:1:setvar:PUB_CITY_LETTER=א\n";
        $output .= "if_digit:2:setvar:PUB_CITY_LETTER=ב\n";
        $output .= "if_digit:3:setvar:PUB_CITY_LETTER=ג\n";
        $output .= "if_digit:4:setvar:PUB_CITY_LETTER=ד\n";
        $output .= "if_digit:5:setvar:PUB_CITY_LETTER=ה\n";
        $output .= "if_digit:6:setvar:PUB_CITY_LETTER=ו\n";
        $output .= "if_digit:7:setvar:PUB_CITY_LETTER=ז\n";
        $output .= "if_digit:8:setvar:PUB_CITY_LETTER=כ\n";
        for ($i = 1; $i <= 8; $i++) {
            $output .= "if_digit:{$i}:goto:menu2_city_input\n";
        }
        $output .= gotoMenu('menu2_city_alef');
        break;

    case 'menu2_city_mem':
        $output .= say("לעיר המתחילה באות מ - הקש 1.");
        $output .= say("לעיר המתחילה באות נ - הקש 2.");
        $output .= say("לעיר המתחילה באות ס עד ע - הקש 3.");
        $output .= say("לעיר המתחילה באות פ - הקש 4.");
        $output .= say("לעיר המתחילה באות צ - הקש 5.");
        $output .= say("לעיר המתחילה באות ק - הקש 6.");
        $output .= say("לעיר המתחילה באות ר - הקש 7.");
        $output .= say("לעיר המתחילה באות ש עד ת - הקש 8.");
        $output .= getInput(5);
        $output .= "if_digit:1:setvar:PUB_CITY_LETTER=מ\n";
        $output .= "if_digit:2:setvar:PUB_CITY_LETTER=נ\n";
        $output .= "if_digit:3:setvar:PUB_CITY_LETTER=ס\n";
        $output .= "if_digit:4:setvar:PUB_CITY_LETTER=פ\n";
        $output .= "if_digit:5:setvar:PUB_CITY_LETTER=צ\n";
        $output .= "if_digit:6:setvar:PUB_CITY_LETTER=ק\n";
        $output .= "if_digit:7:setvar:PUB_CITY_LETTER=ר\n";
        $output .= "if_digit:8:setvar:PUB_CITY_LETTER=ש\n";
        for ($i = 1; $i <= 8; $i++) {
            $output .= "if_digit:{$i}:goto:menu2_city_input\n";
        }
        $output .= gotoMenu('menu2_city_mem');
        break;

    case 'menu2_city_input':
        $output .= say("אנא אמור את שם העיר לאחר הצפצוף.");
        $output .= "record:PUB_CITY_REC," . RECORDINGS_PATH . "city_" . time() . ".wav,10\n";
        $output .= gotoMenu('menu2_duration');
        break;

    case 'menu2_duration':
        $output .= say("לכמה ימים תרצה שהפרסום שלך יישמע?");
        $output .= say("למשך יום אחד - הקש 1.");
        $output .= say("למשך יומיים - הקש 2.");
        $output .= say("למשך 3 ימים - הקש 3.");
        $output .= say("למשך 4 ימים - הקש 4.");
        $output .= say("למשך 5 ימים - הקש 5.");
        $output .= say("למשך 6 ימים - הקש 6.");
        $output .= say("למשך שבוע שלם - הקש 7.");
        $output .= getInput(7);
        for ($i = 1; $i <= 7; $i++) {
            $output .= "if_digit:{$i}:setvar:PUB_DAYS={$i}\n";
            $output .= "if_digit:{$i}:goto:menu2_record\n";
        }
        $output .= gotoMenu('menu2_duration');
        break;

    case 'menu2_record':
        $recFile = RECORDINGS_PATH . "ad_" . time() . "_" . rand(1000,9999) . ".wav";
        $output .= say("לאחר הצפצוף הקלט את הפרסומת שלך. עד דקה וחצי.");
        $output .= say("לסיום ההקלטה לחץ סולמית.");
        $output .= "record:PUB_REC,{$recFile},90\n";
        $output .= setVar('PUB_REC_FILE', $recFile);
        $output .= gotoMenu('menu2_review');
        break;

    case 'menu2_review':
        $output .= say("להאזנה להקלטה - הקש 1.");
        $output .= say("להקליט מחדש - הקש 2.");
        $output .= say("לאישור ומעבר לתשלום - הקש 3.");
        $output .= getInput(7);
        $output .= "if_digit:1:play:\$PUB_REC_FILE\n";
        $output .= "if_digit:1:goto:menu2_review\n";
        $output .= "if_digit:2:goto:menu2_record\n";
        $output .= "if_digit:3:goto:menu2_payment\n";
        $output .= gotoMenu('menu2_review');
        break;

    case 'menu2_payment':
        $output .= say("לתשלום דרך נדרים פלוס - הקש 1.");
        $output .= say("לחזרה - הקש 9.");
        $output .= getInput(7);
        $output .= "if_digit:1:goto:menu2_nedarim\n";
        $output .= "if_digit:9:goto:menu2_review\n";
        $output .= gotoMenu('menu2_payment');
        break;

    case 'menu2_nedarim':
        $pubPhone = $_GET['PUB_PHONE'] ?? $phone;
        $days     = intval($_GET['PUB_DAYS'] ?? 7);
        // מחיר יחסי לפי מספר ימים
        $amount   = round((PRICE_WEEK / 7) * $days);
        $desc     = "פרסום קורס/סדנה - {$days} ימים";

        $output .= say("סכום לתשלום: {$amount} שקלים עבור {$days} ימי פרסום.");
        $output .= say("מבצע חיוב דרך נדרים פלוס. אנא המתן.");

        $result = nedarimCharge($pubPhone, $amount, $desc);

        if ($result['success']) {
            // תשלום עבר בהצלחה
            $output .= setVar('PAYMENT_OK', '1');
            $output .= setVar('PAYMENT_TID', $result['transaction_id']);
            $output .= gotoMenu('menu2_success');
        } else {
            // תשלום נכשל — שלח קישור SMS
            $link = nedarimPaymentLink($pubPhone, $amount, $desc);
            if ($link) {
                sendSMS($pubPhone, "לתשלום פרסום הקורס שלך ({$amount} ₪) לחץ: {$link}");
                $output .= say("לא נמצא כרטיס אשראי רשום. נשלח אליך קישור תשלום ב-SMS.");
                $output .= say("לאחר התשלום, התקשר שוב כדי לאשר את הפרסום.");
            } else {
                $output .= say("אירעה שגיאה בתשלום. אנא נסה שוב מאוחר יותר.");
            }
            $output .= say("לחזרה לתפריט הראשי - הקש 9.");
            $output .= getInput(7);
            $output .= "if_digit:9:goto:main\n";
            $output .= gotoMenu('main');
        }
        break;

    case 'menu2_success':
        // שמירת הפרסום
        $adId = saveNewAd(
            $_GET['PUB_PHONE'] ?? $phone,
            $_GET['PUB_CAT']  ?? 1,
            $_GET['PUB_REGION'] ?? 1,
            $_GET['PUB_CITY_LETTER'] ?? '',
            $_GET['PUB_REC_FILE'] ?? '',
            $_GET['PUB_DAYS'] ?? 7
        );
        
        // שליחת SMS
        $pubPhone = $_GET['PUB_PHONE'] ?? $phone;
        $days     = $_GET['PUB_DAYS'] ?? 7;
        $expDate  = date('d/m/Y', time() + ($days * 86400));
        sendSMS($pubPhone, "הפרסום שלך התקבל! מספר אסמכתא: {$adId}. תוקף עד: {$expDate}.");
        
        // התראה למשתמשים רשומים
        $cat = $_GET['PUB_CAT'] ?? 1;
        notifyUsersForCategory($cat);
        
        $output .= say("תודה! הפרסומת שלך פורסמה בהצלחה למשך {$days} ימים.");
        $output .= say("מספר אסמכתא: " . implode(', ', str_split($adId)));
        $output .= say("נשלח אליך אישור ב-SMS.");
        $output .= say("לחזרה לתפריט הראשי - הקש 9.");
        $output .= getInput(7);
        $output .= "if_digit:9:goto:main\n";
        $output .= gotoMenu('main');
        break;

    // ==========================================
    // תפריט 3 - אזור אישי והתראות
    // ==========================================
    case 'menu3_start':
        $output .= say("ברוכים הבאים לאזור האישי.");
        $output .= say("אנא הקש את מספר הטלפון שלך ולחץ סולמית.");
        $output .= "read:USER_PHONE,15,10\n";
        $output .= gotoMenu('menu3_check');
        break;

    case 'menu3_check':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        if (!isUserRegistered($userPhone)) {
            $output .= say("מספר זה אינו רשום במערכת.");
            $output .= say("להרשמה חינם - הקש 1.");
            $output .= say("חזרה לתפריט הראשי - הקש 9.");
            $output .= getInput(7);
            $output .= "if_digit:1:goto:menu3_register\n";
            $output .= "if_digit:9:goto:main\n";
            $output .= gotoMenu('menu3_check');
        } else {
            $output .= gotoMenu('menu3_logged_in');
        }
        break;

    case 'menu3_register':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        registerUser($userPhone);
        
        // הוספה לרשימת כל המשתמשים
        $allUsersRaw = callAPI('GetVar', ['var' => 'all_users']);
        $allUsers = isset($allUsersRaw['value']) ? json_decode($allUsersRaw['value'], true) : [];
        if (!in_array($userPhone, $allUsers)) {
            $allUsers[] = $userPhone;
            callAPI('SetVar', ['var' => 'all_users', 'value' => json_encode($allUsers)]);
        }
        
        $output .= say("נרשמת בהצלחה! ברוך הבא.");
        $output .= gotoMenu('menu3_logged_in');
        break;

    case 'menu3_logged_in':
        $output .= say("לרישום להתראות על קורסים חדשים - הקש 1.");
        $output .= say("לצפייה בהתראות הרשומות שלי - הקש 2.");
        $output .= say("לביטול התראות - הקש 3.");
        $output .= say("לחזרה לתפריט הראשי - הקש 9.");
        $output .= getInput(7);
        $output .= "if_digit:1:goto:menu3_alerts\n";
        $output .= "if_digit:2:goto:menu3_view_alerts\n";
        $output .= "if_digit:3:goto:menu3_cancel_alerts\n";
        $output .= "if_digit:9:goto:main\n";
        $output .= gotoMenu('menu3_logged_in');
        break;

    case 'menu3_alerts':
        $output .= say("בחר קטגוריה לקבלת התראות.");
        $output .= say("הקש 1 לקורסים ושיעורי תורה.");
        $output .= say("הקש 2 לשיעורים פרטיים ולימוד לבר מצווה.");
        $output .= say("הקש 3 לסדנאות ופיתוח אישי.");
        $output .= say("הקש 4 לקייטנות וחוגים לילדים.");
        $output .= say("הקש 5 לקורסים מקצועיים.");
        $output .= say("הקש 6 לכל הקטגוריות.");
        $output .= getInput(7);
        for ($i = 1; $i <= 6; $i++) {
            $output .= "if_digit:{$i}:setvar:ALERT_CAT={$i}\n";
            $output .= "if_digit:{$i}:goto:menu3_record_alert\n";
        }
        $output .= gotoMenu('menu3_alerts');
        break;

    case 'menu3_record_alert':
        $output .= say("לאחר הצפצוף הקלט את בקשתך.");
        $recFile = RECORDINGS_PATH . "alert_" . $phone . "_" . time() . ".wav";
        $output .= "record:ALERT_REC,{$recFile},30\n";
        $output .= setVar('ALERT_REC_FILE', $recFile);
        $output .= gotoMenu('menu3_confirm_alert');
        break;

    case 'menu3_confirm_alert':
        $output .= say("לשמיעת ההקלטה - הקש 1.");
        $output .= say("לאישור ושמירה - הקש 2.");
        $output .= say("להקלטה מחדש - הקש 3.");
        $output .= getInput(7);
        $output .= "if_digit:1:play:\$ALERT_REC_FILE\n";
        $output .= "if_digit:1:goto:menu3_confirm_alert\n";
        $output .= "if_digit:2:goto:menu3_save_alert\n";
        $output .= "if_digit:3:goto:menu3_record_alert\n";
        $output .= gotoMenu('menu3_confirm_alert');
        break;

    case 'menu3_save_alert':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        $alertCat  = $_GET['ALERT_CAT'] ?? 6;
        $alertRec  = $_GET['ALERT_REC_FILE'] ?? '';
        
        if ($alertCat == 6) {
            // כל הקטגוריות
            foreach (array_keys(CATEGORIES) as $c) {
                saveUserAlert($userPhone, $c, $alertRec);
            }
        } else {
            saveUserAlert($userPhone, $alertCat, $alertRec);
        }
        
        $catName = ($alertCat == 6) ? 'כל הקטגוריות' : (CATEGORIES[$alertCat] ?? '');
        $output .= say("ההרשמה בוצעה בהצלחה! תקבל צלצול אוטומטי ברגע שיפורסם פריט חדש בקטגוריית {$catName}.");
        $output .= gotoMenu('main');
        break;

    case 'menu3_view_alerts':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        $user      = getUser($userPhone);
        if (empty($user['alerts'])) {
            $output .= say("אין לך התראות רשומות כרגע.");
        } else {
            $output .= say("ההתראות הרשומות שלך:");
            foreach ($user['alerts'] as $cat => $rec) {
                $catName = CATEGORIES[$cat] ?? 'קטגוריה ' . $cat;
                $output .= say("קטגוריה: {$catName}.");
            }
        }
        $output .= say("לחזרה - הקש 9.");
        $output .= getInput(5);
        $output .= "if_digit:9:goto:menu3_logged_in\n";
        $output .= gotoMenu('menu3_logged_in');
        break;

    case 'menu3_cancel_alerts':
        $userPhone = $_GET['USER_PHONE'] ?? $phone;
        $user      = getUser($userPhone);
        $user['alerts'] = [];
        callAPI('SetVar', ['var' => 'user_' . $userPhone, 'value' => json_encode($user)]);
        $output .= say("כל ההתראות שלך בוטלו בהצלחה.");
        $output .= gotoMenu('menu3_logged_in');
        break;

    // ==========================================
    // תפריט 4 - מידע ותעריפים + פרסומת פתיח
    // ==========================================
    case 'menu4_info':
        $output .= say("מידע על המערכת ותעריפים.");
        $output .= say("פרסום קורס או שיעור: 25 שקל לשבוע.");
        $output .= say("פרסומת בפתיח הקו: 25 שקל ל-24 שעות.");
        $output .= say("הרשמה לאזור האישי: חינם לגמרי.");
        $output .= say("לפרסום פרסומת בפתיח הקו - הקש 1.");
        $output .= say("לחזרה לתפריט הראשי - הקש 9.");
        $output .= getInput(7);
        $output .= "if_digit:1:goto:menu4_opening_ad\n";
        $output .= "if_digit:9:goto:main\n";
        $output .= gotoMenu('menu4_info');
        break;

    case 'menu4_opening_ad':
        $output .= say("פרסומת בפתיח הקו: 25 שקל ל-24 שעות.");
        $output .= say("הפרסומת תושמע לכל מתקשר מיד לאחר הפתיח.");
        $output .= say("אנא הקש את מספר הטלפון שלך ולחץ סולמית.");
        $output .= "read:OPEN_PHONE,15,10\n";
        $output .= gotoMenu('menu4_record_opening');
        break;

    case 'menu4_record_opening':
        $output .= say("לאחר הצפצוף, הקלט את הפרסומת. עד 10 שניות בלבד. לסיום - לחץ סולמית.");
        $recFile = RECORDINGS_PATH . "opening_" . time() . ".wav";
        $output .= "record:OPEN_REC,{$recFile},10\n";
        $output .= setVar('OPEN_REC_FILE', $recFile);
        $output .= gotoMenu('menu4_review_opening');
        break;

    case 'menu4_review_opening':
        $output .= say("להאזנה - הקש 1. להקלטה מחדש - הקש 2. לאישור ותשלום - הקש 3.");
        $output .= getInput(7);
        $output .= "if_digit:1:play:\$OPEN_REC_FILE\n";
        $output .= "if_digit:1:goto:menu4_review_opening\n";
        $output .= "if_digit:2:goto:menu4_record_opening\n";
        $output .= "if_digit:3:goto:menu4_pay_opening\n";
        $output .= gotoMenu('menu4_review_opening');
        break;

    case 'menu4_pay_opening':
        $openPhone = $_GET['OPEN_PHONE'] ?? $phone;
        $amount    = PRICE_OPENING_AD;
        $desc      = "פרסומת פתיח - 24 שעות";

        $output .= say("סכום לתשלום: {$amount} שקלים עבור פרסומת פתיח ל-24 שעות.");
        $output .= say("מבצע חיוב דרך נדרים פלוס. אנא המתן.");

        $result = nedarimCharge($openPhone, $amount, $desc);

        if ($result['success']) {
            $output .= setVar('OPEN_PAYMENT_OK', '1');
            $output .= gotoMenu('menu4_save_opening');
        } else {
            $link = nedarimPaymentLink($openPhone, $amount, $desc);
            if ($link) {
                sendSMS($openPhone, "לתשלום פרסומת הפתיח ({$amount} ₪) לחץ: {$link}");
                $output .= say("לא נמצא כרטיס אשראי רשום. נשלח אליך קישור תשלום ב-SMS.");
                $output .= say("לאחר התשלום, התקשר שוב להשלמת הפרסומת.");
            } else {
                $output .= say("אירעה שגיאה בתשלום. אנא נסה שוב מאוחר יותר.");
            }
            $output .= say("לחזרה לתפריט הראשי - הקש 9.");
            $output .= getInput(7);
            $output .= "if_digit:9:goto:main\n";
            $output .= gotoMenu('main');
        }
        break;

    case 'menu4_save_opening':
        $openPhone = $_GET['OPEN_PHONE'] ?? $phone;
        $openRec   = $_GET['OPEN_REC_FILE'] ?? '';
        $ad = [
            'phone'     => $openPhone,
            'recording' => $openRec,
            'created'   => time(),
            'expires'   => time() + 86400, // 24 שעות
        ];
        callAPI('SetVar', ['var' => 'opening_ad', 'value' => json_encode($ad)]);
        $output .= say("הפרסומת שלך תשודר מעכשיו למשך 24 שעות.");
        $output .= say("בסוף הפרסומת יוכרז מספר הטלפון: " . $openPhone . ".");
        sendSMS($openPhone, "פרסומת הפתיח שלך פעילה! תוקף: 24 שעות. תזכורת תישלח שעה לפני הסיום.");
        $output .= gotoMenu('main');
        break;

    // ==========================================
    // תפריט 5 - הודעה למנהל
    // ==========================================
    case 'menu5_voicemail':
        $output .= say("אנא השאר הודעה לאחר הצפצוף. לסיום לחץ סולמית.");
        $recFile = RECORDINGS_PATH . "admin_msg_" . time() . ".wav";
        $output .= "record:ADMIN_MSG,{$recFile},120\n";
        $output .= say("תודה! ההודעה שלך התקבלה. חוזרים לתפריט הראשי.");
        // שלח התראת SMS למנהל
        sendSMS('05XXXXXXXX', "הודעה חדשה מ-{$phone} נשמרה: {$recFile}"); // החלף למספר שלך
        $output .= gotoMenu('main');
        break;

    default:
        $output .= gotoMenu('main');
        break;
}

// פלט לימות המשיח
header('Content-Type: text/plain; charset=utf-8');
echo $output;

