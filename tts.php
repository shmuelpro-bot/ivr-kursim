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
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('כלי זה מיועד להרצה משורת הפקודה בלבד.');
}

$apiKey  = getenv('ELEVENLABS_API_KEY') ?: '';
$voiceId = getenv('ELEVENLABS_VOICE_ID') ?: 'pNInz6obpgDQGcFmaJgB'; // Adam – קול גבר, מתאים לקריינות
$modelId = getenv('ELEVENLABS_MODEL_ID') ?: 'eleven_turbo_v2_5';   // תומך בעברית

if ($apiKey === '') {
    fwrite(STDERR, "שגיאה: לא הוגדר ELEVENLABS_API_KEY (הגדירו אותו כמשתנה סביבה או ב-.env).\n");
    exit(1);
}

$textArg = $argv[1] ?? null;
$outPath = $argv[2] ?? null;

if ($textArg === null) {
    fwrite(STDERR, "שימוש: php tts.php \"טקסט להקראה\" [output.mp3]\n");
    fwrite(STDERR, "       echo \"טקסט\" | php tts.php - [output.mp3]\n");
    exit(1);
}

$text = $textArg === '-' ? stream_get_contents(STDIN) : $textArg;
$text = trim((string) $text);

if ($text === '') {
    fwrite(STDERR, "שגיאה: לא סופק טקסט להקראה.\n");
    exit(1);
}

if ($outPath === null) {
    $outPath = 'tts_' . date('Ymd_His') . '.mp3';
}

$payload = json_encode([
    'text'     => $text,
    'model_id' => $modelId,
    'voice_settings' => [
        'stability'         => 0.5,
        'similarity_boost'  => 0.75,
        'style'             => 0.3,
        'use_speaker_boost' => true,
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'xi-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: audio/mpeg',
    ],
    CURLOPT_TIMEOUT        => 60,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "שגיאת רשת בפנייה ל-ElevenLabs: {$curlError}\n");
    exit(1);
}

if ($httpCode !== 200) {
    fwrite(STDERR, "שגיאה מ-ElevenLabs (HTTP {$httpCode}):\n{$response}\n");
    exit(1);
}

file_put_contents($outPath, $response);
fwrite(STDOUT, "הקובץ נשמר בהצלחה: {$outPath}\n");
