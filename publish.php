<?php
/**
 * publish.php – דף פרסום דירות לשבוע הקרוב
 * אזור אישי + שאלות מובנות + העלאת תמונות
 */

require_once __DIR__ . '/lib.php';

session_start();

// ── Constants ──────────────────────────────────────────────────
define('MAX_IMAGES',    4);
define('MAX_IMG_BYTES', 5 * 1024 * 1024); // 5MB per image (before resize)

// ── Redis helpers ──────────────────────────────────────────────

function redisCmd(array $cmd): mixed {
    if (!REDIS_URL || !REDIS_TOKEN) return null;
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: Bearer " . REDIS_TOKEN . "\r\nContent-Type: application/json",
        'content' => json_encode([$cmd]),
    ]]);
    $r = @file_get_contents(REDIS_URL . '/pipeline', false, $ctx);
    if (!$r) return null;
    $d = json_decode($r, true);
    return $d[0]['result'] ?? null;
}

function saveAptImages(string $aptId, array $b64): void {
    if (empty($b64)) return;
    redisCmd(['SET', 'apt_imgs:' . $aptId, json_encode($b64), 'EX', 60 * 60 * 24 * 8]);
}

function getAptImages(string $aptId): array {
    $raw = redisCmd(['GET', 'apt_imgs:' . $aptId]);
    if (!$raw) return [];
    $imgs = json_decode($raw, true);
    return is_array($imgs) ? $imgs : [];
}

function delAptImages(string $aptId): void {
    redisCmd(['DEL', 'apt_imgs:' . $aptId]);
}

// ── Image resize ───────────────────────────────────────────────

function resizeToJpeg(string $tmpPath, string $mime, int $maxW = 900, int $maxH = 700, int $quality = 72): string|false {
    if (!function_exists('imagecreatefromjpeg')) {
        return file_get_contents($tmpPath); // GD not available – use raw
    }
    $src = match ($mime) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($tmpPath),
        'image/png'               => @imagecreatefrompng($tmpPath),
        'image/webp'              => @imagecreatefromwebp($tmpPath),
        'image/gif'               => @imagecreatefromgif($tmpPath),
        default                   => false,
    };
    if (!$src) return file_get_contents($tmpPath);
    $ow = imagesx($src);
    $oh = imagesy($src);
    $ratio = min($maxW / $ow, $maxH / $oh, 1.0);
    $nw = (int)round($ow * $ratio);
    $nh = (int)round($oh * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    // preserve transparency for PNG/GIF
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);
    ob_start();
    imagejpeg($dst, null, $quality);
    imagedestroy($dst);
    return ob_get_clean();
}

// ── OTP ────────────────────────────────────────────────────────

function normalizePhone(string $p): string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 10 && str_starts_with($p, '0')) {
        return '+972' . substr($p, 1);
    }
    if (strlen($p) === 9 && !str_starts_with($p, '0')) {
        return '+972' . $p;
    }
    if (str_starts_with($p, '972') && strlen($p) === 12) {
        return '+' . $p;
    }
    return $p;
}

function dispPhone(string $p): string {
    // +9720501234567 -> 050-123-4567
    if (str_starts_with($p, '+972')) {
        $local = '0' . substr($p, 4);
        return substr($local, 0, 3) . '-' . substr($local, 3, 3) . '-' . substr($local, 6);
    }
    return $p;
}

function sendOTPCode(string $phone): bool {
    $code = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    redisCmd(['SET', 'otp:' . $phone, $code, 'EX', 300]);
    sendSMS($phone, "קו דירות לשבת – קוד אימות: {$code}\nתקף ל-5 דקות.");
    return true;
}

function checkOTP(string $phone, string $code): bool {
    $stored = redisCmd(['GET', 'otp:' . $phone]);
    if (!$stored) return false;
    if (hash_equals((string)$stored, trim($code))) {
        redisCmd(['DEL', 'otp:' . $phone]);
        return true;
    }
    return false;
}

// ── State ──────────────────────────────────────────────────────

$userPhone = $_SESSION['pub_phone'] ?? null;
$step      = $_POST['step'] ?? $_GET['step'] ?? ($userPhone ? 'dashboard' : 'home');
$error     = '';
$success   = '';

if (isset($_GET['logout'])) {
    unset($_SESSION['pub_phone'], $_SESSION['otp_phone']);
    header('Location: publish.php');
    exit;
}

