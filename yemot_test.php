<?php
/**
 * yemot_test.php – שלב 1: בדיקת חיבור ל-API של ימות המשיח
 */

require_once __DIR__ . '/yemot_api.php';

try {
    $ym = new YemotAPI(YEMOT_PHONE, YEMOT_PASSWORD);
    echo "חיבור הצליח!\n";
    echo "Token: " . $ym->token . "\n";

    $session = $ym->call('GetSession');
    echo "יחידות: " . ($session->units ?? 'לא ידוע') . "\n";
    echo "responseStatus: " . ($session->responseStatus ?? '?') . "\n";

} catch (Exception $e) {
    echo "שגיאה: " . $e->getMessage() . "\n";
}
