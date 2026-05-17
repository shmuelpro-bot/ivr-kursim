<?php
/**
 * ivr_main.php – מערכת IVR: קו דירות לשבת
 * ימות המשיח – פורמט INI
 */

require_once __DIR__ . '/lib.php';

$step  = $_GET['step'] ?? 'main';
$phone = $_GET['PhoneNumber'] ?? '';

switch ($step) {

    // ─────────────────────────────────────────────────────────────
    // MAIN MENU
    // ─────────────────────────────────────────────────────────────
    case 'main':
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                'שלום וברכה! ברוכים הבאים לקו דירות לשבת.',
                'לחיפוש דירה הקש 1.',
                'לפרסום דירה הקש 2.',
                'לניהול הפרסום שלך הקש 3.',
                'לסטטיסטיקות המערכת הקש 4.',
                'לחזרה על הפקודות הקש 9.',
            ],
            'id_list_1' => stepUrl('search_notice'),
            'id_list_2' => stepUrl('list_notice'),
            'id_list_3' => stepUrl('owner_check'),
            'id_list_4' => stepUrl('stats'),
            'id_list_9' => stepUrl('main'),
        ]);
        break;

    // ─────────────────────────────────────────────────────────────
    // PAYMENT NOTICES
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
    //  LISTING FLOW
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
            'id_list_1' => stepUrl('list_city', ['rt' => 1, 'pg' => 1]),
            'id_list_2' => stepUrl('list_city', ['rt' => 2, 'pg' => 1]),
            'id_list_3' => stepUrl('list_city', ['rt' => 3, 'pg' => 1]),
        ]);
        break;

    case 'list_city': {
        $rt         = $_GET['rt'] ?? 1;
        $page       = max(1, intval($_GET['pg'] ?? 1));
        $totalPages = totalCityPages();
        $cities     = citiesForPage($page);

        $msgs = ['שאלה 2 – בחר עיר.' . ($totalPages > 1 ? ' עמוד ' . $page . ' מתוך ' . $totalPages . '.' : '')];
        $res  = ['type' => 'menu'];

        foreach ($cities as $key => $city) {
            $msgs[] = 'הקש ' . $key . ' ל' . $city['name'] . '.';
            $res['id_list_' . $key] = stepUrl('list_neighborhood', ['rt' => $rt, 'ci' => $city['id']]);
        }

        if ($page < $totalPages) {
            $msgs[]          = 'לערים נוספות הקש כוכבית.';
            $res['id_list_*'] = stepUrl('list_city', ['rt' => $rt, 'pg' => $page + 1]);
        }
        if ($page > 1) {
            $msgs[]          = 'לעמוד הקודם הקש סולמית.';
            $res['id_list_#'] = stepUrl('list_city', ['rt' => $rt, 'pg' => $page - 1]);
        }

        $res['id_list_message'] = $msgs;
        respond($res);
        break;
    }

    case 'list_neighborhood': {
        $rt  = $_GET['rt'] ?? 1;
        $ci  = intval($_GET['ci'] ?? 1);
        $nhs = NEIGHBORHOODS[$ci] ?? [];
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
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0, 'sr' => $_GET['sr'] ?? ''];
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
            'rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1,
            'nh' => $_GET['nh'] ?? 0, 'sr' => $_GET['sr'] ?? '',
            'at' => $_GET['at'] ?? 1,
        ];
        respond([
            'type'            => 'menu',
            'id_list_message' => ['שאלה 6 – הקלד מספר מיטות כולל מזרנים ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '2',
            'read_variable'   => 'BEDS',
            'goto'            => stepUrl('list_bedrooms', $p),
        ]);
        break;
    }

    case 'list_bedrooms': {
        $p = [
            'rt'   => $_GET['rt']   ?? 1, 'ci' => $_GET['ci'] ?? 1,
            'nh'   => $_GET['nh']   ?? 0, 'sr' => $_GET['sr'] ?? '',
            'at'   => $_GET['at']   ?? 1, 'BEDS' => $_GET['BEDS'] ?? 0,
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
            'rt'    => $_GET['rt']    ?? 1, 'ci'   => $_GET['ci']   ?? 1,
            'nh'    => $_GET['nh']    ?? 0, 'sr'   => $_GET['sr']   ?? '',
            'at'    => $_GET['at']    ?? 1, 'BEDS' => $_GET['BEDS'] ?? 0,
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

        $p = ['rt' => $rt, 'ci' => $ci, 'nh' => $nh, 'sr' => $_GET['sr'] ?? '',
              'at' => $at, 'BEDS' => $beds, 'ROOMS' => $rooms, 'PRICE' => $price];

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
            'id_list_message' => ['הדירה פורסמה בהצלחה! הפרסום פעיל עד צאת השבת. נשלח אישור ב SMS.'],
            'goto'            => stepUrl('main'),
        ]);
        break;
    }

    // ================================================================
    //  SEARCH FLOW
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
            'id_list_0' => stepUrl('search_city', ['fr' => 0, 'pg' => 1]),
            'id_list_1' => stepUrl('search_city', ['fr' => 1, 'pg' => 1]),
            'id_list_2' => stepUrl('search_city', ['fr' => 2, 'pg' => 1]),
            'id_list_3' => stepUrl('search_city', ['fr' => 3, 'pg' => 1]),
        ]);
        break;

    case 'search_city': {
        $fr         = $_GET['fr'] ?? 0;
        $page       = max(1, intval($_GET['pg'] ?? 1));
        $totalPages = totalCityPages();
        $cities     = citiesForPage($page);

        $msgs = ['סנן לפי עיר.' . ($totalPages > 1 ? ' עמוד ' . $page . ' מתוך ' . $totalPages . '.' : '') . ' הקש 0 לכל הערים.'];
        $res  = ['type' => 'menu'];

        $res['id_list_0'] = stepUrl('search_apt_type', ['fr' => $fr, 'fc' => 0, 'fn' => 0]);

        foreach ($cities as $key => $city) {
            $msgs[] = 'הקש ' . $key . ' ל' . $city['name'] . '.';
            $res['id_list_' . $key] = stepUrl('search_neighborhood', ['fr' => $fr, 'fc' => $city['id']]);
        }

        if ($page < $totalPages) {
            $msgs[]           = 'לערים נוספות הקש כוכבית.';
            $res['id_list_*'] = stepUrl('search_city', ['fr' => $fr, 'pg' => $page + 1]);
        }
        if ($page > 1) {
            $msgs[]           = 'לעמוד הקודם הקש סולמית.';
            $res['id_list_#'] = stepUrl('search_city', ['fr' => $fr, 'pg' => $page - 1]);
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
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0, 'fa' => $_GET['fa'] ?? 0];
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
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0, 'fa' => $_GET['fa'] ?? 0, 'FB' => $_GET['FB'] ?? 0];
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
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0,
              'fa' => $_GET['fa'] ?? 0, 'FB' => $_GET['FB'] ?? 0, 'FBR' => $_GET['FBR'] ?? 0];
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

        $results = filterApts(getApts(), [
            'rental_type'  => $fr, 'city'         => $fc,
            'neighborhood' => $fn, 'apt_type'      => $fa,
            'beds_min'     => $fb, 'bedrooms_min'  => $fbr, 'price_max' => $fp,
        ]);
        $total   = count($results);

        $fP = ['fr' => $fr, 'fc' => $fc, 'fn' => $fn, 'fa' => $fa,
               'FB' => $fb, 'FBR' => $fbr, 'FP' => $fp];

        if ($total === 0) {
            respond([
                'type'            => 'menu',
                'id_list_message' => ['לא נמצאו דירות התואמות את הסינון שלך.', 'לחיפוש חדש הקש 1.', 'לתפריט הראשי הקש 9.'],
                'id_list_1' => stepUrl('search_notice'),
                'id_list_9' => stepUrl('main'),
            ]);
        }

        if ($idx >= $total) {
            respond([
                'type'            => 'menu',
                'id_list_message' => ['הגעת לסוף הרשימה.', 'לחיפוש חדש הקש 1.', 'לתפריט הראשי הקש 9.'],
                'id_list_1' => stepUrl('search_notice'),
                'id_list_9' => stepUrl('main'),
            ]);
        }

        $apt      = $results[$idx];
        $loc      = locationStr($apt);
        $priceTxt = $apt['price'] > 0 ? $apt['price'] . ' שקל ללילה' : 'מחיר לא צוין';
        $roomsTxt = $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חדרי שינה';

        $nextP = array_merge($fP, ['idx' => $idx + 1]);
        $prevP = array_merge($fP, ['idx' => max(0, $idx - 1)]);
        $curP  = array_merge($fP, ['idx' => $idx]);

        $msgs = [
            'דירה ' . ($idx + 1) . ' מתוך ' . $total . '.',
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
        $msgs[] = 'לפרטי קשר עם בעל הדירה הקש 3.';
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
        $fP = ['fr'  => $_GET['fr']  ?? 0, 'fc'  => $_GET['fc']  ?? 0,
               'fn'  => $_GET['fn']  ?? 0, 'fa'  => $_GET['fa']  ?? 0,
               'FB'  => $_GET['FB']  ?? 0, 'FBR' => $_GET['FBR'] ?? 0,
               'FP'  => $_GET['FP']  ?? 0, 'idx' => $_GET['idx'] ?? 0];
        respond([
            'type'            => 'menu',
            'id_list_message' => [
                paymentMsg(),
                'מספר הטלפון של בעל הדירה הוא ' . $ownerPhone . '.',
                'לחזרה לרשימה הקש 1.',
                'לתפריט הראשי הקש 9.',
            ],
            'id_list_1' => stepUrl('search_results', $fP),
            'id_list_9' => stepUrl('main'),
        ]);
        break;
    }

    // ================================================================
    //  OWNER MANAGEMENT
    // ================================================================

    case 'owner_check': {
        $apts   = getApts();
        $myApts = array_values(array_filter($apts, fn($a) => $a['owner_phone'] === $phone));

        if (empty($myApts)) {
            respond([
                'type'            => 'menu',
                'id_list_message' => ['לא נמצא פרסום פעיל במספר טלפון זה.', 'לפרסום דירה חדשה הקש 1.', 'לתפריט הראשי הקש 9.'],
                'id_list_1' => stepUrl('list_notice'),
                'id_list_9' => stepUrl('main'),
            ]);
        }

        $apt      = $myApts[count($myApts) - 1];
        $count    = count($myApts);
        $priceTxt = $apt['price'] > 0 ? $apt['price'] . ' שקל ללילה' : 'מחיר לא צוין';
        $roomsTxt = $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חדרי שינה';

        $msgs = array_values(array_filter([
            'ניהול הפרסום שלך.',
            $count > 1 ? 'יש לך ' . $count . ' פרסומים פעילים. מציג את האחרון.' : '',
            'מיקום: ' . locationStr($apt) . '.',
            'סוג: ' . aptTypeName($apt['apt_type']) . '.',
            'מיטות: ' . $apt['beds'] . '.', $roomsTxt . '.', $priceTxt . '.',
            'זמן השכרה: ' . rentalName($apt['rental_type']) . '.',
            'לעדכון מחיר הקש 1.',
            'למחיקת הפרסום הקש 2.',
            'לתפריט הראשי הקש 9.',
        ]));

        respond([
            'type'            => 'menu',
            'id_list_message' => $msgs,
            'id_list_1'       => stepUrl('owner_update_price', ['aid' => $apt['id']]),
            'id_list_2'       => stepUrl('owner_delete_confirm', ['aid' => $apt['id']]),
            'id_list_9'       => stepUrl('main'),
        ]);
        break;
    }

    case 'owner_update_price': {
        $aid = $_GET['aid'] ?? '';
        respond([
            'type'            => 'menu',
            'id_list_message' => ['הקלד מחיר חדש ללילה בשקלים ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'],
            'read_type'       => 'dtmf',
            'read_max_digits' => '5',
            'read_variable'   => 'NEWPRICE',
            'goto'            => stepUrl('owner_update_price_save', ['aid' => $aid]),
        ]);
        break;
    }

    case 'owner_update_price_save': {
        $aid      = $_GET['aid']           ?? '';
        $newPrice = intval($_GET['NEWPRICE'] ?? 0);
        $apts     = getApts();
        foreach ($apts as &$a) {
            if ($a['id'] === $aid && $a['owner_phone'] === $phone) {
                $a['price'] = $newPrice;
                break;
            }
        }
        unset($a);
        saveApts($apts);
        $priceTxt = $newPrice > 0 ? $newPrice . ' שקל ללילה' : 'ללא מחיר מצוין';
        respond([
            'type'            => 'menu',
            'id_list_message' => ['המחיר עודכן בהצלחה ל' . $priceTxt . '.'],
            'goto'            => stepUrl('main'),
        ]);
        break;
    }

    case 'owner_delete_confirm': {
        $aid = $_GET['aid'] ?? '';
        respond([
            'type'            => 'menu',
            'id_list_message' => ['האם אתה בטוח שברצונך למחוק את הפרסום?', 'לאישור מחיקה הקש 1.', 'לביטול הקש 9.'],
            'id_list_1' => stepUrl('owner_delete', ['aid' => $aid]),
            'id_list_9' => stepUrl('owner_check'),
        ]);
        break;
    }

    case 'owner_delete': {
        $aid  = $_GET['aid'] ?? '';
        $apts = getApts();
        $apts = array_values(array_filter($apts, fn($a) => !($a['id'] === $aid && $a['owner_phone'] === $phone)));
        saveApts($apts);
        sendSMS($phone, "הפרסום שלך הוסר מקו דירות לשבת.");
        respond([
            'type'            => 'menu',
            'id_list_message' => ['הפרסום הוסר בהצלחה. נשלח אישור ב SMS.'],
            'goto'            => stepUrl('main'),
        ]);
        break;
    }

    // ================================================================
    //  STATISTICS
    // ================================================================

    case 'stats': {
        $apts  = getApts();
        $total = count($apts);

        if ($total === 0) {
            respond([
                'type'            => 'menu',
                'id_list_message' => ['אין כרגע דירות פעילות במערכת.', 'לתפריט הראשי הקש 9.'],
                'id_list_9' => stepUrl('main'),
            ]);
        }

        $cityCounts = $typeCounts = [];
        $prices     = [];
        foreach ($apts as $a) {
            $cn = cityName($a['city']);
            $cityCounts[$cn] = ($cityCounts[$cn] ?? 0) + 1;
            $tn = aptTypeName($a['apt_type']);
            $typeCounts[$tn] = ($typeCounts[$tn] ?? 0) + 1;
            if ($a['price'] > 0) $prices[] = $a['price'];
        }
        arsort($cityCounts);
        arsort($typeCounts);

        $msgs = ['סטטיסטיקות המערכת.', 'סך הכל ' . $total . ' דירות פעילות.'];

        foreach (array_slice($cityCounts, 0, 3, true) as $city => $cnt) {
            $msgs[] = $city . ': ' . $cnt . ($cnt === 1 ? ' דירה' : ' דירות') . '.';
        }

        if (!empty($typeCounts)) {
            $msgs[] = 'הסוג הנפוץ ביותר: ' . array_key_first($typeCounts) . '.';
        }

        if (!empty($prices)) {
            $msgs[] = 'מחיר ממוצע: ' . round(array_sum($prices) / count($prices)) . ' שקל ללילה.';
            $msgs[] = 'טווח מחירים: ' . min($prices) . ' עד ' . max($prices) . ' שקל.';
        }

        $msgs[] = 'לתפריט הראשי הקש 9.';

        respond([
            'type'            => 'menu',
            'id_list_message' => $msgs,
            'id_list_9'       => stepUrl('main'),
        ]);
        break;
    }

    // ─────────────────────────────────────────────────────────────
    default:
        respond(['type' => 'menu', 'goto' => stepUrl('main')]);
        break;
}