// ── POST handlers ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. שליחת OTP
    if ($action === 'send_otp') {
        $raw = trim($_POST['phone'] ?? '');
        $phone = normalizePhone($raw);
        if (strlen($phone) < 12) {
            $error = 'מספר טלפון לא תקין. יש להזין מספר ישראלי (050/052/054...).';
            $step  = 'login';
        } else {
            sendOTPCode($phone);
            $_SESSION['otp_phone'] = $phone;
            $step    = 'verify';
            $success = 'קוד אימות נשלח ל-' . dispPhone($phone);
        }
    }

    // ── 2. אימות OTP
    elseif ($action === 'verify_otp') {
        $phone = $_SESSION['otp_phone'] ?? '';
        $code  = trim($_POST['code'] ?? '');
        if (!$phone) {
            $error = 'נא להתחיל מחדש.';
            $step  = 'login';
        } elseif (checkOTP($phone, $code)) {
            $_SESSION['pub_phone'] = $phone;
            unset($_SESSION['otp_phone']);
            $userPhone = $phone;
            $step      = 'dashboard';
        } else {
            $error = 'קוד שגוי או פג תוקף. ניתן לשלוח קוד חדש.';
            $step  = 'verify';
        }
    }

    // ── 3. פרסום דירה
    elseif ($action === 'publish' && $userPhone) {
        $city        = intval($_POST['city']         ?? 0);
        $nh          = intval($_POST['neighborhood'] ?? 0);
        $aptType     = intval($_POST['apt_type']     ?? 0);
        $beds        = max(1, min(12, intval($_POST['beds']     ?? 1)));
        $bedrooms    = max(0, min(10, intval($_POST['bedrooms'] ?? 1)));
        $rentalType  = intval($_POST['rental_type']  ?? 1);
        $price       = max(0, intval($_POST['price'] ?? 0));
        $floor       = trim(strip_tags($_POST['floor']        ?? ''));
        $features    = array_map('strip_tags', $_POST['features'] ?? []);
        $description = mb_substr(strip_tags(trim($_POST['description'] ?? '')), 0, 800);
        $contactName = mb_substr(strip_tags(trim($_POST['contact_name'] ?? '')), 0, 60);
        $contactPhone = normalizePhone(trim($_POST['contact_phone'] ?? ''));
        if (strlen($contactPhone) < 12) $contactPhone = $userPhone;

        if (!$city || !isset(CITIES[$city])) {
            $error = 'אנא בחר עיר.';
            $step  = 'publish_form';
        } elseif (!$aptType || !isset(APT_TYPES[$aptType])) {
            $error = 'אנא בחר סוג דירה.';
            $step  = 'publish_form';
        } elseif (!$rentalType || !isset(RENTAL_TYPES[$rentalType])) {
            $error = 'אנא בחר סוג השכרה.';
            $step  = 'publish_form';
        } else {
            $aptId   = bin2hex(random_bytes(8));
            $expires = nextShabbatEnd();

            // תמונות
            $images = [];
            if (!empty($_FILES['images']['name'][0])) {
                $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
                $count   = min(count($_FILES['images']['name']), MAX_IMAGES);
                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK)      continue;
                    if ($_FILES['images']['size'][$i]  > MAX_IMG_BYTES)        continue;
                    $mime = mime_content_type($_FILES['images']['tmp_name'][$i]);
                    if (!in_array($mime, $allowed, true))                      continue;
                    $raw = resizeToJpeg($_FILES['images']['tmp_name'][$i], $mime);
                    if ($raw === false) continue;
                    $outMime  = function_exists('imagejpeg') ? 'image/jpeg' : $mime;
                    $images[] = 'data:' . $outMime . ';base64,' . base64_encode($raw);
                }
            }

            $apt = [
                'id'           => $aptId,
                'city'         => $city,
                'neighborhood' => $nh,
                'apt_type'     => $aptType,
                'beds'         => $beds,
                'bedrooms'     => $bedrooms,
                'rental_type'  => $rentalType,
                'price'        => $price,
                'floor'        => $floor,
                'features'     => $features,
                'description'  => $description,
                'contact_name' => $contactName,
                'owner_phone'  => $contactPhone,
                'pub_phone'    => $userPhone,
                'has_images'   => !empty($images),
                'image_count'  => count($images),
                'expires'      => $expires,
                'created'      => time(),
            ];

            if (!empty($images)) {
                saveAptImages($aptId, $images);
            }

            $all   = getAllApts();
            $all[] = $apt;
            saveApts($all);

            $_SESSION['flash'] = 'success:הדירה פורסמה בהצלחה! היא תהיה זמינה עד סוף השבת הקרובה.';
            header('Location: publish.php?step=my_listings');
            exit;
        }
    }

    // ── 4. מחיקת דירה
    elseif ($action === 'delete_apt' && $userPhone) {
        $aptId = trim($_POST['apt_id'] ?? '');
        if ($aptId) {
            $all = array_values(array_filter(getAllApts(), function ($a) use ($aptId, $userPhone) {
                return !($a['id'] === $aptId && ($a['pub_phone'] ?? '') === $userPhone);
            }));
            saveApts($all);
            delAptImages($aptId);
            $_SESSION['flash'] = 'info:הדירה הוסרה מהמאגר.';
        }
        header('Location: publish.php?step=my_listings');
        exit;
    }
}

