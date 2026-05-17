<?php
/**
 * לוח ניהול – מערכת IVR דירות לשבת
 */

require_once __DIR__ . '/lib.php';

function getActiveApts(): array {
    $now = time();
    return array_values(array_filter(getAllApts(), fn($a) => ($a['expires'] ?? 0) > $now));
}

function isExpired(array $a): bool { return ($a['expires'] ?? 0) <= time(); }

// ── Session & Auth ─────────────────────────────────────────────

session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['admin'])) {
    if (hash_equals(ADMIN_PASS, $_POST['pass'] ?? '')) {
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = 'סיסמה שגויה. נסה שוב.';
}

// ── Actions (require login) ────────────────────────────────────

$flash = '';

if (isset($_SESSION['admin'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_id'])) {
        $did = $_POST['delete_id'];
        $all = array_values(array_filter(getAllApts(), fn($a) => $a['id'] !== $did));
        saveApts($all);
        $_SESSION['flash'] = 'הדירה נמחקה בהצלחה.';
        header('Location: admin.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup'])) {
        $active = getActiveApts();
        saveApts($active);
        $_SESSION['flash'] = 'ניקוי הושלם. ' . count($active) . ' דירות פעילות נשארו.';
        header('Location: admin.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_expired'])) {
        $active = getActiveApts();
        saveApts($active);
        $_SESSION['flash'] = 'כל הפרסומים הפגי תוקף נמחקו.';
        header('Location: admin.php');
        exit;
    }

    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
}

// ── Data for dashboard ─────────────────────────────────────────

$loggedIn = isset($_SESSION['admin']);
$allApts  = $loggedIn ? getAllApts() : [];
$active   = array_values(array_filter($allApts, fn($a) => !isExpired($a)));
$expired  = array_values(array_filter($allApts, fn($a) =>  isExpired($a)));
$total    = count($active);

$cityCounts = $typeCounts = $rentCounts = [];
$prices = [];

foreach ($active as $a) {
    $cityCounts[$a['city']]        = ($cityCounts[$a['city']]        ?? 0) + 1;
    $typeCounts[$a['apt_type']]    = ($typeCounts[$a['apt_type']]    ?? 0) + 1;
    $rentCounts[$a['rental_type']] = ($rentCounts[$a['rental_type']] ?? 0) + 1;
    if ($a['price'] > 0) $prices[] = $a['price'];
}

arsort($cityCounts);
arsort($typeCounts);

$avgPrice = !empty($prices) ? round(array_sum($prices) / count($prices)) : 0;
$minPrice = !empty($prices) ? min($prices) : 0;
$maxPrice = !empty($prices) ? max($prices) : 0;

// Filter
$filterCity  = isset($_GET['city']) ? intval($_GET['city']) : 0;
$filterType  = isset($_GET['type']) ? intval($_GET['type']) : 0;
$showExpired = isset($_GET['show_expired']);

$displayApts = $showExpired ? $allApts : $active;
if ($filterCity) $displayApts = array_values(array_filter($displayApts, fn($a) => $a['city'] == $filterCity));
if ($filterType) $displayApts = array_values(array_filter($displayApts, fn($a) => $a['apt_type'] == $filterType));

