<?php
/**
 * otp_voice.php – TwiML: מקריא קוד OTP בשיחת טלפון
 * Twilio קורא לכתובת זו כשמתקשרים למשתמש
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: text/xml; charset=utf-8');

// ── KV helpers (copy from publish.php pattern) ──
function vKvGet(string $k): mixed {
    if (hasRedis()) {
        $r = redisExec(['GET', $k]);
        return ($r !== null && $r !== '') ? json_decode($r, true) : null;
    }
    return fileKvGet($k);
}
function vKvDel(string $k): void {
    if (hasRedis()) redisExec(['DEL', $k]);
    else fileKvDel($k);
}

$token = trim($_GET['t'] ?? '');
$code  = $token ? vKvGet('vtk:' . $token) : null;

if ($code) {
    vKvDel('vtk:' . $token); // single-use
}

function twiSay(string $text): string {
    return '<Say language="he-IL" voice="woman">'
         . htmlspecialchars($text, ENT_XML1, 'UTF-8')
         . '</Say>';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if (!$code || !preg_match('/^\d{5}$/', (string)$code)) {
    echo '<Response>';
    echo twiSay('שגיאה. אנא נסה שנית.');
    echo '<Hangup/></Response>';
    exit;
}

// קרא כל ספרה בנפרד עם פסיקים
$digits = implode(', ', str_split((string)$code));
?>
<Response>
  <?= twiSay('שלום! קוד האימות שלך הוא:') ?>
  <Pause length="1"/>
  <?= twiSay($digits) ?>
  <Pause length="2"/>
  <?= twiSay('אני חוזר. הקוד הוא:') ?>
  <Pause length="1"/>
  <?= twiSay($digits) ?>
  <Pause length="2"/>
  <?= twiSay('שוב, הקוד:') ?>
  <Pause length="1"/>
  <?= twiSay($digits) ?>
  <Pause length="1"/>
  <?= twiSay('תודה. להתראות.') ?>
  <Hangup/>
</Response>