// ── Flash ──────────────────────────────────────────────────────
if (isset($_SESSION['flash'])) {
    [$flashType, $flashMsg] = explode(':', $_SESSION['flash'], 2);
    unset($_SESSION['flash']);
    if ($flashType === 'success') $success = $flashMsg;
    else                          $success  = $flashMsg;
}

// ── My listings ────────────────────────────────────────────────
$myListings = [];
if ($userPhone && $step === 'my_listings') {
    $now = time();
    $all = getAllApts();
    $myListings = array_values(array_filter($all, fn($a) => ($a['pub_phone'] ?? '') === $userPhone));
}

// ── CITIES JSON for JS ─────────────────────────────────────────
$citiesJson = json_encode(CITIES, JSON_UNESCAPED_UNICODE);
$nhJson     = json_encode(NEIGHBORHOODS, JSON_UNESCAPED_UNICODE);

?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>פרסום דירה לשבוע הקרוב | מאגרים</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Assistant:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --primary:   #2563eb;
    --primary-d: #1d4ed8;
    --success:   #16a34a;
    --danger:    #dc2626;
    --warm:      #f59e0b;
    --bg:        #f1f5f9;
    --card-bg:   #ffffff;
    --radius:    16px;
  }
  * { box-sizing: border-box; }
  body {
    background: var(--bg);
    font-family: 'Assistant', Arial, sans-serif;
    font-size: 1rem;
    min-height: 100vh;
  }
  /* Navbar */
  .site-nav {
    background: var(--primary);
    padding: 0.75rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .site-nav .brand {
    color: #fff;
    font-weight: 700;
    font-size: 1.15rem;
    text-decoration: none;
  }
  .site-nav .nav-right { display: flex; gap: .5rem; align-items: center; }

  /* Cards */
  .card {
    border: none;
    border-radius: var(--radius);
    box-shadow: 0 2px 16px rgba(0,0,0,.08);
    background: var(--card-bg);
  }
  .card-icon { font-size: 2.5rem; }

  /* Hero */
  .hero {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    color: #fff;
    padding: 3rem 1.5rem 2.5rem;
    text-align: center;
    margin-bottom: 2rem;
  }
  .hero h1 { font-size: 2rem; font-weight: 700; margin-bottom: .5rem; }
  .hero p  { opacity: .9; font-size: 1.1rem; }

  /* Step title */
  .step-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 1.5rem;
  }

  /* Form */
  .form-label    { font-weight: 600; color: #374151; margin-bottom: .35rem; }
  .form-control, .form-select {
    border-radius: 10px;
    border: 1.5px solid #d1d5db;
    padding: .55rem .85rem;
    font-size: .97rem;
    transition: border-color .2s;
  }
  .form-control:focus, .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.15);
  }
  textarea.form-control { resize: vertical; min-height: 90px; }

  /* Buttons */
  .btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    border-radius: 10px;
    font-weight: 600;
    padding: .6rem 1.5rem;
  }
  .btn-primary:hover { background: var(--primary-d); border-color: var(--primary-d); }
  .btn-success { border-radius: 10px; font-weight: 600; }
  .btn-outline-danger { border-radius: 8px; }

  /* Feature checkboxes */
  .feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: .5rem;
  }
  .feature-item {
    display: flex;
    align-items: center;
    gap: .45rem;
    padding: .45rem .75rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all .15s;
    user-select: none;
    font-size: .9rem;
  }
  .feature-item:hover { border-color: var(--primary); background: #eff6ff; }
  .feature-item input[type=checkbox]:checked + span { color: var(--primary); font-weight: 600; }
  .feature-item:has(input:checked) { border-color: var(--primary); background: #eff6ff; }

  /* Image upload */
  .img-drop-zone {
    border: 2px dashed #94a3b8;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
    background: #f8fafc;
  }
  .img-drop-zone:hover, .img-drop-zone.drag-over {
    border-color: var(--primary);
    background: #eff6ff;
  }
  .img-previews {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-top: .75rem;
  }
  .img-preview-wrap {
    position: relative;
    width: 100px;
    height: 80px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid #e2e8f0;
  }
  .img-preview-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .img-remove-btn {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background: rgba(220,38,38,.85);
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: .65rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    line-height: 1;
  }

  /* Listing card */
  .listing-card {
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
    transition: box-shadow .2s;
  }
  .listing-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.1); }
  .listing-card .lc-img {
    height: 160px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    overflow: hidden;
  }
  .listing-card .lc-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .listing-card .lc-body { padding: 1rem; }
  .badge-type { font-size: .75rem; border-radius: 6px; }

  /* OTP input */
  .otp-inputs { display: flex; gap: .5rem; justify-content: center; }
  .otp-inputs input {
    width: 52px;
    height: 58px;
    text-align: center;
    font-size: 1.5rem;
    font-weight: 700;
    border-radius: 10px;
    border: 2px solid #d1d5db;
  }
  .otp-inputs input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }

  /* Phone display badge */
  .phone-badge {
    background: #eff6ff;
    color: var(--primary);
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: .25rem .75rem;
    font-weight: 600;
    font-size: .9rem;
  }

  /* Dashboard cards */
  .dash-option {
    border: 2px solid #e2e8f0;
    border-radius: var(--radius);
    padding: 2rem 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    color: inherit;
    display: block;
  }
  .dash-option:hover {
    border-color: var(--primary);
    background: #eff6ff;
    color: var(--primary);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37,99,235,.15);
  }
  .dash-option .option-icon { font-size: 2.5rem; margin-bottom: .75rem; }
  .dash-option h5 { font-weight: 700; margin-bottom: .25rem; }

  /* Alert */
  .alert { border-radius: 12px; }

  /* Responsive */
  @media (max-width: 576px) {
    .hero h1 { font-size: 1.5rem; }
    .otp-inputs input { width: 42px; height: 48px; font-size: 1.25rem; }
    .feature-grid { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<!-- ═══════════ NAV ═══════════ -->
<nav class="site-nav">
  <a href="publish.php" class="brand">🏠 מאגרים – דירות לשבת</a>
  <div class="nav-right">
    <?php if ($userPhone): ?>
      <span class="phone-badge"><?= htmlspecialchars(dispPhone($userPhone)) ?></span>
      <a href="?logout" class="btn btn-sm btn-outline-light">יציאה</a>
    <?php else: ?>
      <a href="?step=login" class="btn btn-sm btn-light">כניסה / הרשמה</a>
    <?php endif; ?>
  </div>
</nav>

<div class="container py-4" style="max-width:780px">

<?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible mb-3">
    <strong>שגיאה:</strong> <?= htmlspecialchars($error) ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success alert-dismissible mb-3">
    ✅ <?= htmlspecialchars($success) ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     HOME
════════════════════════════════════════════════════════════ -->
<?php if ($step === 'home'): ?>

<div class="hero rounded-4 mb-4">
  <div style="font-size:3rem">🏡</div>
  <h1>פרסום דירה לשבוע הקרוב</h1>
  <p>פרסם את הדירה שלך בקלות ומהירות – עם אזור אישי, תמונות ומעקב</p>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <a href="?step=login" class="dash-option">
      <div class="option-icon">📢</div>
      <h5>פרסום דירה חדשה</h5>
      <p class="text-muted small mb-0">פרסם דירה להשכרה לשבת הקרובה</p>
    </a>
  </div>
  <div class="col-md-6">
    <a href="?step=login" class="dash-option">
      <div class="option-icon">👤</div>
      <h5>האזור האישי שלי</h5>
      <p class="text-muted small mb-0">נהל את הפרסומים שלך</p>
    </a>
  </div>
</div>

<div class="card p-4 mt-4">
  <h6 class="fw-bold mb-3">📋 מה צריך לפרסם?</h6>
  <div class="row g-2">
    <?php
    $steps_list = [
      ['📱','אימות מספר טלפון'],
      ['🏠','פרטי הדירה'],
      ['📍','עיר ושכונה'],
      ['💰','מחיר וסוג השכרה'],
      ['📝','תיאור ופיצ\'רים'],
      ['🖼️','העלאת תמונות (עד 4)'],
    ];
    foreach ($steps_list as [$icon, $txt]): ?>
    <div class="col-6 col-md-4">
      <div class="d-flex align-items-center gap-2 p-2 bg-light rounded-3">
        <span><?= $icon ?></span>
        <small class="fw-500"><?= $txt ?></small>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     LOGIN – הכנסת מספר טלפון
════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'login'): ?>

<div class="card p-4" style="max-width:420px;margin:0 auto">
  <div class="text-center mb-4">
    <div class="card-icon">📱</div>
    <h4 class="fw-bold mt-2">כניסה עם מספר טלפון</h4>
    <p class="text-muted small">נשלח לך קוד אימות ב-SMS</p>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="send_otp">
    <input type="hidden" name="step"   value="login">
    <div class="mb-3">
      <label class="form-label">מספר טלפון נייד</label>
      <input type="tel" name="phone" class="form-control text-center fs-5 fw-bold"
             placeholder="050-000-0000" dir="ltr" autofocus required
             inputmode="tel" maxlength="15">
      <div class="form-text text-center">מספר ישראלי בלבד (050/052/054/058...)</div>
    </div>
    <button type="submit" class="btn btn-primary w-100 btn-lg">שלח קוד אימות</button>
  </form>
</div>


<!-- ═══════════════════════════════════════════════════════════
     VERIFY OTP
════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'verify'): ?>

