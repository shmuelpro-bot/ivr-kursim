<?php
/**
 * tts_web.php — עמוד דפדפן: הזנת טקסט וקבלת קובץ קול (MP3) להורדה.
 * קול גבר קריין מקצועי בעברית, דרך ElevenLabs.
 *
 * מוגן בסיסמת הניהול (אותה סיסמה של admin.php) כדי למנוע שימוש-יתר
 * לא מבוקר במפתח ה-API הבתשלום.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_tts.php';

session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: tts_web.php');
    exit;
}

$loginError = '';
$genError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['tts_auth'])) {
    if (hash_equals(ADMIN_PASS, $_POST['pass'] ?? '')) {
        $_SESSION['tts_auth'] = true;
    } else {
        $loginError = 'סיסמה שגויה. נסה שוב.';
    }
}

$loggedIn = isset($_SESSION['tts_auth']);

// יצירת הקול והורדתו — אם התקבל טקסט מתוך פעולת "צור והורד"
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
    $result = elevenlabs_tts($_POST['text']);

    if ($result['ok']) {
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: attachment; filename="tts_' . date('Ymd_His') . '.mp3"');
        header('Content-Length: ' . strlen($result['audio']));
        echo $result['audio'];
        exit;
    }

    $genError = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<title>יצירת קובץ קול מטקסט</title>
<style>
  body { font-family: system-ui, sans-serif; background:#f4f6f9; margin:0; padding:40px 16px; }
  .box { max-width:520px; margin:0 auto; background:#fff; border-radius:12px; padding:28px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
  h1 { font-size:1.3rem; margin:0 0 18px; }
  textarea { width:100%; min-height:160px; box-sizing:border-box; padding:12px; font-size:1rem; border:1px solid #ccc; border-radius:8px; resize:vertical; font-family:inherit; }
  input[type=password] { width:100%; box-sizing:border-box; padding:12px; font-size:1rem; border:1px solid #ccc; border-radius:8px; }
  button { margin-top:14px; padding:12px 20px; font-size:1rem; border:0; border-radius:8px; background:#2b5fd9; color:#fff; cursor:pointer; }
  button:hover { background:#2049b0; }
  .error { color:#c0392b; margin-top:10px; }
  .hint { color:#666; font-size:.85rem; margin-top:10px; }
  a.logout { display:block; text-align:left; font-size:.85rem; color:#888; margin-top:16px; }
</style>
</head>
<body>
<div class="box">
<h1>יצירת קובץ קול מטקסט</h1>

<?php if (!$loggedIn): ?>
  <form method="post">
    <input type="password" name="pass" placeholder="הכנס סיסמה" autofocus required>
    <button type="submit">כניסה</button>
  </form>
  <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
<?php else: ?>
  <form method="post">
    <textarea name="text" placeholder="הקלד כאן את הטקסט שברצונך להפוך לקובץ קול..." required></textarea>
    <br>
    <button type="submit">צור והורד קובץ קול</button>
  </form>
  <?php if ($genError): ?><p class="error"><?= htmlspecialchars($genError) ?></p><?php endif; ?>
  <p class="hint">הלחיצה על הכפתור תיצור קובץ MP3 בקול גבר קריין ותוריד אותו אוטומטית לדפדפן.</p>
  <a class="logout" href="?logout=1">התנתקות</a>
<?php endif; ?>

</div>
</body>
</html>
