<?php
/**
 * cron.php – ניקוי אוטומטי של פרסומים פגי תוקף
 *
 * יש להריץ בכל מוצאי שבת (שבת 21:00 שעון ישראל).
 *
 * קריאה חיצונית:
 *   GET /cron.php?secret=<CRON_SECRET>
 *
 * לדוגמה ב-render.com (Cron Job):
 *   curl "https://ivr-kursim.onrender.com/cron.php?secret=MY_SECRET"
 *   תזמון: 0 21 * * 6  (כל שבת בשעה 21:00 UTC+3)
 */

require_once __DIR__ . '/lib.php';

// ── Auth ───────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    $given = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if (!hash_equals(CRON_SECRET, $given)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// ── Cleanup ────────────────────────────────────────────────────

$all    = getAllApts();
$now    = time();
$before = count($all);
$active = array_values(array_filter($all, fn($a) => ($a['expires'] ?? 0) > $now));
$after  = count($active);

saveApts($active);

// ── Report ────────────────────────────────────────────────────

$removed  = $before - $after;
$tz       = new DateTimeZone('Asia/Jerusalem');
$dateStr  = (new DateTime('now', $tz))->format('d/m/Y H:i:s');

$report = [
    'timestamp' => $dateStr,
    'before'    => $before,
    'after'     => $after,
    'removed'   => $removed,
    'status'    => 'ok',
];

$msg = "[cron] {$dateStr} – הוסרו {$removed} פרסומים. נשארו {$after} פעילים.";
error_log($msg);

header('Content-Type: application/json');
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