<?php $otpPhone = $_SESSION['otp_phone'] ?? ''; ?>
<div class="card p-4" style="max-width:420px;margin:0 auto">
  <div class="text-center mb-4">
    <div class="card-icon">🔐</div>
    <h4 class="fw-bold mt-2">הכנס קוד אימות</h4>
    <?php if ($otpPhone): ?>
      <p class="text-muted small">קוד נשלח ל-<strong><?= htmlspecialchars(dispPhone($otpPhone)) ?></strong></p>
    <?php endif; ?>
  </div>

  <form method="post" id="otp-form">
    <input type="hidden" name="action" value="verify_otp">
    <input type="hidden" name="step"   value="verify">
    <div class="mb-4">
      <div class="otp-inputs" id="otp-inputs">
        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" data-idx="0" autofocus>
        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" data-idx="1">
        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" data-idx="2">
        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" data-idx="3">
        <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" data-idx="4">
      </div>
      <input type="hidden" name="code" id="otp-hidden">
    </div>
    <button type="submit" class="btn btn-primary w-100 btn-lg" id="otp-submit" disabled>אמת קוד</button>
  </form>

  <hr class="my-3">
  <div class="text-center">
    <form method="post">
      <input type="hidden" name="action" value="send_otp">
      <input type="hidden" name="phone"  value="<?= htmlspecialchars($otpPhone) ?>">
      <button class="btn btn-sm btn-link text-muted">לא קיבלת? שלח שוב</button>
    </form>
    <a href="?step=login" class="btn btn-sm btn-link text-muted">שנה מספר טלפון</a>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     DASHBOARD
