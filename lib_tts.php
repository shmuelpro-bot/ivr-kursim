<?php
/**
 * lib_tts.php — פונקציית ליבה משותפת להמרת טקסט לדיבור דרך ElevenLabs.
 * משמשת גם את tts.php (CLI) וגם את tts_web.php (דפדפן).
 */

/**
 * @return array{ok: bool, audio: ?string, error: ?string, httpCode: ?int}
 */
function elevenlabs_tts(string $text, ?string $voiceId = null, ?string $modelId = null): array {
    $apiKey  = getenv('ELEVENLABS_API_KEY') ?: '';
    $voiceId = $voiceId ?: (getenv('ELEVENLABS_VOICE_ID') ?: 'pNInz6obpgDQGcFmaJgB'); // Adam – קול גבר, מתאים לקריינות
    $modelId = $modelId ?: (getenv('ELEVENLABS_MODEL_ID') ?: 'eleven_turbo_v2_5');   // תומך בעברית

    if ($apiKey === '') {
        return ['ok' => false, 'audio' => null, 'error' => 'לא הוגדר ELEVENLABS_API_KEY.', 'httpCode' => null];
    }

    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'audio' => null, 'error' => 'לא סופק טקסט להקראה.', 'httpCode' => null];
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
        return ['ok' => false, 'audio' => null, 'error' => "שגיאת רשת בפנייה ל-ElevenLabs: {$curlError}", 'httpCode' => null];
    }

    if ($httpCode !== 200) {
        return ['ok' => false, 'audio' => null, 'error' => "שגיאה מ-ElevenLabs (HTTP {$httpCode}): {$response}", 'httpCode' => $httpCode];
    }

    return ['ok' => true, 'audio' => $response, 'error' => null, 'httpCode' => 200];
}
