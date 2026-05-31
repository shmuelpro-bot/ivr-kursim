<?php
/**
 * ivr_main.php – מערכת IVR: קו דירות לשבת (Twilio TwiML)
 */

require_once __DIR__ . '/lib.php';

$step  = $_GET['step'] ?? 'main';
$phone = $_REQUEST['From'] ?? '';
$digit = $_REQUEST['Digits'] ?? null;

switch ($step) {

    // ─────────────────────────────────────────────────────────────
    // MAIN MENU
    // ─────────────────────────────────────────────────────────────
    case 'main':
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('search_notice')),
                '2'     => redir(stepUrl('list_notice')),
                '3'     => redir(stepUrl('owner_check')),
                '4'     => redir(stepUrl('stats')),
                default => redir(stepUrl('main')),
            });
        }
        respond(menu(stepUrl('main'), [
            'שלום וברכה! ברוכים הבאים לקו דירות לשבת.',
            'לחיפוש דירה הקש 1.',
            'לפרסום דירה הקש 2.',
            'לניהול הפרסום שלך הקש 3.',
            'לסטטיסטיקות המערכת הקש 4.',
        ]));
        break;

    // ─────────────────────────────────────────────────────────────
    // PAYMENT NOTICES
    // ─────────────────────────────────────────────────────────────
    case 'search_notice':
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('search_rental_type')),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('search_notice')),
            });
        }
        respond(menu(stepUrl('search_notice'), [
            paymentMsg(),
            'להמשיך לחיפוש הקש 1.',
            'לחזרה לתפריט הקש 9.',
        ]));
        break;

    case 'list_notice':
        if (isShabbat()) {
            respond(say('מערכת הפרסום סגורה בשבת.', 'ניתן לפרסם דירות בימות החול בלבד.')
                  . redir(stepUrl('main')));
        }
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('list_rental_type')),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('list_notice')),
            });
        }
        respond(menu(stepUrl('list_notice'), [
            paymentMsg(),
            'להמשיך לפרסום הקש 1.',
            'לחזרה לתפריט הקש 9.',
        ]));
        break;

    // ================================================================
    //  LISTING FLOW
    // ================================================================

    case 'list_rental_type':
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('list_city', ['rt' => 1, 'pg' => 1])),
                '2'     => redir(stepUrl('list_city', ['rt' => 2, 'pg' => 1])),
                '3'     => redir(stepUrl('list_city', ['rt' => 3, 'pg' => 1])),
                default => redir(stepUrl('list_rental_type')),
            });
        }
        respond(menu(stepUrl('list_rental_type'), [
            'שאלה 1 – זמן השכרה.',
            'הקש 1 לשבת בלבד.',
            'הקש 2 לשבת החל מיום חמישי.',
            'הקש 3 לכל השבוע.',
        ]));
        break;

    case 'list_city': {
        $rt         = $_GET['rt'] ?? 1;
        $page       = max(1, intval($_GET['pg'] ?? 1));
        $totalPages = totalCityPages();
        $cities     = citiesForPage($page);

        if ($digit !== null) {
            if ($digit === '*' && $page < $totalPages) {
                respond(redir(stepUrl('list_city', ['rt' => $rt, 'pg' => $page + 1])));
            }
            if ($digit === '#' && $page > 1) {
                respond(redir(stepUrl('list_city', ['rt' => $rt, 'pg' => $page - 1])));
            }
            $d = intval($digit);
            if (isset($cities[$d])) {
                respond(redir(stepUrl('list_neighborhood', ['rt' => $rt, 'ci' => $cities[$d]['id']])));
            }
            respond(redir(stepUrl('list_city', ['rt' => $rt, 'pg' => $page])));
        }

        $lines = ['שאלה 2 – בחר עיר.' . ($totalPages > 1 ? ' עמוד ' . $page . ' מתוך ' . $totalPages . '.' : '')];
        foreach ($cities as $key => $city) {
            $lines[] = 'הקש ' . $key . ' ל' . $city['name'] . '.';
        }
        if ($page < $totalPages) $lines[] = 'לערים נוספות הקש כוכבית.';
        if ($page > 1)           $lines[] = 'לעמוד הקודם הקש סולמית.';

        respond(menu(stepUrl('list_city', ['rt' => $rt, 'pg' => $page]), $lines));
        break;
    }

    case 'list_neighborhood': {
        $rt  = $_GET['rt'] ?? 1;
        $ci  = intval($_GET['ci'] ?? 1);
        $nhs = NEIGHBORHOODS[$ci] ?? [];

        if (empty($nhs)) {
            respond(redir(stepUrl('list_street_ask', ['rt' => $rt, 'ci' => $ci, 'nh' => 0])));
        }

        if ($digit !== null) {
            $d = intval($digit);
            if (isset($nhs[$d])) {
                respond(redir(stepUrl('list_street_ask', ['rt' => $rt, 'ci' => $ci, 'nh' => $d])));
            }
            respond(redir(stepUrl('list_neighborhood', ['rt' => $rt, 'ci' => $ci])));
        }

        $lines = ['שאלה 3 – בחר שכונה.'];
        foreach ($nhs as $nid => $nname) {
            $lines[] = 'הקש ' . $nid . ' ל' . $nname . '.';
        }
        respond(menu(stepUrl('list_neighborhood', ['rt' => $rt, 'ci' => $ci]), $lines));
        break;
    }

    case 'list_street_ask': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0];
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('list_street_record', $p)),
                '2'     => redir(stepUrl('list_apt_type', array_merge($p, ['sr' => '']))),
                default => redir(stepUrl('list_street_ask', $p)),
            });
        }
        respond(menu(stepUrl('list_street_ask', $p), [
            'שאלה 4 – האם תרצה להוסיף שם רחוב?',
            'הקש 1 להקלטת שם הרחוב.',
            'הקש 2 לדילוג.',
        ]));
        break;
    }

    case 'list_street_record': {
        $p      = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0];
        $recUrl = $_REQUEST['RecordingUrl'] ?? '';
        if ($recUrl) {
            respond(redir(stepUrl('list_apt_type', array_merge($p, ['sr' => $recUrl]))));
        }
        respond(
            say('לאחר הצפצוף הקלט את שם הרחוב ולחץ סולמית.')
          . '<Record action="' . xe(stepUrl('list_street_record', $p))
          . '" maxLength="8" finishOnKey="#" playBeep="true" />'
        );
        break;
    }

    case 'list_apt_type': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1,
              'nh' => $_GET['nh'] ?? 0, 'sr' => $_GET['sr'] ?? ''];
        if ($digit !== null) {
            $d = intval($digit);
            if ($d >= 1 && $d <= 8) {
                respond(redir(stepUrl('list_beds', array_merge($p, ['at' => $d]))));
            }
            respond(redir(stepUrl('list_apt_type', $p)));
        }
        respond(menu(stepUrl('list_apt_type', $p), [
            'שאלה 5 – סוג הדירה.',
            'הקש 1 לדירה רגילה.',
            'הקש 2 לדירה חדשה.',
            'הקש 3 לדירה משופצת.',
            'הקש 4 לדירת אירוח.',
            'הקש 5 לצימר.',
            'הקש 6 לדירה במושב.',
            'הקש 7 לדירה לחג הקרוב.',
            'הקש 8 לדירה לבין הזמנים.',
        ]));
        break;
    }

    case 'list_beds': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0,
              'sr' => $_GET['sr'] ?? '', 'at' => $_GET['at'] ?? 1];
        if ($digit !== null) {
            respond(redir(stepUrl('list_bedrooms', array_merge($p, ['BEDS' => intval($digit)]))));
        }
        respond(numInput(stepUrl('list_beds', $p),
            'שאלה 6 – הקלד מספר מיטות כולל מזרנים ולחץ סולמית.'));
        break;
    }

    case 'list_bedrooms': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0,
              'sr' => $_GET['sr'] ?? '', 'at' => $_GET['at'] ?? 1, 'BEDS' => $_GET['BEDS'] ?? 0];
        if ($digit !== null) {
            respond(redir(stepUrl('list_price', array_merge($p, ['ROOMS' => intval($digit)]))));
        }
        respond(numInput(stepUrl('list_bedrooms', $p),
            'שאלה 7 – הקלד מספר חדרי שינה. לסטודיו הקש 0 ולחץ סולמית.'));
        break;
    }

    case 'list_price': {
        $p = ['rt' => $_GET['rt'] ?? 1, 'ci' => $_GET['ci'] ?? 1, 'nh' => $_GET['nh'] ?? 0,
              'sr' => $_GET['sr'] ?? '', 'at' => $_GET['at'] ?? 1,
              'BEDS' => $_GET['BEDS'] ?? 0, 'ROOMS' => $_GET['ROOMS'] ?? 0];
        if ($digit !== null) {
            respond(redir(stepUrl('list_confirm', array_merge($p, ['PRICE' => intval($digit)]))));
        }
        respond(numInput(stepUrl('list_price', $p),
            'שאלה 8 – הקלד מחיר ללילה בשקלים ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'));
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
        $p     = ['rt' => $rt, 'ci' => $ci, 'nh' => $nh, 'sr' => $_GET['sr'] ?? '',
                  'at' => $at, 'BEDS' => $beds, 'ROOMS' => $rooms, 'PRICE' => $price];

        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('list_save', $p)),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('list_confirm', $p)),
            });
        }

        $nhTxt    = $nh > 0    ? nhName($ci, $nh) : 'לא צוין';
        $roomsTxt = $rooms === 0 ? 'סטודיו' : $rooms . ' חדרי שינה';
        $priceTxt = $price > 0  ? $price . ' שקל ללילה' : 'מחיר לא צוין';

        respond(menu(stepUrl('list_confirm', $p), [
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
        ]));
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

        respond(say('הדירה פורסמה בהצלחה! הפרסום פעיל עד צאת השבת. נשלח אישור ב-SMS.')
              . redir(stepUrl('main')));
        break;
    }

    // ================================================================
    //  SEARCH FLOW
    // ================================================================

    case 'search_rental_type':
        if ($digit !== null) {
            $d = intval($digit);
            respond(redir(stepUrl('search_city', ['fr' => $d <= 3 ? $d : 0, 'pg' => 1])));
        }
        respond(menu(stepUrl('search_rental_type'), [
            'סנן לפי זמן השכרה.',
            'הקש 0 לכל הדירות.',
            'הקש 1 לשבת בלבד.',
            'הקש 2 לשבת החל מיום חמישי.',
            'הקש 3 לכל השבוע.',
        ]));
        break;

    case 'search_city': {
        $fr         = $_GET['fr'] ?? 0;
        $page       = max(1, intval($_GET['pg'] ?? 1));
        $totalPages = totalCityPages();
        $cities     = citiesForPage($page);

        if ($digit !== null) {
            if ($digit === '0') {
                respond(redir(stepUrl('search_apt_type', ['fr' => $fr, 'fc' => 0, 'fn' => 0])));
            }
            if ($digit === '*' && $page < $totalPages) {
                respond(redir(stepUrl('search_city', ['fr' => $fr, 'pg' => $page + 1])));
            }
            if ($digit === '#' && $page > 1) {
                respond(redir(stepUrl('search_city', ['fr' => $fr, 'pg' => $page - 1])));
            }
            $d = intval($digit);
            if (isset($cities[$d])) {
                respond(redir(stepUrl('search_neighborhood', ['fr' => $fr, 'fc' => $cities[$d]['id']])));
            }
            respond(redir(stepUrl('search_city', ['fr' => $fr, 'pg' => $page])));
        }

        $lines = ['סנן לפי עיר. הקש 0 לכל הערים.'
                . ($totalPages > 1 ? ' עמוד ' . $page . ' מתוך ' . $totalPages . '.' : '')];
        foreach ($cities as $key => $city) {
            $lines[] = 'הקש ' . $key . ' ל' . $city['name'] . '.';
        }
        if ($page < $totalPages) $lines[] = 'לערים נוספות הקש כוכבית.';
        if ($page > 1)           $lines[] = 'לעמוד הקודם הקש סולמית.';

        respond(menu(stepUrl('search_city', ['fr' => $fr, 'pg' => $page]), $lines));
        break;
    }

    case 'search_neighborhood': {
        $fr  = $_GET['fr'] ?? 0;
        $fc  = intval($_GET['fc'] ?? 0);
        $nhs = $fc > 0 ? (NEIGHBORHOODS[$fc] ?? []) : [];

        if (empty($nhs)) {
            respond(redir(stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => 0])));
        }

        if ($digit !== null) {
            if ($digit === '0') {
                respond(redir(stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => 0])));
            }
            $d = intval($digit);
            if (isset($nhs[$d])) {
                respond(redir(stepUrl('search_apt_type', ['fr' => $fr, 'fc' => $fc, 'fn' => $d])));
            }
            respond(redir(stepUrl('search_neighborhood', ['fr' => $fr, 'fc' => $fc])));
        }

        $lines = ['סנן לפי שכונה. הקש 0 לכל השכונות.'];
        foreach ($nhs as $nid => $nname) {
            $lines[] = 'הקש ' . $nid . ' ל' . $nname . '.';
        }
        respond(menu(stepUrl('search_neighborhood', ['fr' => $fr, 'fc' => $fc]), $lines));
        break;
    }

    case 'search_apt_type': {
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0];
        if ($digit !== null) {
            $d = intval($digit);
            respond(redir(stepUrl('search_beds', array_merge($p, ['fa' => $d <= 8 ? $d : 0]))));
        }
        respond(menu(stepUrl('search_apt_type', $p), [
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
        ]));
        break;
    }

    case 'search_beds': {
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0,
              'fn' => $_GET['fn'] ?? 0, 'fa' => $_GET['fa'] ?? 0];
        if ($digit !== null) {
            respond(redir(stepUrl('search_bedrooms', array_merge($p, ['FB' => intval($digit)]))));
        }
        respond(numInput(stepUrl('search_beds', $p),
            'הקלד מספר מיטות מינימום ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'));
        break;
    }

    case 'search_bedrooms': {
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0,
              'fn' => $_GET['fn'] ?? 0, 'fa' => $_GET['fa'] ?? 0, 'FB' => $_GET['FB'] ?? 0];
        if ($digit !== null) {
            respond(redir(stepUrl('search_price', array_merge($p, ['FBR' => intval($digit)]))));
        }
        respond(numInput(stepUrl('search_bedrooms', $p),
            'הקלד מספר חדרי שינה מינימום ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'));
        break;
    }

    case 'search_price': {
        $p = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0,
              'fa' => $_GET['fa'] ?? 0, 'FB' => $_GET['FB'] ?? 0, 'FBR' => $_GET['FBR'] ?? 0];
        if ($digit !== null) {
            respond(redir(stepUrl('search_intro', array_merge($p, ['FP' => intval($digit)]))));
        }
        respond(numInput(stepUrl('search_price', $p),
            'הקלד מחיר מקסימום ללילה ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'));
        break;
    }

    case 'search_intro': {
        $fr  = intval($_GET['fr']  ?? 0);
        $fc  = intval($_GET['fc']  ?? 0);
        $fn  = intval($_GET['fn']  ?? 0);
        $fa  = intval($_GET['fa']  ?? 0);
        $fb  = intval($_GET['FB']  ?? 0);
        $fbr = intval($_GET['FBR'] ?? 0);
        $fp  = intval($_GET['FP']  ?? 0);
        $fP  = ['fr' => $fr, 'fc' => $fc, 'fn' => $fn, 'fa' => $fa,
                'FB' => $fb, 'FBR' => $fbr, 'FP' => $fp];

        $total = count(filterApts(getApts(), [
            'rental_type' => $fr, 'city' => $fc, 'neighborhood' => $fn,
            'apt_type' => $fa, 'beds_min' => $fb, 'bedrooms_min' => $fbr, 'price_max' => $fp,
        ]));

        if ($total === 0) {
            if ($digit !== null) respond(redir(stepUrl($digit === '1' ? 'search_notice' : 'main')));
            respond(menu(stepUrl('search_intro', $fP), [
                'לא נמצאו דירות התואמות את הסינון שלך.',
                'לחיפוש חדש הקש 1.',
                'לתפריט הראשי הקש 9.',
            ]));
        }

        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('search_results', array_merge($fP, ['idx' => 0]))),
                '2'     => redir(stepUrl('search_notify_all', $fP)),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('search_intro', $fP)),
            });
        }
        respond(menu(stepUrl('search_intro', $fP), [
            'נמצאו ' . $total . ' דירות המתאימות לחיפוש שלך.',
            'לשמיעת הדירות הקש 1.',
            'לצינתוק לכל בעלי הדירות המתאימים הקש 2.',
            'לתפריט הראשי הקש 9.',
        ]));
        break;
    }

    case 'search_notify_all': {
        $fr  = intval($_GET['fr']  ?? 0);
        $fc  = intval($_GET['fc']  ?? 0);
        $fn  = intval($_GET['fn']  ?? 0);
        $fa  = intval($_GET['fa']  ?? 0);
        $fb  = intval($_GET['FB']  ?? 0);
        $fbr = intval($_GET['FBR'] ?? 0);
        $fp  = intval($_GET['FP']  ?? 0);

        $results = filterApts(getApts(), [
            'rental_type' => $fr, 'city' => $fc, 'neighborhood' => $fn,
            'apt_type' => $fa, 'beds_min' => $fb, 'bedrooms_min' => $fbr, 'price_max' => $fp,
        ]);

        $phones = array_unique(array_column($results, 'owner_phone'));
        foreach ($phones as $ownerPhone) {
            flashCall($ownerPhone);
        }
        $count = count($phones);
        respond(say(
            'שלחנו צינתוק ל ' . $count . ' בעלי דירות.',
            'הם יראו שיחה שלא נענתה ויצרו איתך קשר בהקדם.',
        ) . redir(stepUrl('main')));
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
        $total = count($results);
        $fP    = ['fr' => $fr, 'fc' => $fc, 'fn' => $fn, 'fa' => $fa,
                  'FB' => $fb, 'FBR' => $fbr, 'FP' => $fp];

        if ($total === 0) {
            if ($digit !== null) respond(redir(stepUrl($digit === '1' ? 'search_notice' : 'main')));
            respond(menu(stepUrl('search_results', array_merge($fP, ['idx' => 0])), [
                'לא נמצאו דירות התואמות את הסינון שלך.',
                'לחיפוש חדש הקש 1.',
                'לתפריט הראשי הקש 9.',
            ]));
        }

        if ($idx >= $total) {
            if ($digit !== null) respond(redir(stepUrl($digit === '1' ? 'search_notice' : 'main')));
            respond(menu(stepUrl('search_results', array_merge($fP, ['idx' => $idx])), [
                'הגעת לסוף הרשימה.',
                'לחיפוש חדש הקש 1.',
                'לתפריט הראשי הקש 9.',
            ]));
        }

        $apt      = $results[$idx];
        $priceTxt = $apt['price'] > 0 ? $apt['price'] . ' שקל ללילה' : 'מחיר לא צוין';
        $roomsTxt = $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חדרי שינה';

        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('search_results', array_merge($fP, ['idx' => $idx + 1]))),
                '2'     => redir(stepUrl('search_results', array_merge($fP, ['idx' => max(0, $idx - 1)]))),
                '3'     => redir(stepUrl('search_contact', array_merge($fP, ['idx' => $idx, 'own' => $apt['owner_phone']]))),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('search_results', array_merge($fP, ['idx' => $idx]))),
            });
        }

        $lines = [
            'דירה ' . ($idx + 1) . ' מתוך ' . $total . '.',
            'מיקום: ' . locationStr($apt) . '.',
            'סוג: ' . aptTypeName($apt['apt_type']) . '.',
            'מיטות: ' . $apt['beds'] . '.',
            $roomsTxt . '.',
            $priceTxt . '.',
            'זמן השכרה: ' . rentalName($apt['rental_type']) . '.',
            'לדירה הבאה הקש 1.',
        ];
        if ($idx > 0) $lines[] = 'לדירה הקודמת הקש 2.';
        $lines[] = 'לפרטי קשר עם בעל הדירה הקש 3.';
        $lines[] = 'לתפריט הראשי הקש 9.';

        respond(menu(stepUrl('search_results', array_merge($fP, ['idx' => $idx])), $lines));
        break;
    }

    case 'search_contact': {
        $ownerPhone = $_GET['own'] ?? '';
        $fP = ['fr' => $_GET['fr'] ?? 0, 'fc' => $_GET['fc'] ?? 0, 'fn' => $_GET['fn'] ?? 0,
               'fa' => $_GET['fa'] ?? 0, 'FB' => $_GET['FB'] ?? 0, 'FBR' => $_GET['FBR'] ?? 0,
               'FP' => $_GET['FP'] ?? 0, 'idx' => $_GET['idx'] ?? 0];
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('search_results', $fP)),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('search_contact', array_merge($fP, ['own' => $ownerPhone]))),
            });
        }
        respond(menu(stepUrl('search_contact', array_merge($fP, ['own' => $ownerPhone])), [
            paymentMsg(),
            'מספר הטלפון של בעל הדירה הוא ' . $ownerPhone . '.',
            'לחזרה לרשימה הקש 1.',
            'לתפריט הראשי הקש 9.',
        ]));
        break;
    }

    // ================================================================
    //  OWNER MANAGEMENT
    // ================================================================

    case 'owner_check': {
        if (isShabbat()) {
            respond(say('ניהול הפרסום אינו זמין בשבת.', 'ניתן לעדכן את הפרסום בימות החול בלבד.')
                  . redir(stepUrl('main')));
        }

        $apts   = getApts();
        $myApts = array_values(array_filter($apts, fn($a) => $a['owner_phone'] === $phone));

        if (empty($myApts)) {
            if ($digit !== null) {
                respond(match($digit) {
                    '1'     => redir(stepUrl('list_notice')),
                    '9'     => redir(stepUrl('main')),
                    default => redir(stepUrl('owner_check')),
                });
            }
            respond(menu(stepUrl('owner_check'), [
                'לא נמצא פרסום פעיל במספר טלפון זה.',
                'לפרסום דירה חדשה הקש 1.',
                'לתפריט הראשי הקש 9.',
            ]));
        }

        $apt      = $myApts[count($myApts) - 1];
        $count    = count($myApts);
        $priceTxt = $apt['price'] > 0 ? $apt['price'] . ' שקל ללילה' : 'מחיר לא צוין';
        $roomsTxt = $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חדרי שינה';

        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('owner_update_price', ['aid' => $apt['id']])),
                '2'     => redir(stepUrl('owner_delete_confirm', ['aid' => $apt['id']])),
                '9'     => redir(stepUrl('main')),
                default => redir(stepUrl('owner_check')),
            });
        }

        $lines = array_values(array_filter([
            'ניהול הפרסום שלך.',
            $count > 1 ? 'יש לך ' . $count . ' פרסומים. מציג את האחרון.' : '',
            'מיקום: ' . locationStr($apt) . '.',
            'סוג: ' . aptTypeName($apt['apt_type']) . '.',
            'מיטות: ' . $apt['beds'] . '.', $roomsTxt . '.', $priceTxt . '.',
            'זמן השכרה: ' . rentalName($apt['rental_type']) . '.',
            'לעדכון מחיר הקש 1.',
            'למחיקת הפרסום הקש 2.',
            'לתפריט הראשי הקש 9.',
        ]));
        respond(menu(stepUrl('owner_check'), $lines));
        break;
    }

    case 'owner_update_price': {
        $aid = $_GET['aid'] ?? '';
        if ($digit !== null) {
            $newPrice = intval($digit);
            $apts = getApts();
            foreach ($apts as &$a) {
                if ($a['id'] === $aid && $a['owner_phone'] === $phone) {
                    $a['price'] = $newPrice;
                    break;
                }
            }
            unset($a);
            saveApts($apts);
            $txt = $newPrice > 0 ? $newPrice . ' שקל ללילה' : 'ללא מחיר מצוין';
            respond(say('המחיר עודכן בהצלחה ל' . $txt . '.') . redir(stepUrl('main')));
        }
        respond(numInput(stepUrl('owner_update_price', ['aid' => $aid]),
            'הקלד מחיר חדש ללילה בשקלים ולחץ סולמית. לדילוג הקש 0 ולחץ סולמית.'));
        break;
    }

    case 'owner_delete_confirm': {
        $aid = $_GET['aid'] ?? '';
        if ($digit !== null) {
            respond(match($digit) {
                '1'     => redir(stepUrl('owner_delete', ['aid' => $aid])),
                '9'     => redir(stepUrl('owner_check')),
                default => redir(stepUrl('owner_delete_confirm', ['aid' => $aid])),
            });
        }
        respond(menu(stepUrl('owner_delete_confirm', ['aid' => $aid]), [
            'האם אתה בטוח שברצונך למחוק את הפרסום?',
            'לאישור מחיקה הקש 1.',
            'לביטול הקש 9.',
        ]));
        break;
    }

    case 'owner_delete': {
        $aid  = $_GET['aid'] ?? '';
        $apts = getApts();
        $apts = array_values(array_filter($apts,
            fn($a) => !($a['id'] === $aid && $a['owner_phone'] === $phone)));
        saveApts($apts);
        sendSMS($phone, 'הפרסום שלך הוסר מקו דירות לשבת.');
        respond(say('הפרסום הוסר בהצלחה. נשלח אישור ב-SMS.') . redir(stepUrl('main')));
        break;
    }

    // ================================================================
    //  STATISTICS
    // ================================================================

    case 'stats': {
        $apts  = getApts();
        $total = count($apts);

        if ($digit !== null) respond(redir(stepUrl('main')));

        if ($total === 0) {
            respond(menu(stepUrl('stats'), [
                'אין כרגע דירות פעילות במערכת.',
                'לתפריט הראשי הקש 9.',
            ]));
        }

        $cityCounts = $typeCounts = [];
        $prices = [];
        foreach ($apts as $a) {
            $cn = cityName($a['city']);
            $cityCounts[$cn] = ($cityCounts[$cn] ?? 0) + 1;
            $tn = aptTypeName($a['apt_type']);
            $typeCounts[$tn] = ($typeCounts[$tn] ?? 0) + 1;
            if ($a['price'] > 0) $prices[] = $a['price'];
        }
        arsort($cityCounts);

        $lines = ['סטטיסטיקות המערכת.', 'סך הכל ' . $total . ' דירות פעילות.'];
        foreach (array_slice($cityCounts, 0, 3, true) as $city => $cnt) {
            $lines[] = $city . ': ' . $cnt . ($cnt === 1 ? ' דירה' : ' דירות') . '.';
        }
        if (!empty($typeCounts)) {
            arsort($typeCounts);
            $lines[] = 'הסוג הנפוץ ביותר: ' . array_key_first($typeCounts) . '.';
        }
        if (!empty($prices)) {
            $lines[] = 'מחיר ממוצע: ' . round(array_sum($prices) / count($prices)) . ' שקל ללילה.';
        }
        $lines[] = 'לתפריט הראשי הקש 9.';

        respond(menu(stepUrl('stats'), $lines));
        break;
    }

    default:
        respond(redir(stepUrl('main')));
        break;
}