════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'dashboard' && $userPhone): ?>

<div class="card p-4 mb-4" style="background:linear-gradient(135deg,#eff6ff,#dbeafe)">
  <div class="d-flex align-items-center gap-3">
    <div style="font-size:2.5rem">👋</div>
    <div>
      <h5 class="fw-bold mb-0">שלום! <?= htmlspecialchars(dispPhone($userPhone)) ?></h5>
      <small class="text-muted">מה תרצה לעשות היום?</small>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <a href="?step=publish_form" class="dash-option">
      <div class="option-icon">➕</div>
      <h5>פרסם דירה חדשה</h5>
      <p class="text-muted small mb-0">פרסום לשבת הקרובה</p>
    </a>
  </div>
  <div class="col-md-6">
    <a href="?step=my_listings" class="dash-option">
      <div class="option-icon">📋</div>
      <h5>הפרסומים שלי</h5>
      <p class="text-muted small mb-0">צפה ונהל את הדירות שפרסמת</p>
    </a>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     PUBLISH FORM
════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'publish_form' && $userPhone): ?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="?step=dashboard" class="text-muted text-decoration-none">← חזרה</a>
</div>
<h4 class="step-title">📢 פרסום דירה לשבוע הקרוב</h4>

<form method="post" enctype="multipart/form-data" id="publish-form">
  <input type="hidden" name="action" value="publish">
  <input type="hidden" name="step"   value="publish_form">

  <!-- ── מיקום ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">📍 מיקום הדירה</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">עיר <span class="text-danger">*</span></label>
        <select name="city" id="city-select" class="form-select" required
                onchange="updateNeighborhoods()">
          <option value="">בחר עיר...</option>
          <?php foreach (CITIES as $cid => $cname): ?>
            <option value="<?= $cid ?>"><?= htmlspecialchars($cname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">שכונה</label>
        <select name="neighborhood" id="nh-select" class="form-select">
          <option value="0">בחר שכונה (אופציונלי)</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">קומה</label>
        <input type="text" name="floor" class="form-control" placeholder='למשל: 3, קרקע, גג'>
      </div>
    </div>
  </div>

  <!-- ── פרטי הדירה ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">🏠 פרטי הדירה</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">סוג הדירה <span class="text-danger">*</span></label>
        <select name="apt_type" class="form-select" required>
          <option value="">בחר סוג...</option>
          <?php foreach (APT_TYPES as $tid => $tname): ?>
            <option value="<?= $tid ?>"><?= htmlspecialchars($tname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">חדרי שינה</label>
        <select name="bedrooms" class="form-select">
          <option value="0">סטודיו</option>
          <?php for ($i = 1; $i <= 8; $i++): ?>
            <option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= $i ?> חד'</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">מיטות</label>
        <select name="beds" class="form-select">
          <?php for ($i = 1; $i <= 12; $i++): ?>
            <option value="<?= $i ?>" <?= $i === 4 ? 'selected' : '' ?>><?= $i ?> מיטות</option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- ── השכרה ומחיר ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">💰 השכרה ומחיר</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">סוג ההשכרה <span class="text-danger">*</span></label>
        <select name="rental_type" class="form-select" required>
          <?php foreach (RENTAL_TYPES as $rid => $rname): ?>
            <option value="<?= $rid ?>"><?= htmlspecialchars($rname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">מחיר ל-24 שעות (₪)</label>
        <div class="input-group">
          <span class="input-group-text">₪</span>
          <input type="number" name="price" class="form-control" min="0" max="99999"
                 placeholder="0 = לא צוין" value="0">
        </div>
      </div>
    </div>
  </div>

  <!-- ── פיצ'רים ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">✨ מה יש בדירה?</h6>
    <div class="feature-grid">
      <?php
      $features = [
        'parking'      => '🅿️ חניה',
        'ac'           => '❄️ מיזוג',
        'elevator'     => '🛗 מעלית',
        'balcony'      => '🌿 מרפסת',
        'wifi'         => '📶 WiFi',
        'washing'      => '🫧 מכונת כביסה',
        'dishwasher'   => '🍽️ מדיח כלים',
        'bathtub'      => '🛁 אמבטיה',
        'shabbat_mode' => '🕯️ מצב שבת',
        'crib'         => '👶 עריסה',
        'synagogue'    => '🕍 בית כנסת קרוב',
        'garden'       => '🌳 גינה',
        'handicapped'  => '♿ נגיש',
        'quiet'        => '🔇 שקט',
      ];
      foreach ($features as $key => $label): ?>
      <label class="feature-item">
        <input type="checkbox" name="features[]" value="<?= $key ?>">
        <span><?= $label ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── תיאור ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">📝 תיאור חופשי</h6>
    <textarea name="description" class="form-control" rows="4" maxlength="800"
      placeholder="ספר על הדירה: מיקום, נוחות, קרבה למוסדות, כל מה שחשוב לדעת..."></textarea>
    <div class="form-text text-end" id="desc-counter">0 / 800</div>
  </div>

  <!-- ── תמונות ── -->
  <div class="card p-4 mb-3">
    <h6 class="fw-bold mb-3">🖼️ תמונות (עד <?= MAX_IMAGES ?>)</h6>
    <div class="img-drop-zone" id="drop-zone" onclick="document.getElementById('img-input').click()">
      <div style="font-size:2rem">📷</div>
      <div class="fw-bold mt-1">לחץ להוספת תמונות</div>
      <div class="text-muted small mt-1">JPG / PNG / WebP – עד 3MB לתמונה</div>
    </div>
    <input type="file" name="images[]" id="img-input" multiple accept="image/*"
           style="display:none" onchange="handleFiles(this.files)">
    <div class="img-previews" id="img-previews"></div>
  </div>

  <!-- ── פרטי יצירת קשר ── -->
  <div class="card p-4 mb-4">
    <h6 class="fw-bold mb-3">📞 פרטי יצירת קשר</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">שם איש הקשר</label>
        <input type="text" name="contact_name" class="form-control"
               placeholder="שם מלא (אופציונלי)" maxlength="60">
      </div>
      <div class="col-md-6">
        <label class="form-label">טלפון ליצירת קשר</label>
        <input type="tel" name="contact_phone" class="form-control" dir="ltr"
               placeholder="<?= htmlspecialchars(dispPhone($userPhone)) ?>"
               value="<?= htmlspecialchars(dispPhone($userPhone)) ?>">
        <div class="form-text">השאר ריק להשתמש במספר שאימתת</div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-success btn-lg w-100 mb-4">
    🚀 פרסם דירה עכשיו
  </button>
</form>


<!-- ═══════════════════════════════════════════════════════════
     MY LISTINGS
════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 'my_listings' && $userPhone): ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="step-title mb-0">📋 הפרסומים שלי</h4>
  <a href="?step=publish_form" class="btn btn-primary btn-sm">+ פרסם דירה חדשה</a>
</div>
<a href="?step=dashboard" class="text-muted text-decoration-none d-block mb-3">← חזרה לדשבורד</a>

<?php if (empty($myListings)): ?>
  <div class="card p-5 text-center">
    <div style="font-size:3rem">🏚️</div>
    <h5 class="mt-3 mb-1">אין לך פרסומים פעילים</h5>
    <p class="text-muted">פרסם את הדירה שלך לשבת הקרובה</p>
    <a href="?step=publish_form" class="btn btn-primary">פרסם עכשיו</a>
  </div>
<?php else: ?>

<div class="row g-3">
<?php foreach ($myListings as $apt):
    $expired   = ($apt['expires'] ?? 0) <= time();
    $expDate   = date('d/m/Y H:i', $apt['expires'] ?? 0);
    $images    = $apt['has_images'] ? getAptImages($apt['id']) : [];
    $firstImg  = $images[0] ?? null;
    $features  = $apt['features'] ?? [];
    $featureLabels = [
      'parking'=>'חניה','ac'=>'מיזוג','elevator'=>'מעלית','balcony'=>'מרפסת',
      'wifi'=>'WiFi','washing'=>'כביסה','dishwasher'=>'מדיח','bathtub'=>'אמבטיה',
      'shabbat_mode'=>'מצב שבת','crib'=>'עריסה','synagogue'=>'ביהכ"נ','garden'=>'גינה',
      'handicapped'=>'נגיש','quiet'=>'שקט',
    ];
?>
<div class="col-md-6">
  <div class="listing-card <?= $expired ? 'opacity-60' : '' ?>">
    <div class="lc-img">
      <?php if ($firstImg): ?>
        <img src="<?= htmlspecialchars($firstImg) ?>" alt="תמונת דירה">
      <?php else: ?>
        🏠
      <?php endif; ?>
    </div>
    <div class="lc-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <strong><?= htmlspecialchars(locationStr($apt)) ?></strong>
        <?php if ($expired): ?>
          <span class="badge bg-danger badge-type">פג תוקף</span>
        <?php else: ?>
          <span class="badge bg-success badge-type">פעיל</span>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-wrap gap-1 mb-2">
        <span class="badge bg-secondary"><?= htmlspecialchars(aptTypeName($apt['apt_type'])) ?></span>
        <span class="badge bg-light text-dark border"><?= $apt['bedrooms'] == 0 ? 'סטודיו' : $apt['bedrooms'] . ' חד\'' ?></span>
        <span class="badge bg-light text-dark border"><?= $apt['beds'] ?> 🛏</span>
        <?php if ($apt['price'] > 0): ?>
          <span class="badge bg-warning text-dark">₪<?= number_format($apt['price']) ?></span>
        <?php endif; ?>
      </div>

      <div class="small text-muted mb-2">
        <?= htmlspecialchars(rentalName($apt['rental_type'])) ?>
        <?php if (!empty($apt['floor'])): ?>
          · קומה <?= htmlspecialchars($apt['floor']) ?>
        <?php endif; ?>
      </div>

      <?php if (!empty($features)): ?>
      <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach (array_slice($features, 0, 5) as $f): ?>
          <span class="badge bg-info text-dark" style="font-size:.7rem"><?= htmlspecialchars($featureLabels[$f] ?? $f) ?></span>
        <?php endforeach; ?>
        <?php if (count($features) > 5): ?>
          <span class="badge bg-light text-muted" style="font-size:.7rem">+<?= count($features) - 5 ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($apt['description'])): ?>
        <p class="small text-muted mb-2" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
          <?= htmlspecialchars($apt['description']) ?>
        </p>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mt-2">
        <small class="text-muted">
          <?= $expired ? '❌ פג' : '✅ תקף' ?> עד <?= $expDate ?>
          <?php if (!empty($apt['image_count'])): ?>
            · <?= $apt['image_count'] ?> 📷
          <?php endif; ?>
        </small>
        <form method="post" onsubmit="return confirm('האם להסיר פרסום זה?')">
          <input type="hidden" name="action"  value="delete_apt">
          <input type="hidden" name="apt_id"  value="<?= htmlspecialchars($apt['id']) ?>">
          <input type="hidden" name="step"    value="my_listings">
          <button class="btn btn-sm btn-outline-danger">הסר</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     NOT LOGGED IN – redirect to login
════════════════════════════════════════════════════════════ -->
<?php elseif (in_array($step, ['publish_form','my_listings','dashboard']) && !$userPhone): ?>

<div class="card p-5 text-center" style="max-width:420px;margin:0 auto">
  <div style="font-size:3rem">🔒</div>
  <h5 class="mt-3 mb-1">נדרשת כניסה</h5>
  <p class="text-muted">יש להתחבר עם מספר הטלפון שלך</p>
  <a href="?step=login" class="btn btn-primary">כניסה</a>
</div>

<?php endif; ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Neighborhoods ──
const NEIGHBORHOODS = <?= $nhJson ?>;
function updateNeighborhoods() {
  const city = parseInt(document.getElementById('city-select')?.value);
  const sel  = document.getElementById('nh-select');
  if (!sel) return;
  sel.innerHTML = '<option value="0">בחר שכונה (אופציונלי)</option>';
  const nhs = NEIGHBORHOODS[city] || {};
  Object.entries(nhs).forEach(([id, name]) => {
    const o = document.createElement('option');
    o.value = id;
    o.textContent = name;
    sel.appendChild(o);
  });
}

// ── OTP ──
(function () {
  const digits  = document.querySelectorAll('.otp-digit');
  const hidden  = document.getElementById('otp-hidden');
  const submit  = document.getElementById('otp-submit');
  if (!digits.length) return;

  digits.forEach((inp, i) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\D/, '');
      if (inp.value && i < digits.length - 1) digits[i + 1].focus();
      syncOTP();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !inp.value && i > 0) digits[i - 1].focus();
    });
    inp.addEventListener('paste', e => {
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      if (pasted.length >= digits.length) {
        digits.forEach((d, j) => d.value = pasted[j] || '');
        syncOTP();
        e.preventDefault();
      }
    });
  });

  function syncOTP() {
    const val = Array.from(digits).map(d => d.value).join('');
    if (hidden) hidden.value = val;
    if (submit) submit.disabled = val.length < 5;
  }
})();

