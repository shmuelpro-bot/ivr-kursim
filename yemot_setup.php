<?php
/**
 * yemot_setup.php – הגדרת מבנה השלוחות הראשוני
 * הפעל פעם אחת מהמחשב שלך:
 *   YEMOT_PHONE=0772522826 YEMOT_PASSWORD=13323127 php yemot_setup.php
 */

require_once __DIR__ . '/yemot_api.php';

$ym = new YemotAPI(YEMOT_PHONE, YEMOT_PASSWORD);
echo "מחובר. Token: {$ym->token}\n\n";

// ─────────────────────────────────────────────────────────
//  שלוחה ראשית (/)
// ─────────────────────────────────────────────────────────
createExt($ym, '/', 'playfile', 'תפריט ראשי');

uploadTts($ym, '/', '000',
    "שלום וברכה!, ברוכים הבאים לקו דירות לשבת.,\n" .
    "לחיפוש דירה הקש 1.,\n" .
    "לפרסום דירה הקש 2.,\n"
);

uploadIni($ym, '/',
    "type=playfile\n" .
    "control_play1=go_to_folder\n" .
    "playfile_control_play_1_goto=/1\n" .
    "control_play2=go_to_folder\n" .
    "playfile_control_play_2_goto=/2\n" .
    "playfile_end_goto=.\n"       // חזרה על התפריט אם לא נלחץ כלום
);

// ─────────────────────────────────────────────────────────
//  שלוחת חיפוש (/1)  ← תתחבר לשרת שלנו
// ─────────────────────────────────────────────────────────
createExt($ym, '/1', 'playfile', 'חיפוש דירות');

uploadTts($ym, '/1', '000', "חיפוש דירות, אנא המתן.,\n");

uploadIni($ym, '/1',
    "type=playfile\n" .
    "control_play1=send_api\n" .
    "api_link=" . YEMOT_IVR_URL . "?flow=search\n" .
    "api_url_post=yes\n" .
    "api_dir=/1\n" .
    "api_end_goto=/\n" .
    "playfile_end_goto=.\n"
);

// ─────────────────────────────────────────────────────────
//  שלוחת פרסום (/2)  ← תתחבר לשרת שלנו
// ─────────────────────────────────────────────────────────
createExt($ym, '/2', 'playfile', 'פרסום דירות');

uploadTts($ym, '/2', '000', "פרסום דירות, אנא המתן.,\n");

uploadIni($ym, '/2',
    "type=playfile\n" .
    "control_play1=send_api\n" .
    "api_link=" . YEMOT_IVR_URL . "?flow=list\n" .
    "api_url_post=yes\n" .
    "api_dir=/2\n" .
    "api_end_goto=/\n" .
    "playfile_end_goto=.\n"
);

echo "\nהגדרות הושלמו בהצלחה!\n";

// ─────────────────────────────────────────────────────────
//  פונקציות עזר
// ─────────────────────────────────────────────────────────

function createExt(YemotAPI $ym, string $path, string $type, string $title): void
{
    $r = $ym->call('UpdateExtension', [
        'path'  => 'ivr2:' . $path,
        'type'  => $type,
        'title' => $title,
    ]);
    echo "שלוחה {$path} ({$title}): " . ($r->responseStatus ?? 'שגיאה') . "\n";
}

function uploadTts(YemotAPI $ym, string $folder, string $name, string $text): void
{
    $file = new oFile($name . '.tts', 'text/plain', $text);
    $r    = $ym->call('UploadFile', [
        'path'         => 'ivr2:' . rtrim($folder, '/') . '/' . $name . '.tts',
        'convertAudio' => 0,
        'fileUpload'   => $file,
    ]);
    echo "  TTS {$folder}/{$name}.tts: " . ($r->responseStatus ?? 'שגיאה') . "\n";
}

function uploadIni(YemotAPI $ym, string $folder, string $content): void
{
    $file = new oFile('ext.ini', 'text/plain', $content);
    $r    = $ym->call('UploadFile', [
        'path'         => 'ivr2:' . rtrim($folder, '/') . '/ext.ini',
        'convertAudio' => 0,
        'fileUpload'   => $file,
    ]);
    echo "  ext.ini {$folder}: " . ($r->responseStatus ?? 'שגיאה') . "\n";
}
