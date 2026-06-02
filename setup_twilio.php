<?php
// One-time setup: updates Twilio webhook URL to this server
$sid   = getenv('TWILIO_ACCOUNT_SID');
$token = getenv('TWILIO_AUTH_TOKEN');
$newUrl = 'https://ivr-kursim-1.onrender.com/ivr_main.php';

if (!$sid || !$token) {
    die("Missing Twilio credentials in environment variables.");
}

// Find phone numbers
$resp = file_get_contents(
    "https://api.twilio.com/2010-04-01/Accounts/$sid/IncomingPhoneNumbers.json",
    false,
    stream_context_create(['http' => [
        'header' => "Authorization: Basic " . base64_encode("$sid:$token")
    ]])
);

$data = json_decode($resp, true);
if (!$data || empty($data['incoming_phone_numbers'])) {
    die("No phone numbers found or API error: $resp");
}

$results = [];
foreach ($data['incoming_phone_numbers'] as $num) {
    $pnSid  = $num['sid'];
    $phone  = $num['phone_number'];
    $appSid = $num['voice_application_sid'] ?? '';

    $update = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: Basic " . base64_encode("$sid:$token") . "\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => http_build_query([
            'VoiceUrl'            => $newUrl,
            'VoiceMethod'         => 'POST',
            'VoiceApplicationSid' => '',   // clear any app that overrides webhook
        ]),
    ]]);
    $r = file_get_contents("https://api.twilio.com/2010-04-01/Accounts/$sid/IncomingPhoneNumbers/$pnSid.json", false, $update);
    $updated = json_decode($r, true);
    $voiceUrl = $updated['voice_url'] ?? 'error';
    $appAfter = $updated['voice_application_sid'] ?? 'none';
    $results[] = "$phone | voice_url=$voiceUrl | app_sid=$appAfter | was_app=" . ($appSid ?: 'none');
}

echo "<h2>Done!</h2><ul>";
foreach ($results as $r) echo "<li>$r</li>";
echo "</ul>";
