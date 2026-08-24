<?php
/**
 * tts.php — כלי כללי להמרת טקסט עברי לדיבור (Text-to-Speech)
 * באמצעות ElevenLabs, עם קול קריין גבר מקצועי.
 *
 * שימוש:
 *   php tts.php "הטקסט שברצונך להקריא" [output.mp3]
 *   echo "טקסט מה-STDIN" | php tts.php - [output.mp3]
 *
 * דורש משתני סביבה (ראו .env.example):
 *   ELEVENLABS_API_KEY   – חובה
 *   ELEVENLABS_VOICE_ID  – אופציונלי, ברירת מחדל: קול גבר קריין (Adam)
 *   ELEVENLABS_MODEL_ID  – אופציונלי, ברירת מחדל: eleven_turbo_v2_5 (תומך עברית)
 *
 * לשימוש מהדפדפן (הזנת טקסט וקבלת קובץ להורדה) – ראו tts_web.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('כלי זה מיועד להרצה משורת הפקודה בלבד. לשימוש מהדפדפן ראו tts_web.php.');
}

require_once __DIR__ . '/lib_tts.php';

$textArg = $argv[1] ?? null;
$outPath = $argv[2] ?? null;

if ($textArg === null) {
    fwrite(STDERR, "שימוש: php tts.php \"טקסט להקראה\" [output.mp3]\n");
    fwrite(STDERR, "       echo \"טקסט\" | php tts.php - [output.mp3]\n");
    exit(1);
}

$text = $textArg === '-' ? stream_get_contents(STDIN) : $textArg;

if ($outPath === null) {
    $outPath = 'tts_' . date('Ymd_His') . '.mp3';
}

$result = elevenlabs_tts((string) $text);

if (!$result['ok']) {
    fwrite(STDERR, $result['error'] . "\n");
    exit(1);
}

file_put_contents($outPath, $result['audio']);
fwrite(STDOUT, "הקובץ נשמר בהצלחה: {$outPath}\n");