?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ניהול | קו דירות לשבת</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<style>
  body          { background: #f0f2f5; }
  .navbar-brand { font-weight: 700; letter-spacing: .3px; }
  .card         { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
  .stat-card    { border-top: 4px solid; }
  .c-blue   { border-color: #4e73df; }
  .c-green  { border-color: #1cc88a; }
  .c-orange { border-color: #f6c23e; }
  .c-red    { border-color: #e74a3b; }
  .bar      { height: 10px; border-radius: 5px; background: #4e73df; min-width: 4px; }
  .table th { background: #f8f9fa; font-weight: 600; }
  .badge-sm { font-size: .7rem; }
  .login-box{ max-width: 420px; margin: 8vh auto; }
  .expired-row { opacity: .55; }
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ══════════ LOGIN ══════════ -->
<div class="container">
  <div class="card p-4 login-box">
    <div class="text-center mb-4">
      <div style="font-size:2.5rem">🏠</div>
      <h5 class="fw-bold mt-2">ניהול קו דירות לשבת</h5>
    </div>
    <?php if ($loginError): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">סיסמת ניהול</label>
        <input type="password" name="pass" class="form-control" autofocus required placeholder="הכנס סיסמה">
      </div>
      <button type="submit" class="btn btn-primary w-100">כניסה למערכת</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════ DASHBOARD ══════════ -->

<nav class="navbar navbar-dark bg-dark px-3 py-2 mb-4">
  <span class="navbar-brand">🏠 ניהול קו דירות לשבת</span>
  <div class="d-flex align-items-center gap-2">
    <form method="post" class="d-inline">
      <button name="cleanup" class="btn btn-sm btn-warning">ניקוי ידני</button>
    </form>
    <a href="?logout" class="btn btn-sm btn-outline-light">יציאה</a>
  </div>
</nav>

<div class="container-fluid px-4">

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible py-2 mb-3">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- ── Stats ── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="card p-3 stat-card c-blue">
        <div class="text-muted small mb-1">דירות פעילות</div>
        <div class="fs-1 fw-bold text-primary"><?= $total ?></div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card p-3 stat-card c-green">
        <div class="text-muted small mb-1">מחיר ממוצע ללילה</div>
        <div class="fs-1 fw-bold text-success"><?= $avgPrice > 0 ? '₪' . number_format($avgPrice) : '—' ?></div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card p-3 stat-card c-orange">
        <div class="text-muted small mb-1">טווח מחירים</div>
        <div class="fs-4 fw-bold text-warning mt-1">
          <?= $minPrice > 0 ? '₪' . number_format($minPrice) . ' – ₪' . number_format($maxPrice) : '—' ?>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card p-3 stat-card c-red">
        <div class="text-muted small mb-1">פרסומים פגי תוקף</div>
        <div class="fs-1 fw-bold text-danger"><?= count($expired) ?></div>
        <?php if (count($expired) > 0): ?>
        <form method="post" class="mt-2">
          <button name="delete_all_expired" class="btn btn-sm btn-outline-danger w-100"
            onclick="return confirm('למחוק את כל הפרסומים הפגי תוקף?')">מחק הכל</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Charts ── -->
  <div class="row g-3 mb-4">
    <div class="col-md-5">
      <div class="card p-3 h-100">
        <h6 class="fw-bold mb-3">📍 פילוג לפי עיר</h6>
        <?php if (empty($cityCounts)): ?>
          <p class="text-muted">אין נתונים</p>
        <?php else: $maxC = max($cityCounts); foreach ($cityCounts as $cid => $cnt): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:110px;font-size:.85rem"><?= htmlspecialchars(cityName($cid)) ?></div>
            <div class="bar flex-grow-1" style="width:<?= round($cnt / $maxC * 100) ?>%"></div>
            <span class="badge bg-primary badge-sm"><?= $cnt ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold mb-3">🏠 סוג דירה</h6>
        <?php foreach ($typeCounts as $tid => $cnt): ?>
          <div class="d-flex justify-content-between mb-1">
            <small><?= htmlspecialchars(aptTypeName($tid)) ?></small>
            <span class="badge bg-success badge-sm"><?= $cnt ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 h-100">
        <h6 class="fw-bold mb-3">📅 זמן השכרה</h6>
        <?php foreach ($rentCounts as $rid => $cnt): ?>
          <div class="d-flex justify-content-between mb-1">
            <small><?= htmlspecialchars(rentalName($rid)) ?></small>
            <span class="badge bg-info badge-sm"><?= $cnt ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Filter bar ── -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label small mb-1">סינון עיר</label>
        <select name="city" class="form-select form-select-sm">
          <option value="0">כל הערים</option>
          <?php foreach (CITIES as $cid => $cn): ?>
            <option value="<?= $cid ?>" <?= $filterCity == $cid ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">סינון סוג</label>
        <select name="type" class="form-select form-select-sm">
          <option value="0">כל הסוגים</option>
          <?php foreach (APT_TYPES as $tid => $tn): ?>
            <option value="<?= $tid ?>" <?= $filterType == $tid ? 'selected' : '' ?>><?= htmlspecialchars($tn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="show_expired" id="se" <?= $showExpired ? 'checked' : '' ?>>
          <label class="form-check-label small" for="se">הצג גם פגי תוקף</label>
        </div>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">סנן</button>
        <a href="admin.php" class="btn btn-outline-secondary btn-sm">נקה</a>
      </div>
      <div class="col-auto me-auto">
        <input type="text" id="live-search" class="form-control form-control-sm" placeholder="חיפוש חופשי...">
      </div>
    </form>
  </div>

  <!-- ── Table ── -->
  <div class="card mb-5">
    <div class="card-header py-2">
      <strong>רשימת דירות</strong>
      <span class="badge bg-secondary ms-2"><?= count($displayApts) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="main-table">
        <thead>
          <tr>
            <th>#</th>
            <th>מיקום</th>
            <th>סוג</th>
            <th>מיטות / חד'</th>
            <th>מחיר</th>
            <th>השכרה</th>
            <th>טלפון</th>
            <th>תאריך פרסום</th>
            <th>תוקף עד</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($displayApts)): ?>
            <tr><td colspan="10" class="text-center text-muted py-5">אין דירות להצגה</td></tr>
          <?php endif; ?>
          <?php foreach ($displayApts as $i => $a):
            $exp      = isExpired($a);
            $rowClass = $exp ? 'expired-row' : '';
            $expTime  = date('d/m/Y H:i', $a['expires']);
            $pubTime  = date('d/m/Y', $a['created'] ?? 0);
            $urgent   = !$exp && ($a['expires'] - time() < 3600);
          ?>
          <tr class="<?= $rowClass ?>">
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars(locationStr($a)) ?></strong></td>
            <td>
              <span class="badge bg-<?= in_array($a['apt_type'], [7, 8]) ? 'warning text-dark' : 'secondary' ?> badge-sm">
                <?= htmlspecialchars(aptTypeName($a['apt_type'])) ?>
              </span>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><?= (int)$a['beds'] ?> 🛏</span>
              <span class="badge bg-light text-dark border"><?= (int)$a['bedrooms'] === 0 ? 'סטודיו' : (int)$a['bedrooms'] . ' חד\'' ?></span>
            </td>
            <td>
              <?php if ($a['price'] > 0): ?>
                <strong>₪<?= number_format($a['price']) ?></strong>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars(rentalName($a['rental_type'])) ?></small></td>
            <td dir="ltr" class="text-muted small"><?= htmlspecialchars($a['owner_phone']) ?></td>
            <td class="text-muted small"><?= $pubTime ?></td>
            <td class="small <?= $exp ? 'text-danger' : ($urgent ? 'text-warning fw-bold' : 'text-muted') ?>">
              <?= $expTime ?>
              <?php if ($exp): ?><span class="badge bg-danger badge-sm">פג</span><?php endif; ?>
            </td>
            <td>
              <form method="post" onsubmit="return confirm('למחוק דירה זו לצמיתות?')">
                <input type="hidden" name="delete_id" value="<?= htmlspecialchars($a['id']) ?>">
                <button class="btn btn-sm btn-outline-danger">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('live-search').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#main-table tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
</script>

<?php endif; ?>
</body>
</html>