// ── Description counter ──
const descTA = document.querySelector('[name=description]');
const descC  = document.getElementById('desc-counter');
if (descTA && descC) {
  descTA.addEventListener('input', () => {
    descC.textContent = descTA.value.length + ' / 800';
  });
}

// ── Image upload ──
const MAX_IMAGES = <?= MAX_IMAGES ?>;
const MAX_SIZE   = <?= MAX_IMG_BYTES ?>;
let   imgFiles   = [];

function handleFiles(files) {
  Array.from(files).forEach(f => {
    if (imgFiles.length >= MAX_IMAGES) return;
    if (f.size > MAX_SIZE) { alert('קובץ ' + f.name + ' גדול מדי (מקסימום 3MB)'); return; }
    if (!f.type.startsWith('image/')) return;
    imgFiles.push(f);
  });
  renderPreviews();
  updateFileInput();
}

function renderPreviews() {
  const wrap = document.getElementById('img-previews');
  if (!wrap) return;
  wrap.innerHTML = '';
  imgFiles.forEach((f, i) => {
    const div = document.createElement('div');
    div.className = 'img-preview-wrap';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'img-remove-btn';
    btn.textContent = '✕';
    btn.onclick = () => { imgFiles.splice(i, 1); renderPreviews(); updateFileInput(); };
    div.appendChild(img);
    div.appendChild(btn);
    wrap.appendChild(div);
  });
  const dz = document.getElementById('drop-zone');
  if (dz) dz.style.display = imgFiles.length >= MAX_IMAGES ? 'none' : '';
}

function updateFileInput() {
  const inp  = document.getElementById('img-input');
  if (!inp) return;
  const dt = new DataTransfer();
  imgFiles.forEach(f => dt.items.add(f));
  inp.files = dt.files;
}

// Drag & drop
const dz = document.getElementById('drop-zone');
if (dz) {
  dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('drag-over'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
  dz.addEventListener('drop',      e => {
    e.preventDefault();
    dz.classList.remove('drag-over');
    handleFiles(e.dataTransfer.files);
  });
}
</script>
</body>
</html>
