<?php
// ============================================================
// Glycotrack — Personal Diabetic Record Management System
// Developer: Vineet Pratap Singh
// GitHub: psvineet | Contact: connect.vps@icloud.com
// ============================================================
date_default_timezone_set('Asia/Kolkata'); // all dates/times in IST
session_start();

define('DATA_DIR', __DIR__ . '/data');
define('VAULT_DIR', __DIR__ . '/vault');
define('DB_PATH',   DATA_DIR . '/diabetic.db');
define('SHARE_SALT', 'gt_' . substr(md5(DB_PATH), 0, 16)); // per-install salt for public share links

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(VAULT_DIR)) mkdir(VAULT_DIR, 0755, true);

// ── DB ──────────────────────────────────────────────────────
function getDB() {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    return $db;
}

function initDB() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS patient (
        id INTEGER PRIMARY KEY,
        first_name TEXT, last_name TEXT,
        dob TEXT,
        sex TEXT,
        weight_kg REAL, height_ft INTEGER, height_in INTEGER, height_cm REAL,
        bmi REAL, bmi_category TEXT,
        diagnosis_date TEXT, symptoms TEXT,
        avg_sugar_at_diagnosis REAL, diabetes_type TEXT,
        doctor_name TEXT, doctor_contact TEXT,
        ketones TEXT, sugar_at_diagnosis REAL,
        target_low REAL DEFAULT 70, target_high REAL DEFAULT 180,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS glucose_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        level REAL NOT NULL,
        test_time TEXT,
        reading_type TEXT,
        meal_gap_minutes INTEGER,
        symptoms TEXT,
        mood TEXT,
        energy_level INTEGER,
        test_method TEXT,
        lab_name TEXT,
        notes TEXT,
        logged_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS vault_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT, original_name TEXT,
        file_type TEXT, report_type TEXT,
        report_date TEXT, report_time TEXT, notes TEXT,
        uploaded_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    // migrate older DBs that lack report_time
    $cols = $db->query("PRAGMA table_info(vault_files)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('report_time', $cols)) { $db->exec("ALTER TABLE vault_files ADD COLUMN report_time TEXT"); }
    $db->exec("CREATE TABLE IF NOT EXISTS journal (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry TEXT NOT NULL,
        category TEXT DEFAULT 'general',
        logged_at TEXT DEFAULT (datetime('now','localtime'))
    )");
}

// ── HELPERS ─────────────────────────────────────────────────
function calcAge($dob) {
    if (!$dob) return null;
    $d = new DateTime($dob);
    return (int)(new DateTime())->diff($d)->y;
}
function calcBMI($wkg, $hcm) {
    if (!$wkg || !$hcm) return null;
    $hm = $hcm / 100;
    return round($wkg / ($hm * $hm), 1);
}
function bmiCategory($bmi) {
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25)   return 'Normal';
    if ($bmi < 30)   return 'Overweight';
    return 'Obese';
}
function bmiColor($bmi) {
    if ($bmi < 18.5) return '#3498db';
    if ($bmi < 25)   return '#27ae60';
    if ($bmi < 30)   return '#e67e22';
    return '#c0392b';
}
function glucoseStatus($v, $p) {
    if (!$v) return ['Unknown', '#999'];
    if ($v < $p['target_low'])  return ['Low',     '#e67e22'];
    if ($v > $p['target_high']) return ['High',    '#c0392b'];
    return ['In Range', '#27ae60'];
}
function estimateA1C($avg) {
    if (!$avg) return null;
    return round((($avg + 46.7) / 28.7), 1);
}
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function redirect($url) { header('Location: ' . $url); exit; }
function vaultToken($id) { return substr(hash('sha256', $id . '|' . SHARE_SALT), 0, 24); }

// ── BOOTSTRAP ───────────────────────────────────────────────
$dbExists    = file_exists(DB_PATH);
$setupNeeded = false;
$authNeeded  = false;

if ($dbExists) {
    initDB();
    $db = getDB();
    $userRow    = $db->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $patientRow = $db->query("SELECT * FROM patient WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) { $setupNeeded = true; }
    else {
        if (empty($_SESSION['auth'])) { $authNeeded = true; }
    }
} else {
    $setupNeeded = true;
}

if (!$dbExists && !$setupNeeded) { $setupNeeded = true; }

$action = $_REQUEST['action'] ?? '';
$page   = $_GET['page'] ?? 'dashboard';

// ── PUBLIC VAULT ACCESS (token-based, no session needed) ──────
// Lets external services like Google Docs Viewer fetch the PDF directly.
if ($action === 'public_vault') {
    if (!$dbExists) { http_response_code(404); exit; }
    initDB();
    $db  = getDB();
    $id  = (int)($_GET['id'] ?? 0);
    $tok = $_GET['token'] ?? '';
    if (!$id || !hash_equals(vaultToken($id), $tok)) { http_response_code(403); exit; }
    $row = $db->prepare("SELECT * FROM vault_files WHERE id=?");
    $row->execute([$id]);
    $f = $row->fetch(PDO::FETCH_ASSOC);
    if ($f && file_exists(VAULT_DIR . '/' . $f['filename'])) {
        $mime = ($f['file_type'] === 'pdf') ? 'application/pdf' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $f['original_name'] . '"');
        header('Cache-Control: private, max-age=300');
        readfile(VAULT_DIR . '/' . $f['filename']); exit;
    }
    http_response_code(404); exit;
}

// ── AUTH ACTIONS ─────────────────────────────────────────────
if ($action === 'setup_account') {
    if (!$dbExists) initDB();
    initDB();
    $db = getDB();
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    $pass2 = $_POST['password2'];
    $err = '';
    if (strlen($user) < 3) $err = 'Username must be at least 3 characters.';
    elseif (strlen($pass) < 6) $err = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2) $err = 'Passwords do not match.';
    if ($err) { $_SESSION['setup_err'] = $err; redirect('?step=account'); }
    $db->prepare("INSERT OR IGNORE INTO users (username,password_hash) VALUES (?,?)")
       ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
    // Now setup patient
    $wkg  = floatval($_POST['weight_kg']);
    $hft  = intval($_POST['height_ft']);
    $hin  = intval($_POST['height_in']);
    $hcm  = round(($hft * 30.48) + ($hin * 2.54), 1);
    $bmi  = calcBMI($wkg, $hcm);
    $db->prepare("INSERT OR REPLACE INTO patient (id,first_name,last_name,dob,sex,weight_kg,height_ft,height_in,height_cm,bmi,bmi_category,diagnosis_date,symptoms,avg_sugar_at_diagnosis,diabetes_type,doctor_name,doctor_contact,ketones,sugar_at_diagnosis,target_low,target_high) VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$_POST['first_name'],$_POST['last_name'],$_POST['dob'],$_POST['sex'],$wkg,$hft,$hin,$hcm,$bmi,bmiCategory($bmi),$_POST['diagnosis_date'],$_POST['symptoms'],floatval($_POST['avg_sugar']),$_POST['diabetes_type'],$_POST['doctor_name'],$_POST['doctor_contact'],$_POST['ketones'],floatval($_POST['sugar_at_diagnosis']),floatval($_POST['target_low']),floatval($_POST['target_high'])]);
    $_SESSION['auth'] = true;
    $_SESSION['username'] = $user;
    redirect('?');
}

if ($action === 'login') {
    initDB();
    $db = getDB();
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    $row = $db->prepare("SELECT * FROM users WHERE username=?");
    $row->execute([$user]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($pass, $row['password_hash'])) {
        $_SESSION['auth'] = true;
        $_SESSION['username'] = $user;
        redirect('?');
    }
    $_SESSION['login_err'] = 'Invalid username or password.';
    redirect('?action=show_login');
}

if ($action === 'logout') {
    session_destroy();
    redirect('?action=show_login');
}

if ($action === 'change_password') {
    if (!empty($_SESSION['auth'])) {
        $db = getDB();
        $row = $db->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $cur = $_POST['current_password'];
        $new = $_POST['new_password'];
        $new2 = $_POST['new_password2'];
        if (!password_verify($cur, $row['password_hash'])) {
            $_SESSION['pw_err'] = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $_SESSION['pw_err'] = 'New password must be at least 6 characters.';
        } elseif ($new !== $new2) {
            $_SESSION['pw_err'] = 'New passwords do not match.';
        } else {
            $db->prepare("UPDATE users SET password_hash=?")->execute([password_hash($new, PASSWORD_DEFAULT)]);
            $_SESSION['pw_ok'] = true;
        }
    }
    redirect('?page=settings&tab=security');
}

// ── GATE: Must be authenticated ──────────────────────────────
if (!$setupNeeded && (empty($_SESSION['auth']) || $authNeeded) && $action !== 'login') {
    // show login
    $loginErr = $_SESSION['login_err'] ?? '';
    unset($_SESSION['login_err']);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Glycotrack — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--cream:#fdf8f0;--gold:#b8860b;--navy:#1a2744;--border:#e8d5b0;--text:#2c2c2c;--muted:#6b6b6b;--radius:12px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Noto Sans',sans-serif;background:var(--cream);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.login-card{background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,39,68,0.13);width:100%;max-width:420px;overflow:hidden;}
.lc-head{background:var(--navy);padding:40px 36px 32px;text-align:center;}
.lc-head svg{display:block;margin:0 auto 16px;}
.lc-head h1{color:#fff;font-size:22px;font-weight:700;}
.lc-head p{color:rgba(255,255,255,0.55);font-size:13px;margin-top:6px;}
.gold-bar{width:40px;height:3px;background:var(--gold);border-radius:2px;margin:14px auto 0;}
.lc-body{padding:32px 36px;}
.form-group{margin-bottom:18px;}
label{display:block;font-size:13px;font-weight:600;color:var(--navy);margin-bottom:6px;}
input{width:100%;font-family:inherit;font-size:14px;padding:11px 14px;border:2px solid var(--border);border-radius:8px;background:var(--cream);color:var(--text);transition:border-color .2s;}
input:focus{outline:none;border-color:var(--gold);background:#fff;}
.btn{width:100%;padding:12px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s;}
.btn:hover{background:#243358;}
.err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px;}
.lc-foot{text-align:center;padding:16px 36px;border-top:1px solid var(--border);font-size:12px;color:var(--muted);}
.lc-foot a{color:var(--gold);text-decoration:none;}
</style>
</head>
<body>
<div class="login-card">
  <div class="lc-head">
    <svg width="44" height="44" viewBox="0 0 44 44" fill="none">
      <rect width="44" height="44" rx="12" fill="#b8860b"/>
      <path d="M22 10v24M10 22h24" stroke="#fff" stroke-width="3.5" stroke-linecap="round"/>
      <circle cx="22" cy="22" r="6" fill="none" stroke="#fff" stroke-width="2.5"/>
    </svg>
    <h1>Glycotrack</h1>
    <p>Sign in to access your health records</p>
    <div class="gold-bar"></div>
  </div>
  <div class="lc-body">
    <?php if ($loginErr): ?><div class="err"><?= h($loginErr) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required autocomplete="username" autofocus>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn">Sign In</button>
    </form>
  </div>
  <div class="lc-foot">Glycotrack by <a href="https://github.com/psvineet" target="_blank">Vineet Pratap Singh</a></div>
</div>
</body>
</html>
    <?php exit;
}

// ── SETUP FLOW ───────────────────────────────────────────────
if ($setupNeeded) {
    if (!$dbExists) initDB();
    $setupErr = $_SESSION['setup_err'] ?? '';
    unset($_SESSION['setup_err']);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Glycotrack — First-Time Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--cream:#fdf8f0;--cream-dark:#f5edd8;--border:#e8d5b0;--gold:#b8860b;--gold-pale:#fef3cd;--navy:#1a2744;--navy-mid:#243358;--text:#2c2c2c;--muted:#6b6b6b;--danger:#c0392b;--radius:12px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Noto Sans',sans-serif;background:var(--cream);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;}
.setup-box{background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,39,68,0.13);width:100%;max-width:860px;overflow:hidden;margin:auto;}
.setup-head{background:var(--navy);padding:36px;text-align:center;}
.setup-head svg{display:block;margin:0 auto 14px;}
.setup-head h1{color:#fff;font-size:24px;font-weight:700;}
.setup-head p{color:rgba(255,255,255,0.6);font-size:14px;margin-top:6px;}
.gold-bar{width:44px;height:3px;background:var(--gold);border-radius:2px;margin:14px auto 0;}
.setup-body{padding:36px;}
.section{margin-bottom:30px;}
.section h3{font-size:12px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--border);}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group.full{grid-column:1/-1;}
label{font-size:13px;font-weight:600;color:var(--navy);}
label .req{color:var(--danger);}
input,select,textarea{font-family:inherit;font-size:14px;padding:10px 13px;border:2px solid var(--border);border-radius:8px;background:var(--cream);color:var(--text);width:100%;transition:border-color .2s;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--gold);background:#fff;}
textarea{resize:vertical;min-height:76px;}
.hint{font-size:12px;color:var(--muted);}
.radio-group{display:flex;flex-wrap:wrap;gap:8px;}
.radio-opt{display:flex;align-items:center;gap:7px;background:var(--cream);border:2px solid var(--border);border-radius:8px;padding:9px 14px;cursor:pointer;font-size:14px;transition:all .2s;}
.radio-opt:has(input:checked){border-color:var(--gold);background:var(--gold-pale);font-weight:600;}
.bmi-box{background:var(--navy);color:#fff;border-radius:8px;padding:14px 18px;text-align:center;}
.bmi-box .bv{font-size:30px;font-weight:700;}
.bmi-box .bc{font-size:13px;opacity:.7;margin-top:2px;}
.err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:20px;}
.setup-foot{background:var(--cream-dark);padding:18px 36px;text-align:right;border-top:1px solid var(--border);}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 26px;border-radius:8px;font-family:inherit;font-size:15px;font-weight:600;cursor:pointer;border:none;background:var(--navy);color:#fff;transition:background .2s;}
.btn:hover{background:var(--navy-mid);}
@media(max-width:600px){.setup-body{padding:20px 16px;}.setup-head{padding:24px 16px;}.setup-foot{padding:14px 16px;}}
</style>
</head>
<body>
<div class="setup-box">
  <div class="setup-head">
    <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
      <rect width="48" height="48" rx="14" fill="#b8860b"/>
      <path d="M24 12v24M12 24h24" stroke="#fff" stroke-width="4" stroke-linecap="round"/>
      <circle cx="24" cy="24" r="7" fill="none" stroke="#fff" stroke-width="3"/>
    </svg>
    <h1>Welcome to Glycotrack</h1>
    <p>Create your account and health profile to get started</p>
    <div class="gold-bar"></div>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="setup_account">
    <div class="setup-body">
      <?php if ($setupErr): ?><div class="err"><?= h($setupErr) ?></div><?php endif; ?>

      <div class="section">
        <h3>Account Credentials</h3>
        <div class="form-grid">
          <div class="form-group">
            <label>Username <span class="req">*</span></label>
            <input type="text" name="username" required minlength="3" placeholder="e.g. vineet">
            <span class="hint">At least 3 characters</span>
          </div>
          <div class="form-group">
            <label>Password <span class="req">*</span></label>
            <input type="password" name="password" required minlength="6" placeholder="At least 6 characters">
          </div>
          <div class="form-group">
            <label>Confirm Password <span class="req">*</span></label>
            <input type="password" name="password2" required placeholder="Repeat password">
          </div>
        </div>
      </div>

      <div class="section">
        <h3>Personal Information</h3>
        <div class="form-grid">
          <div class="form-group"><label>First Name <span class="req">*</span></label><input type="text" name="first_name" required placeholder="e.g. Vineet"></div>
          <div class="form-group"><label>Last Name <span class="req">*</span></label><input type="text" name="last_name" required placeholder="e.g. Singh"></div>
          <div class="form-group"><label>Date of Birth <span class="req">*</span></label><input type="date" name="dob" required id="sdob" onchange="calcSetupBMI()"></div>
          <div class="form-group"><label>Biological Sex <span class="req">*</span></label><select name="sex" required><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option></select></div>
        </div>
      </div>

      <div class="section">
        <h3>Physical Metrics</h3>
        <div class="form-grid">
          <div class="form-group"><label>Weight (kg) <span class="req">*</span></label><input type="number" name="weight_kg" id="swkg" required step="0.1" min="20" max="300" placeholder="e.g. 72.5" oninput="calcSetupBMI()"></div>
          <div class="form-group">
            <label>Height <span class="req">*</span></label>
            <div style="display:flex;gap:8px;">
              <input type="number" name="height_ft" id="shft" required min="1" max="8" placeholder="Ft" style="width:50%" oninput="calcSetupBMI()">
              <input type="number" name="height_in" id="shin" required min="0" max="11" placeholder="In" style="width:50%" oninput="calcSetupBMI()">
            </div>
          </div>
          <div class="form-group">
            <div class="bmi-box" id="bmiBox"><div class="bv" id="bmiVal">—</div><div class="bc" id="bmiCat">BMI auto-calculated</div></div>
          </div>
        </div>
      </div>

      <div class="section">
        <h3>Diagnosis Details</h3>
        <div class="form-grid">
          <div class="form-group"><label>Date of Diagnosis <span class="req">*</span></label><input type="date" name="diagnosis_date" required></div>
          <div class="form-group"><label>Diabetes Type <span class="req">*</span></label><select name="diabetes_type" required><option value="">Select</option><option value="Type 1 (No Insulin Dependency)">Type 1 — No Insulin Dependency</option><option value="Type 2 (Insulin Resistance)">Type 2 — Insulin Resistance</option><option value="LADA">LADA (Latent Autoimmune)</option><option value="Gestational">Gestational</option><option value="Pre-diabetic">Pre-diabetic</option></select></div>
          <div class="form-group"><label>Blood Sugar at Diagnosis (mg/dL) <span class="req">*</span></label><input type="number" name="sugar_at_diagnosis" required step="0.1" placeholder="e.g. 280"></div>
          <div class="form-group"><label>Avg Sugar Before Diagnosis</label><input type="number" name="avg_sugar" step="0.1" placeholder="e.g. 240"></div>
          <div class="form-group full"><label>Symptoms That Led to Diagnosis <span class="req">*</span></label><textarea name="symptoms" required placeholder="e.g. Excessive thirst, frequent urination, fatigue, blurred vision..."></textarea></div>
          <div class="form-group"><label>Ketones at Diagnosis? <span class="req">*</span></label><div class="radio-group"><label class="radio-opt"><input type="radio" name="ketones" value="Yes" required> Yes</label><label class="radio-opt"><input type="radio" name="ketones" value="No"> No</label><label class="radio-opt"><input type="radio" name="ketones" value="Unknown"> Unknown</label></div></div>
          <div class="form-group"><label>Doctor Name</label><input type="text" name="doctor_name" placeholder="Dr. Name"></div>
          <div class="form-group"><label>Doctor Contact / Clinic</label><input type="text" name="doctor_contact" placeholder="Phone or clinic name"></div>
        </div>
      </div>

      <div class="section">
        <h3>Target Glucose Range</h3>
        <div class="form-grid">
          <div class="form-group"><label>Low Threshold (mg/dL)</label><input type="number" name="target_low" value="70" step="1"><span class="hint">Below this = Low</span></div>
          <div class="form-group"><label>High Threshold (mg/dL)</label><input type="number" name="target_high" value="180" step="1"><span class="hint">Above this = High</span></div>
        </div>
      </div>
    </div>
    <div class="setup-foot"><button type="submit" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Complete Setup</button></div>
  </form>
</div>
<script>
function calcSetupBMI(){
  var w=parseFloat(document.getElementById('swkg').value);
  var ft=parseInt(document.getElementById('shft').value)||0;
  var inch=parseInt(document.getElementById('shin').value)||0;
  if(!w||(!ft&&!inch)){return;}
  var hm=((ft*30.48)+(inch*2.54))/100;
  var bmi=Math.round((w/(hm*hm))*10)/10;
  var cat=bmi<18.5?'Underweight':bmi<25?'Normal Weight':bmi<30?'Overweight':'Obese';
  document.getElementById('bmiVal').textContent=bmi;
  document.getElementById('bmiCat').textContent=cat;
}
</script>
</body>
</html>
    <?php exit;
}

// ── AUTHENTICATED ZONE ───────────────────────────────────────
initDB();
$db      = getDB();
$patient = $db->query("SELECT * FROM patient WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$age     = calcAge($patient['dob']);

// ── AUTHENTICATED ACTIONS ────────────────────────────────────

if ($action === 'add_log') {
    $rt  = $_POST['reading_type'] ?? '';
    $mg  = ($rt === 'postprandial' && !empty($_POST['meal_gap'])) ? intval($_POST['meal_gap']) : null;
    $el  = !empty($_POST['energy_level']) ? intval($_POST['energy_level']) : null;
    $logDate = $_POST['log_date'] ?? date('Y-m-d');
    $logTime = $_POST['test_time'] ?? date('H:i');
    $loggedAt = $logDate . ' ' . $logTime . ':00'; // IST
    $db->prepare("INSERT INTO glucose_logs (level,test_time,reading_type,meal_gap_minutes,symptoms,mood,energy_level,test_method,lab_name,notes,logged_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([floatval($_POST['level']),$logTime,$rt,$mg,$_POST['symptoms']??'',$_POST['mood']??null,$el,$_POST['test_method']??'',$_POST['lab_name']??'',$_POST['notes']??'',$loggedAt]);
    redirect('?page=logs&added=1');
}

if ($action === 'delete_log') {
    $db->prepare("DELETE FROM glucose_logs WHERE id=?")->execute([(int)$_GET['id']]);
    redirect('?page=logs');
}

if ($action === 'edit_log') {
    $id  = (int)($_POST['log_id'] ?? 0);
    $rt  = $_POST['reading_type'] ?? '';
    $mg  = ($rt === 'postprandial' && !empty($_POST['meal_gap'])) ? intval($_POST['meal_gap']) : null;
    $el  = !empty($_POST['energy_level']) ? intval($_POST['energy_level']) : null;
    $logDate = $_POST['log_date'] ?? date('Y-m-d');
    $logTime = $_POST['test_time'] ?? date('H:i');
    $loggedAt = $logDate . ' ' . $logTime . ':00'; // IST
    $db->prepare("UPDATE glucose_logs SET level=?,test_time=?,reading_type=?,meal_gap_minutes=?,symptoms=?,mood=?,energy_level=?,test_method=?,lab_name=?,notes=?,logged_at=? WHERE id=?")
       ->execute([floatval($_POST['level']),$logTime,$rt,$mg,$_POST['symptoms']??'',$_POST['mood']??null,$el,$_POST['test_method']??'',$_POST['lab_name']??'',$_POST['notes']??'',$loggedAt,$id]);
    redirect('?page=logs&edited=1');
}

if ($action === 'update_profile') {
    $wkg  = floatval($_POST['weight_kg']);
    $hft  = intval($_POST['height_ft']);
    $hin  = intval($_POST['height_in']);
    $hcm  = round(($hft * 30.48) + ($hin * 2.54), 1);
    $bmi  = calcBMI($wkg, $hcm);
    $db->prepare("UPDATE patient SET first_name=?,last_name=?,dob=?,sex=?,weight_kg=?,height_ft=?,height_in=?,height_cm=?,bmi=?,bmi_category=?,doctor_name=?,doctor_contact=?,diagnosis_date=?,diabetes_type=?,ketones=? WHERE id=1")
       ->execute([$_POST['first_name'],$_POST['last_name'],$_POST['dob'],$_POST['sex'],$wkg,$hft,$hin,$hcm,$bmi,bmiCategory($bmi),$_POST['doctor_name'],$_POST['doctor_contact'],$_POST['diagnosis_date'],$_POST['diabetes_type'],$_POST['ketones']]);
    redirect('?page=profile&saved=1');
}

if ($action === 'update_targets') {
    $db->prepare("UPDATE patient SET target_low=?,target_high=? WHERE id=1")->execute([floatval($_POST['target_low']),floatval($_POST['target_high'])]);
    redirect('?page=settings&saved=1');
}

if ($action === 'upload_vault') {
    $file = $_FILES['report_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $rType = $_POST['report_type'] ?? '';
    if ($rType === '__custom__') { $rType = trim($_POST['report_type_custom'] ?? '') ?: 'Other'; }
    if (in_array($ext, ['pdf','jpg','jpeg']) && $file['error'] === 0) {
        $fname = uniqid('vault_') . '.' . $ext;
        move_uploaded_file($file['tmp_name'], VAULT_DIR . '/' . $fname);
        $db->prepare("INSERT INTO vault_files (filename,original_name,file_type,report_type,report_date,report_time,notes) VALUES (?,?,?,?,?,?,?)")
           ->execute([$fname,$file['name'],$ext,$rType,$_POST['report_date'],$_POST['report_time']??'',$_POST['vault_notes']??'']);
    }
    redirect('?page=vault&uploaded=1');
}

if ($action === 'delete_vault') {
    $row = $db->prepare("SELECT filename FROM vault_files WHERE id=?");
    $row->execute([(int)$_GET['id']]);
    $f = $row->fetch(PDO::FETCH_ASSOC);
    if ($f) { @unlink(VAULT_DIR . '/' . $f['filename']); $db->prepare("DELETE FROM vault_files WHERE id=?")->execute([(int)$_GET['id']]); }
    redirect('?page=vault');
}

if ($action === 'download_vault') {
    $row = $db->prepare("SELECT * FROM vault_files WHERE id=?");
    $row->execute([(int)$_GET['id']]);
    $f = $row->fetch(PDO::FETCH_ASSOC);
    if ($f && file_exists(VAULT_DIR . '/' . $f['filename'])) {
        $mime = ($f['file_type']==='pdf') ? 'application/pdf' : 'image/jpeg';
        $disp = !empty($_GET['view']) ? 'inline' : 'attachment';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: '.$disp.'; filename="' . $f['original_name'] . '"');
        readfile(VAULT_DIR . '/' . $f['filename']); exit;
    }
    exit;
}

if ($action === 'add_journal') {
    $now = date('Y-m-d H:i:s'); // IST via date_default_timezone_set
    $db->prepare("INSERT INTO journal (entry,category,logged_at) VALUES (?,?,?)")->execute([$_POST['entry'],$_POST['category']??'general',$now]);
    redirect('?page=journal&added=1');
}

if ($action === 'delete_journal') {
    $db->prepare("DELETE FROM journal WHERE id=?")->execute([(int)$_GET['id']]);
    redirect('?page=journal');
}

if ($action === 'export_csv') {
    $logs = $db->query("SELECT * FROM glucose_logs ORDER BY logged_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="glucose_logs_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['ID','Level(mg/dL)','Test Time','Reading Type','Meal Gap(min)','Symptoms','Mood','Energy(1-5)','Test Method','Lab Name','Notes','Logged At']);
    foreach ($logs as $r) fputcsv($out, array_values($r));
    fclose($out); exit;
}

// ── STATS ────────────────────────────────────────────────────
$stats = [];
$stats['total']      = (int)$db->query("SELECT COUNT(*) FROM glucose_logs")->fetchColumn();
$stats['avg']        = round($db->query("SELECT AVG(level) FROM glucose_logs")->fetchColumn() ?? 0, 1);
$stats['max']        = (float)$db->query("SELECT MAX(level) FROM glucose_logs")->fetchColumn();
$stats['min']        = (float)$db->query("SELECT MIN(level) FROM glucose_logs")->fetchColumn();
$stats['last']       = (float)$db->query("SELECT level FROM glucose_logs ORDER BY logged_at DESC LIMIT 1")->fetchColumn();
$stats['fasting_avg']= round($db->query("SELECT AVG(level) FROM glucose_logs WHERE reading_type='fasting'")->fetchColumn() ?? 0, 1);
$stats['pp_avg']     = round($db->query("SELECT AVG(level) FROM glucose_logs WHERE reading_type='postprandial'")->fetchColumn() ?? 0, 1);
$stats['a1c']        = estimateA1C($stats['avg']);
$stats['vault_count']= (int)$db->query("SELECT COUNT(*) FROM vault_files")->fetchColumn();

// In-range %
if ($stats['total'] > 0) {
    $ir = $db->prepare("SELECT COUNT(*) FROM glucose_logs WHERE level >= ? AND level <= ?");
    $ir->execute([$patient['target_low'], $patient['target_high']]);
    $stats['in_range'] = round(($ir->fetchColumn() / $stats['total']) * 100);
} else { $stats['in_range'] = 0; }

// Streak (counts consecutive days with a reading, ending today OR yesterday —
// so the streak isn't shown as "lost" just because today hasn't been logged yet)
$stats['streak'] = 0;
$streakDays = $db->query("SELECT DISTINCT DATE(logged_at) as d FROM glucose_logs ORDER BY d DESC")->fetchAll(PDO::FETCH_COLUMN);
if ($streakDays) {
    $cursor = new DateTime('today'); // IST, per date_default_timezone_set above
    if ($streakDays[0] !== $cursor->format('Y-m-d')) {
        $cursor->modify('-1 day'); // haven't logged today — see if yesterday's chain is unbroken
    }
    foreach ($streakDays as $d) {
        if ($d === $cursor->format('Y-m-d')) {
            $stats['streak']++;
            $cursor->modify('-1 day');
        } else {
            break;
        }
    }
}

// 30-day daily trend
$trend30 = $db->query("SELECT DATE(logged_at) as d, ROUND(AVG(level),1) as avg, COUNT(*) as cnt, MIN(level) as lo, MAX(level) as hi FROM glucose_logs WHERE logged_at >= date('now','-30 days') GROUP BY DATE(logged_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);

// Reading type breakdown
$typeBreakdown = $db->query("SELECT reading_type, COUNT(*) as cnt, ROUND(AVG(level),1) as avg FROM glucose_logs GROUP BY reading_type")->fetchAll(PDO::FETCH_ASSOC);

// ── SVG ICON HELPER ──────────────────────────────────────────
function icon($name, $size=20, $stroke='currentColor') {
    $s = $size; $sw = 2;
    $paths = [
        'dashboard'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'profile'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logs'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'add'         => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
        'vault'       => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'journal'     => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'export'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'chart'       => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash'       => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'check'       => '<polyline points="20 6 9 17 4 12"/>',
        'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'heart'       => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'menu'        => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'close'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'alert'       => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'print'       => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'user'        => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'drop'        => '<path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>',
        'trending'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'file'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'image'       => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'save'        => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
        'back'        => '<polyline points="15 18 9 12 15 6"/>',
        'target'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'fire'        => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'lab'         => '<path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"/><path d="M8.5 2h7"/><path d="M14.5 16h-5"/><path d="M6 12l2-2 2 2 2-2 2 2 2-2"/>',
    ];
    $d = $paths[$name] ?? '<circle cx="12" cy="12" r="10"/>';
    return '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.h($stroke).'" stroke-width="'.$sw.'" stroke-linecap="round" stroke-linejoin="round">'.$d.'</svg>';
}

// ── SVG CHART RENDERER ───────────────────────────────────────
function renderLineChart($data, $patient, $W=800, $H=230) {
    if (empty($data)) return '<p style="text-align:center;color:#999;padding:40px">No data available</p>';
    $n0 = count($data);
    $minPerPoint = 36;
    $W = max($W, $n0 * $minPerPoint + 80);
    $scrollable = $W > 800;
    $pad = ['t'=>32,'r'=>28,'b'=>46,'l'=>54];
    $pw = $W - $pad['l'] - $pad['r'];
    $ph = $H - $pad['t'] - $pad['b'];
    $vals = array_column($data,'avg');
    $allV = array_merge($vals, [$patient['target_low'], $patient['target_high']]);
    $minV = max(0, min($allV) - 20);
    $maxV = max($allV) + 25;
    $n    = count($data);
    $tlow = $patient['target_low'];
    $thigh= $patient['target_high'];

    $toX = function($i) use ($pw,$pad,$n){ return $pad['l'] + ($n > 1 ? ($i/($n-1))*$pw : $pw/2); };
    $toY = function($v) use ($ph,$pad,$minV,$maxV){ return $pad['t'] + $ph - (($v-$minV)/($maxV-$minV))*$ph; };

    // Build smooth cubic bezier path
    $smoothPath = '';
    if ($n === 1) {
        $smoothPath = 'M '.$toX(0).','.$toY($vals[0]);
    } elseif ($n > 1) {
        $pts = [];
        foreach ($data as $i => $r) $pts[] = [$toX($i), $toY($r['avg'])];
        $smoothPath = 'M '.$pts[0][0].','.$pts[0][1];
        for ($i=1; $i<count($pts); $i++) {
            $prev = $pts[$i-1]; $cur = $pts[$i];
            $cpx = ($prev[0]+$cur[0])/2;
            $smoothPath .= ' C '.$cpx.','.$prev[1].' '.$cpx.','.$cur[1].' '.$cur[0].','.$cur[1];
        }
    }
    // fill path
    $fillPath = $smoothPath;
    if ($n > 0) {
        $fillPath .= ' L '.$toX($n-1).','.(($pad['t']+$ph)).' L '.$pad['l'].','.(($pad['t']+$ph)).' Z';
    }

    ob_start();
    if ($scrollable): ?>
<div class="chart-scroll"><div style="width:<?=$W?>px;max-width:none;">
<?php endif; ?>
<svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:<?=$scrollable?$W.'px':'100%'?>;display:block;overflow:visible;font-family:inherit" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="lineFill" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#b8860b" stop-opacity="0.28"/>
      <stop offset="85%" stop-color="#b8860b" stop-opacity="0.03"/>
    </linearGradient>
    <linearGradient id="rangeGrad" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#27ae60" stop-opacity="0.10"/>
      <stop offset="100%" stop-color="#27ae60" stop-opacity="0.03"/>
    </linearGradient>
    <filter id="ptGlow"><feGaussianBlur stdDeviation="2.5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
  </defs>

  <!-- grid -->
  <?php for ($g=0; $g<=5; $g++):
      $gv = $minV + ($g/5)*($maxV-$minV); $gy = $toY($gv);
  ?>
  <line x1="<?=$pad['l']?>" y1="<?=$gy?>" x2="<?=$pad['l']+$pw?>" y2="<?=$gy?>" stroke="#ecdfc5" stroke-width="<?=$g===0?'0':'1'?>"/>
  <text x="<?=$pad['l']-7?>" y="<?=$gy+4?>" text-anchor="end" font-size="10" fill="#bbb" font-family="inherit"><?=round($gv)?></text>
  <?php endfor; ?>

  <!-- target zone -->
  <?php $tz_top=$toY($thigh); $tz_bot=$toY($tlow); ?>
  <rect x="<?=$pad['l']?>" y="<?=$tz_top?>" width="<?=$pw?>" height="<?=max(0,$tz_bot-$tz_top)?>" fill="url(#rangeGrad)" rx="3"/>
  <line x1="<?=$pad['l']?>" y1="<?=$tz_top?>" x2="<?=$pad['l']+$pw?>" y2="<?=$tz_top?>" stroke="#27ae60" stroke-width="1.5" stroke-dasharray="6,4" opacity=".5"/>
  <line x1="<?=$pad['l']?>" y1="<?=$tz_bot?>"  x2="<?=$pad['l']+$pw?>" y2="<?=$tz_bot?>"  stroke="#f39c12" stroke-width="1.5" stroke-dasharray="6,4" opacity=".5"/>

  <!-- fill under curve -->
  <?php if ($n > 0): ?>
  <path d="<?=$fillPath?>" fill="url(#lineFill)"/>
  <?php endif; ?>

  <!-- smooth line -->
  <?php if ($n > 1): ?>
  <path d="<?=$smoothPath?>" fill="none" stroke="#b8860b" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/>
  <?php endif; ?>

  <!-- data points -->
  <?php foreach ($data as $i => $row):
      $v = $row['avg'];
      $col = $v < $tlow ? '#f39c12' : ($v > $thigh ? '#e74c3c' : '#27ae60');
      $cx = $toX($i); $cy = $toY($v);
      $label = date('d M', strtotime($row['d']));
  ?>
  <circle cx="<?=$cx?>" cy="<?=$cy?>" r="8" fill="<?=$col?>" opacity="0.15"/>
  <circle class="chart-pt" cx="<?=$cx?>" cy="<?=$cy?>" r="5" fill="<?=$col?>" stroke="#fff" stroke-width="2.5"
    data-v="<?=$v?> mg/dL" data-d="<?=h($label)?>" style="cursor:pointer;filter:drop-shadow(0 1px 3px <?=$col?>66)"/>
  <?php
  $showLabel = ($n <= 8) || ($i % max(1, intval($n/8)) === 0) || ($i === $n-1);
  if ($showLabel): ?>
  <text x="<?=$cx?>" y="<?=$H-6?>" text-anchor="middle" font-size="9.5" fill="#aaa" font-family="inherit"><?=$label?></text>
  <?php endif; ?>
  <?php endforeach; ?>

  <!-- Y axis label -->
  <text x="14" y="<?=$pad['t']+$ph/2?>" text-anchor="middle" font-size="10" fill="#ccc" font-family="inherit" transform="rotate(-90 14 <?=$pad['t']+$ph/2?>)">mg/dL</text>

  <!-- legend -->
  <rect x="<?=$pad['l']?>" y="5" width="11" height="11" rx="3" fill="#27ae60" opacity=".25"/>
  <text x="<?=$pad['l']+15?>" y="14" font-size="9.5" fill="#888" font-family="inherit">Target <?=$tlow?>–<?=$thigh?> mg/dL</text>
  <line x1="<?=$pad['l']+130?>" y1="10" x2="<?=$pad['l']+148?>" y2="10" stroke="#b8860b" stroke-width="2.5" stroke-linecap="round"/>
  <circle cx="<?=$pad['l']+139?>" cy="10" r="3.5" fill="#b8860b"/>
  <text x="<?=$pad['l']+152?>" y="14" font-size="9.5" fill="#888" font-family="inherit">Avg glucose</text>
</svg>
<?php if ($scrollable): ?>
</div></div>
<?php endif; ?>
    <?php return ob_get_clean();
}

function renderBarChart($data, $patient, $W=800, $H=220) {
    if (empty($data)) return '<p style="text-align:center;color:#999;padding:40px">No data available</p>';
    $n0 = count($data);
    $minPerBar = 36;
    $W = max($W, $n0 * $minPerBar + 80);
    $scrollable = $W > 800;
    $pad = ['t'=>28,'r'=>20,'b'=>48,'l'=>54];
    $pw = $W - $pad['l'] - $pad['r'];
    $ph = $H - $pad['t'] - $pad['b'];
    $vals = array_column($data,'avg');
    $maxV = max(array_merge($vals,[$patient['target_high']])) + 30;
    $minV = 0;
    $n = count($data);
    $gap = max(4, ($pw/$n)*0.18);
    $barW = max(10, ($pw/$n) - $gap);
    $tlow = $patient['target_low']; $thigh = $patient['target_high'];
    $toY = function($v) use ($ph,$pad,$minV,$maxV){ return $pad['t'] + $ph - (($v-$minV)/($maxV-$minV))*$ph; };
    ob_start();
    if ($scrollable): ?>
<div class="chart-scroll"><div style="width:<?=$W?>px;max-width:none;">
<?php endif; ?>
<svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:<?=$scrollable?$W.'px':'100%'?>;display:block;overflow:visible;font-family:inherit" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="barGradIn" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#27ae60"/><stop offset="100%" stop-color="#1e8449" stop-opacity=".75"/></linearGradient>
    <linearGradient id="barGradHi" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#e74c3c"/><stop offset="100%" stop-color="#c0392b" stop-opacity=".75"/></linearGradient>
    <linearGradient id="barGradLo" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#f39c12"/><stop offset="100%" stop-color="#e67e22" stop-opacity=".75"/></linearGradient>
    <filter id="barShadow" x="-10%" y="-10%" width="120%" height="130%"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#00000022"/></filter>
  </defs>
  <!-- horizontal grid -->
  <?php for ($g=0;$g<=5;$g++): $gv=($g/5)*$maxV; $gy=$toY($gv); ?>
  <line x1="<?=$pad['l']?>" y1="<?=$gy?>" x2="<?=$pad['l']+$pw?>" y2="<?=$gy?>" stroke="#ecdfc5" stroke-width="<?=$g===0?'0':'1'?>"/>
  <text x="<?=$pad['l']-7?>" y="<?=$gy+4?>" text-anchor="end" font-size="10" fill="#aaa" font-family="inherit"><?=round($gv)?></text>
  <?php endfor; ?>
  <!-- target zone band -->
  <?php $tz_top=$toY($thigh); $tz_bot=$toY($tlow); ?>
  <rect x="<?=$pad['l']?>" y="<?=$tz_top?>" width="<?=$pw?>" height="<?=max(0,$tz_bot-$tz_top)?>" fill="#27ae60" opacity="0.06"/>
  <!-- target dashed lines -->
  <line x1="<?=$pad['l']?>" y1="<?=$toY($thigh)?>" x2="<?=$pad['l']+$pw?>" y2="<?=$toY($thigh)?>" stroke="#e74c3c" stroke-width="1.2" stroke-dasharray="6,4" opacity=".55"/>
  <line x1="<?=$pad['l']?>" y1="<?=$toY($tlow)?>"  x2="<?=$pad['l']+$pw?>" y2="<?=$toY($tlow)?>"  stroke="#f39c12" stroke-width="1.2" stroke-dasharray="6,4" opacity=".55"/>
  <!-- bars -->
  <?php foreach ($data as $i => $row):
      $v = $row['avg'];
      $col = $v < $tlow ? 'url(#barGradLo)' : ($v > $thigh ? 'url(#barGradHi)' : 'url(#barGradIn)');
      $cx = $pad['l'] + (($i+0.5)/$n)*$pw;
      $barTop = $toY($v);
      $barH = max(4, ($pad['t']+$ph) - $barTop);
      $label = date('d/m', strtotime($row['d']));
      $rx = min(6, $barW/2);
  ?>
  <!-- bar with rounded top -->
  <rect class="chart-pt" x="<?=$cx-$barW/2?>" y="<?=$barTop?>" width="<?=$barW?>" height="<?=$barH?>" rx="<?=$rx?>" ry="<?=$rx?>"
    fill="<?=$col?>" filter="url(#barShadow)"
    data-v="<?=$v?> mg/dL" data-d="<?=h($label)?>" style="cursor:pointer;transition:opacity .15s"
    onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'"/>
  <?php $show=($n<=10)||($i%max(1,intval($n/10))===0)||($i===$n-1); if($show): ?>
  <text x="<?=$cx?>" y="<?=$H-6?>" text-anchor="middle" font-size="9.5" fill="#999" font-family="inherit"><?=$label?></text>
  <?php endif; ?>
  <?php endforeach; ?>
  <!-- axis labels -->
  <text x="<?=$pad['l']+$pw+4?>" y="<?=$toY($thigh)+4?>" font-size="8.5" fill="#e74c3c" font-family="inherit"><?=$thigh?></text>
  <text x="<?=$pad['l']+$pw+4?>" y="<?=$toY($tlow)+4?>" font-size="8.5" fill="#f39c12" font-family="inherit"><?=$tlow?></text>
  <!-- Y axis label -->
  <text x="13" y="<?=$pad['t']+$ph/2?>" text-anchor="middle" font-size="10" fill="#bbb" font-family="inherit" transform="rotate(-90 13 <?=$pad['t']+$ph/2?>)">mg/dL</text>
  <!-- legend -->
  <rect x="<?=$pad['l']?>" y="4" width="10" height="10" rx="3" fill="url(#barGradIn)" opacity=".8"/>
  <text x="<?=$pad['l']+14?>" y="13" font-size="9.5" fill="#777" font-family="inherit">In Range</text>
  <rect x="<?=$pad['l']+68?>" y="4" width="10" height="10" rx="3" fill="url(#barGradHi)" opacity=".8"/>
  <text x="<?=$pad['l']+82?>" y="13" font-size="9.5" fill="#777" font-family="inherit">High</text>
  <rect x="<?=$pad['l']+108?>" y="4" width="10" height="10" rx="3" fill="url(#barGradLo)" opacity=".8"/>
  <text x="<?=$pad['l']+122?>" y="13" font-size="9.5" fill="#777" font-family="inherit">Low</text>
</svg>
<?php if ($scrollable): ?>
</div></div>
<?php endif; ?>
    <?php return ob_get_clean();
}

function renderDonut($inRange, $size=120) {
    $r = 46; $cx = 60; $cy = 60; $stroke = 12;
    $circ = 2*M_PI*$r;
    $pct = max(0,min(100,$inRange));
    $dash = ($pct/100)*$circ;
    $gap  = $circ - $dash;
    $col  = $pct >= 70 ? '#27ae60' : ($pct >= 50 ? '#e67e22' : '#c0392b');
    ob_start(); ?>
<svg width="<?=$size?>" height="<?=$size?>" viewBox="0 0 120 120">
  <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$r?>" fill="none" stroke="#e8d5b0" stroke-width="<?=$stroke?>"/>
  <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$r?>" fill="none" stroke="<?=$col?>" stroke-width="<?=$stroke?>"
    stroke-dasharray="<?=$dash?> <?=$gap?>" stroke-dashoffset="<?=$circ/4?>" stroke-linecap="round"/>
  <text x="<?=$cx?>" y="<?=$cy+5?>" text-anchor="middle" font-size="18" font-weight="700" fill="<?=$col?>"><?=$pct?>%</text>
  <text x="<?=$cx?>" y="<?=$cy+20?>" text-anchor="middle" font-size="9" fill="#999">In Range</text>
</svg>
    <?php return ob_get_clean();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Glycotrack — <?= h($patient['first_name'].' '.$patient['last_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<style>
:root{
  --cream:#fdf8f0;--cream-dark:#f5edd8;--border:#e8d5b0;
  --gold:#b8860b;--gold-l:#d4a017;--gold-pale:#fef3cd;
  --navy:#1a2744;--navy-mid:#243358;--navy-l:#2d4070;
  --text:#2c2c2c;--muted:#6b6b6b;--white:#fff;
  --danger:#c0392b;--success:#27ae60;--warning:#e67e22;--info:#2980b9;
  --r:12px;--r-sm:8px;--sw:260px;
  --shadow:0 2px 16px rgba(26,39,68,.09);--shadow-lg:0 8px 36px rgba(26,39,68,.14);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
html,body{overflow-x:hidden;max-width:100%;}
body{font-family:'Noto Sans',sans-serif;background:var(--cream);color:var(--text);min-height:100vh;font-size:15px;line-height:1.6;}

/* LAYOUT */
.app{display:flex;min-height:100vh;}
.sidebar{width:var(--sw);background:var(--navy);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;transition:transform .28s cubic-bezier(.4,0,.2,1);overflow-y:auto;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh;}
.topbar{background:var(--white);border-bottom:2px solid var(--border);padding:13px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.05);}
.content{padding:26px 24px;max-width:1240px;width:100%;}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:90;}

/* SIDEBAR */
.sb-logo{padding:22px 18px 18px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:11px;}
.sb-logo .logo-icon{width:40px;height:40px;background:var(--gold);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo h1{color:var(--white);font-size:17px;font-weight:700;letter-spacing:.2px;}
.sb-logo p{color:rgba(255,255,255,.45);font-size:11px;}
.sb-section{padding:14px 10px 6px;}
.sb-label{color:rgba(255,255,255,.32);font-size:10px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;padding:0 8px 7px;}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r-sm);color:rgba(255,255,255,.7);text-decoration:none;font-size:13.5px;font-weight:500;margin-bottom:2px;transition:all .18s;}
.nav-item:hover{background:rgba(255,255,255,.08);color:var(--white);}
.nav-item.active{background:var(--gold);color:#fff;font-weight:700;}
.nav-item .ni{width:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.nav-badge{margin-left:auto;background:rgba(255,255,255,.18);color:var(--white);font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;min-width:22px;text-align:center;}
.nav-item.active .nav-badge{background:rgba(26,39,68,.2);}
.sb-footer{margin-top:auto;padding:14px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:11px;color:rgba(255,255,255,.32);line-height:1.8;}
.sb-footer a{color:var(--gold);text-decoration:none;}

/* TOPBAR */
.topbar{border-bottom:2px solid var(--gold);flex-wrap:nowrap!important;gap:10px;}
.tb-left{min-width:0;overflow:hidden;}
.tb-left h2{font-size:18px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tb-left p{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tb-right{display:flex;align-items:center;gap:10px;flex-wrap:nowrap;justify-content:flex-end;flex-shrink:0;}
.hamburger{display:none;background:none;border:none;cursor:pointer;padding:6px;color:var(--navy);border-radius:8px;flex-shrink:0;}
.hamburger:hover{background:var(--cream-dark);}
.user-chip{display:flex;align-items:center;gap:7px;background:var(--cream-dark);border-radius:20px;padding:5px 12px 5px 6px;font-size:13px;font-weight:600;color:var(--navy);flex-shrink:0;}
.user-chip .uc-av{width:26px;height:26px;background:var(--navy);color:var(--white);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--r-sm);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .18s;white-space:nowrap;line-height:1;}
.btn-primary{background:var(--navy);color:var(--white);}
.btn-primary:hover{background:var(--navy-mid);transform:translateY(-1px);box-shadow:0 4px 12px rgba(26,39,68,.25);}
.btn-gold{background:var(--gold);color:var(--white);}
.btn-gold:hover{background:var(--gold-l);}
.btn-outline{background:transparent;border:2px solid var(--border);color:var(--navy);}
.btn-outline:hover{border-color:var(--navy);background:var(--cream-dark);}
.btn-ghost{background:transparent;color:var(--muted);padding:7px 10px;}
.btn-ghost:hover{background:var(--cream-dark);color:var(--navy);}
.btn-danger{background:var(--danger);color:var(--white);}
.btn-danger:hover{background:#a93226;}
.btn-success{background:var(--success);color:var(--white);}
.btn-sm{padding:6px 13px;font-size:12.5px;}
.btn-xs{padding:4px 9px;font-size:11.5px;}

/* CARDS */
.card{background:var(--white);border-radius:var(--r);box-shadow:var(--shadow);border:1px solid var(--border);}
.card-head{padding:16px 20px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.card-head h3{font-size:15px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}

/* STAT CARDS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:14px;margin-bottom:22px;}
.stat-card{background:var(--white);border-radius:var(--r);padding:18px 18px 14px;box-shadow:var(--shadow);border:1px solid var(--border);border-left:4px solid var(--gold);position:relative;}
.stat-card .sl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.stat-card .sv{font-size:24px;font-weight:700;color:var(--navy);margin:5px 0 1px;line-height:1.1;}
.stat-card .ss{font-size:12px;color:var(--muted);}
.stat-card .si{position:absolute;right:14px;top:14px;opacity:.12;}
.sc-navy{border-left-color:var(--navy);}
.sc-green{border-left-color:var(--success);}
.sc-red{border-left-color:var(--danger);}
.sc-orange{border-left-color:var(--warning);}
.sc-info{border-left-color:var(--info);}

/* FORMS */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
.fg label{font-size:13px;font-weight:600;color:var(--navy);}
.fg label .req{color:var(--danger);}
input[type=text],input[type=number],input[type=date],input[type=time],input[type=password],input[type=email],select,textarea{font-family:inherit;font-size:14px;color:var(--text);background:var(--cream);border:2px solid var(--border);border-radius:var(--r-sm);padding:10px 13px;transition:border-color .2s,box-shadow .2s;width:100%;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(184,134,11,.11);background:var(--white);}
textarea{resize:vertical;min-height:76px;}
.hint{font-size:12px;color:var(--muted);}
.radio-group{display:flex;flex-wrap:wrap;gap:8px;}
.radio-opt{display:flex;align-items:center;gap:7px;background:var(--cream);border:2px solid var(--border);border-radius:var(--r-sm);padding:8px 13px;cursor:pointer;font-size:13.5px;transition:all .18s;}
.radio-opt:has(input:checked){border-color:var(--gold);background:var(--gold-pale);font-weight:600;}
input[type=radio]{width:auto;accent-color:var(--gold);}
.bmi-box{background:var(--navy);color:var(--white);border-radius:var(--r-sm);padding:12px 16px;text-align:center;}
.bmi-box .bv{font-size:28px;font-weight:700;}
.bmi-box .bc{font-size:12px;opacity:.7;margin-top:2px;}

/* IST CLOCK */
.ist-clock{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:var(--navy);background:var(--cream-dark);border:1px solid var(--border);border-radius:8px;padding:5px 10px;white-space:nowrap;letter-spacing:.2px;line-height:1;flex-shrink:0;}
.ist-clock .ic-time{font-size:12.5px;font-weight:700;}
.ist-clock .ic-sep{color:var(--border);font-weight:300;font-size:13px;}
.ist-clock .ic-date{font-size:11px;font-weight:500;color:var(--muted);}
@media(max-width:480px){.ist-clock{padding:4px 8px;gap:4px;}.ist-clock .ic-time{font-size:11.5px;}.ist-clock .ic-date{display:none;}}

/* TABLE */
.col-hide-sm{} /* shown by default, hidden on small screens below */
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
/* horizontally scrollable charts (30-day trend gets wide) */
.chart-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -4px;padding:0 4px 6px;scrollbar-width:thin;scrollbar-color:var(--gold) var(--cream-dark);}
.chart-scroll::-webkit-scrollbar{height:7px;}
.chart-scroll::-webkit-scrollbar-track{background:var(--cream-dark);border-radius:10px;}
.chart-scroll::-webkit-scrollbar-thumb{background:var(--gold);border-radius:10px;}
.chart-hint{display:none;font-size:11px;color:var(--muted);text-align:right;margin-bottom:6px;}
table{width:100%;border-collapse:collapse;}
th{background:var(--navy);color:var(--white);padding:11px 13px;text-align:left;font-size:12px;font-weight:600;letter-spacing:.3px;white-space:nowrap;}
th:first-child{border-radius:8px 0 0 0;}th:last-child{border-radius:0 8px 0 0;}
td{padding:10px 13px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--cream);}
/* keep row action buttons (Edit/Delete) visible without needing to scroll the table */
.col-actions{position:sticky;right:0;background:var(--white);box-shadow:-6px 0 8px -6px rgba(0,0,0,.12);z-index:1;white-space:nowrap;}
th.col-actions{background:var(--navy);}
tr:hover td.col-actions{background:var(--cream);}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-sharp{border-radius:4px;}
.bg-green{background:#d4edda;color:#155724;}
.bg-red{background:#f8d7da;color:#721c24;}
.bg-orange{background:#fff3cd;color:#856404;}
.bg-blue{background:#cce5ff;color:#004085;}
.bg-navy{background:#d0d6e8;color:var(--navy);}
.bg-grey{background:#e2e3e5;color:#383d41;}

/* GRID HELPERS */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.mt-4{margin-top:16px;}.mt-5{margin-top:20px;}.mt-6{margin-top:24px;}
.flex{display:flex;}.gap-2{gap:8px;}.gap-3{gap:12px;}.items-center{align-items:center;}
.justify-between{justify-content:space-between;}.flex-wrap{flex-wrap:wrap;}
.text-muted{color:var(--muted);}.text-sm{font-size:13px;}.text-xs{font-size:11.5px;}
.divider{border:none;border-top:1px solid var(--border);margin:18px 0;}

/* ALERTS */
.alert{padding:11px 15px;border-radius:var(--r-sm);font-size:13.5px;margin-bottom:14px;display:flex;align-items:flex-start;gap:9px;}
.al-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.al-warning{background:#fff3cd;color:#856404;border:1px solid #ffeeba;}
.al-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
.al-info{background:#cce5ff;color:#004085;border:1px solid #b8daff;}

/* PROGRESS */
.prog-bar{height:8px;background:var(--border);border-radius:4px;overflow:hidden;}
.prog-fill{height:100%;border-radius:4px;transition:width .6s;}

/* PROFILE BANNER */
.profile-banner{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 100%);border-radius:var(--r);padding:26px;color:var(--white);margin-bottom:22px;}
.pb-avatar{width:60px;height:60px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;margin-bottom:10px;}
.pb-name{font-size:21px;font-weight:700;}
.pb-sub{color:rgba(255,255,255,.6);font-size:13px;margin-top:3px;}
.pb-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px;}
.pb-chip{background:rgba(255,255,255,.12);border-radius:20px;padding:4px 13px;font-size:12px;font-weight:600;}

/* INFO TILES */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
.info-tile{background:var(--cream);border-radius:var(--r-sm);padding:13px 15px;}
.info-tile .it-l{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.info-tile .it-v{font-size:15px;font-weight:700;color:var(--navy);margin-top:3px;}

/* VAULT */
.vault-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;}
.vault-item{background:var(--white);border:2px solid var(--border);border-radius:var(--r-sm);padding:16px;text-align:center;transition:border-color .2s;}
.vault-item:hover{border-color:var(--gold);}
.vi-icon{display:flex;align-items:center;justify-content:center;margin-bottom:10px;color:var(--navy);}
.vi-name{font-size:12px;font-weight:700;color:var(--navy);word-break:break-all;margin-bottom:3px;}
.vi-meta{font-size:11px;color:var(--muted);margin-bottom:10px;line-height:1.5;}
.vi-actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;}

/* JOURNAL */
.journal-item{border-left:3px solid var(--gold);padding:12px 16px;background:var(--cream);border-radius:0 var(--r-sm) var(--r-sm) 0;margin-bottom:10px;}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:200;align-items:center;justify-content:center;padding:16px;}
.modal-bg.open{display:flex;}
.modal-box{background:var(--white);border-radius:var(--r);width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);}
.modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.modal-head h3{font-size:16px;font-weight:700;color:var(--navy);}
.modal-close{background:none;border:none;cursor:pointer;color:var(--muted);display:flex;padding:3px;}
.modal-body{padding:20px;}
.modal-foot{padding:13px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:9px;}

/* CHART TABS */
.chart-tabs{display:flex;gap:6px;margin-bottom:14px;}
.chart-tab{padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;border:2px solid var(--border);background:var(--cream);color:var(--muted);transition:all .18s;}
.chart-tab.active{border-color:var(--navy);background:var(--navy);color:var(--white);}

/* EMPTY STATE */
.empty-state{text-align:center;padding:48px 24px;color:var(--muted);}
.empty-state .ei{display:flex;align-items:center;justify-content:center;margin-bottom:14px;opacity:.35;}
.empty-state h3{font-size:17px;color:var(--text);margin-bottom:5px;}

/* RESPONSIVE */
@media(max-width:900px){
  .chart-hint{display:block;}
  .sidebar{transform:translateX(-100%);width:min(var(--sw),84vw);}
  .sidebar.open{transform:translateX(0);}
  .overlay.active{display:block;}
  .main{margin-left:0;width:100%;}
  .hamburger{display:block;}
  .content{padding:16px 12px;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .grid-2,.grid-3{grid-template-columns:1fr;}
  .topbar{padding:10px 12px;gap:6px;}
  .tb-left{flex:1 1 0;min-width:0;}
  .tb-left h2{font-size:14px;}
  .tb-left p{display:none;}
  .tb-right{gap:6px;flex-shrink:0;}
  .card-body{padding:16px;}
  .card-head{padding:14px 16px 11px;}
}
@media(max-width:600px){
  .form-grid{grid-template-columns:1fr;}
  .info-grid{grid-template-columns:1fr;}
  .modal-box{max-width:100%;}
  table{font-size:12px;}
  th,td{padding:8px 9px;}
  .content{padding:14px 10px;}
  .user-chip span,.user-chip{font-size:12px;}
  .col-hide-sm{display:none;}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr 1fr;gap:10px;}
  .stat-card{padding:14px 14px 11px;}
  .stat-card .sv{font-size:19px;}
  .vault-grid{grid-template-columns:1fr 1fr;}
  .form-grid{grid-template-columns:1fr;}
  /* topbar: clock shrinks to time-only, chip loses name, add button icon-only */
  .topbar{padding:9px 10px;gap:5px;}
  .tb-right{gap:5px;}
  .tb-right a.btn-gold{padding:7px 9px;}
  .tb-right a.btn-gold span.tb-add-label{display:none;}
  .user-chip{padding:4px 8px 4px 4px;}
  .user-chip span.tb-uname{display:none;}
  .tb-left h2{font-size:13px;}
  .radio-group{gap:6px;}
  .radio-opt{padding:7px 10px;font-size:12.5px;}
  .vi-meta{word-break:break-word;}
}
@media print{
  .sidebar,.topbar,.btn,.hamburger,.modal-bg{display:none!important;}
  .main{margin-left:0!important;}
  .card{box-shadow:none!important;border:1px solid #ddd!important;}
}
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>
<div class="app">
<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="logo-icon"><?= icon('drop',20,'#fff') ?></div>
    <div><h1>Glycotrack</h1><p>Health Manager</p></div>
  </div>

  <div class="sb-section">
    <div class="sb-label">Overview</div>
    <a href="?" class="nav-item <?= $page==='dashboard'?'active':'' ?>"><span class="ni"><?= icon('dashboard',17) ?></span> Dashboard</a>
    <a href="?page=profile" class="nav-item <?= $page==='profile'?'active':'' ?>"><span class="ni"><?= icon('profile',17) ?></span> My Profile</a>
  </div>
  <div class="sb-section">
    <div class="sb-label">Tracking</div>
    <a href="?page=logs" class="nav-item <?= $page==='logs'?'active':'' ?>"><span class="ni"><?= icon('logs',17) ?></span> Glucose Logs <span class="nav-badge"><?= $stats['total'] ?></span></a>
    <a href="?page=add_log" class="nav-item <?= $page==='add_log'?'active':'' ?>"><span class="ni"><?= icon('add',17) ?></span> Add Reading</a>
    <a href="?page=journal" class="nav-item <?= $page==='journal'?'active':'' ?>"><span class="ni"><?= icon('journal',17) ?></span> Health Journal</a>
  </div>
  <div class="sb-section">
    <div class="sb-label">Records</div>
    <a href="?page=vault" class="nav-item <?= $page==='vault'?'active':'' ?>"><span class="ni"><?= icon('vault',17) ?></span> Report Vault <span class="nav-badge"><?= $stats['vault_count'] ?></span></a>
    <a href="?action=export_csv" class="nav-item"><span class="ni"><?= icon('export',17) ?></span> Export CSV</a>
  </div>
  <div class="sb-section">
    <div class="sb-label">Account</div>
    <a href="?page=settings" class="nav-item <?= $page==='settings'?'active':'' ?>"><span class="ni"><?= icon('settings',17) ?></span> Settings</a>
    <a href="?action=logout" class="nav-item"><span class="ni"><?= icon('logout',17) ?></span> Sign Out</a>
  </div>

  <div class="sb-footer">
    Glycotrack v1.1.2<br>
    <a href="https://github.com/psvineet" target="_blank">Vineet Pratap Singh</a><br>
    <a href="mailto:connect.vps@icloud.com">connect.vps@icloud.com</a>
  </div>
</nav>

<!-- MAIN CONTENT -->
<div class="main">
  <div class="topbar">
    <div class="tb-left">
      <?php
      $titles = [
        'dashboard'=>['Dashboard','Your health at a glance'],
        'profile'  =>['My Profile','Personal health details'],
        'logs'     =>['Glucose Logs','All readings'],
        'add_log'  =>['Add Reading','Log a new glucose test'],
        'vault'    =>['Report Vault','Medical files'],
        'journal'  =>['Health Journal','Notes & observations'],
        'settings' =>['Settings','Preferences & security'],
      ];
      $pt = $titles[$page] ?? ['Glycotrack',''];
      ?>
      <h2><?= $pt[0] ?></h2>
      <p><?= $pt[1] ?> &bull; <?= h($patient['first_name'].' '.$patient['last_name']) ?></p>
    </div>
    <div class="tb-right">
      <div class="ist-clock" id="istClock" title="Indian Standard Time"></div>
      <a href="?page=add_log" class="btn btn-gold btn-sm"><?= icon('add',15,'#fff') ?> <span class="tb-add-label">Add Reading</span></a>
      <div class="user-chip">
        <div class="uc-av"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
        <span class="tb-uname"><?= h($_SESSION['username']) ?></span>
      </div>
      <button class="hamburger" onclick="toggleSidebar()"><?= icon('menu',22) ?></button>
    </div>
  </div>

  <div class="content">
<?php
// ── PAGE CONTENT ─────────────────────────────────────────────
switch ($page) {

// ═══════════════════════════════════════════ DASHBOARD
case 'dashboard': default:
    $lastLog = $db->query("SELECT * FROM glucose_logs ORDER BY logged_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    list($lstatus,$lcolor) = $lastLog ? glucoseStatus($lastLog['level'],$patient) : ['No data','#999'];
    $recentLogs = $db->query("SELECT * FROM glucose_logs ORDER BY logged_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($stats['total'] === 0): ?>
<div class="alert al-warning"><?= icon('alert',16) ?> No glucose readings yet. <a href="?page=add_log" style="color:inherit;font-weight:700">Add your first reading</a> to see your health dashboard.</div>
<?php endif; ?>

<!-- STATS -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="si"><?= icon('drop',42,($lcolor)) ?></div>
    <div class="sl">Last Reading</div>
    <div class="sv" style="color:<?= $lcolor ?>"><?= $stats['last'] ? $stats['last'].' mg/dL' : '—' ?></div>
    <div class="ss"><?= $lstatus ?></div>
  </div>
  <div class="stat-card sc-navy">
    <div class="si"><?= icon('activity',42) ?></div>
    <div class="sl">Average Glucose</div>
    <div class="sv"><?= $stats['avg'] ?: '—' ?></div>
    <div class="ss">mg/dL all time</div>
  </div>
  <div class="stat-card sc-info">
    <div class="si"><?= icon('lab',42) ?></div>
    <div class="sl">Est. HbA1c</div>
    <div class="sv"><?= $stats['a1c'] ? $stats['a1c'].'%' : '—' ?></div>
    <div class="ss">Based on avg glucose</div>
  </div>
  <div class="stat-card sc-green">
    <div class="si"><?= icon('target',42) ?></div>
    <div class="sl">In Target Range</div>
    <div class="sv"><?= $stats['in_range'] ?>%</div>
    <div class="ss"><?= $patient['target_low'] ?>–<?= $patient['target_high'] ?> mg/dL</div>
  </div>
  <div class="stat-card sc-orange">
    <div class="si"><?= icon('fire',42) ?></div>
    <div class="sl">Logging Streak</div>
    <div class="sv"><?= $stats['streak'] ?></div>
    <div class="ss"><?= $stats['streak']==1?'day':'days' ?> in a row</div>
  </div>
  <div class="stat-card">
    <div class="si"><?= icon('trending',42) ?></div>
    <div class="sl">Avg Fasting</div>
    <div class="sv"><?= $stats['fasting_avg'] ?: '—' ?></div>
    <div class="ss">mg/dL</div>
  </div>
  <div class="stat-card">
    <div class="si"><?= icon('heart',42) ?></div>
    <div class="sl">Avg Post-Meal</div>
    <div class="sv"><?= $stats['pp_avg'] ?: '—' ?></div>
    <div class="ss">mg/dL</div>
  </div>
  <div class="stat-card sc-red">
    <div class="si"><?= icon('alert',42) ?></div>
    <div class="sl">Highest / Lowest</div>
    <div class="sv"><?= $stats['max'] ?: '—' ?> / <?= $stats['min'] ?: '—' ?></div>
    <div class="ss">mg/dL recorded</div>
  </div>
</div>

<!-- CHARTS ROW -->
<div class="card mb-5" style="margin-bottom:20px;">
  <div class="card-head">
    <h3><?= icon('chart',17,'var(--navy)') ?> 30-Day Glucose Trend</h3>
    <div class="flex gap-2">
      <div class="chart-tabs">
        <button class="chart-tab active" onclick="showChart('line',this)">Line</button>
        <button class="chart-tab" onclick="showChart('bar',this)">Bar</button>
      </div>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:14px;">
    <?php if (count($trend30) > 8): ?><p class="chart-hint">↔ Swipe / scroll to see all <?=count($trend30)?> days</p><?php endif; ?>
    <div id="chart-line"><?= renderLineChart($trend30,$patient) ?></div>
    <div id="chart-bar" style="display:none"><?= renderBarChart($trend30,$patient) ?></div>
  </div>
</div>

<div class="grid-2">
  <!-- IN RANGE DONUT + BREAKDOWN -->
  <div class="card">
    <div class="card-head"><h3><?= icon('target',17,'var(--navy)') ?> Reading Breakdown</h3></div>
    <div class="card-body">
      <div class="flex items-center gap-3 flex-wrap">
        <?= renderDonut($stats['in_range'], 110) ?>
        <div style="flex:1;min-width:140px;">
          <?php foreach ($typeBreakdown as $tb):
            $tc=['fasting'=>'bg-blue','postprandial'=>'bg-orange','random'=>'bg-navy','bedtime'=>'bg-grey'][$tb['reading_type']]??'bg-grey';
          ?>
          <div class="flex items-center justify-between" style="margin-bottom:7px;">
            <span class="badge <?= $tc ?>"><?= ucfirst($tb['reading_type']) ?></span>
            <span class="text-sm text-muted"><?= $tb['cnt'] ?> readings &bull; avg <?= $tb['avg'] ?> mg/dL</span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($typeBreakdown)): ?><p class="text-muted text-sm">No data yet</p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- QUICK HEALTH SUMMARY -->
  <div class="card">
    <div class="card-head"><h3><?= icon('heart',17,'var(--navy)') ?> Health Summary</h3><a href="?page=profile" class="btn btn-sm btn-outline">View Profile</a></div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-tile"><div class="it-l">BMI</div><div class="it-v" style="color:<?= bmiColor($patient['bmi']) ?>"><?= $patient['bmi'] ?> <small style="font-size:12px;color:var(--muted)"><?= $patient['bmi_category'] ?></small></div></div>
        <div class="info-tile"><div class="it-l">Age</div><div class="it-v"><?= $age ?? '—' ?> yrs</div></div>
        <div class="info-tile"><div class="it-l">Type</div><div class="it-v" style="font-size:12px;line-height:1.3"><?= h($patient['diabetes_type']) ?></div></div>
        <div class="info-tile"><div class="it-l">Doctor</div><div class="it-v" style="font-size:13px"><?= h($patient['doctor_name'] ?: 'Not set') ?></div></div>
      </div>
      <div style="margin-top:14px;">
        <div class="text-xs text-muted" style="margin-bottom:5px;">Target adherence <?= $stats['in_range'] ?>% (<?= $patient['target_low'] ?>–<?= $patient['target_high'] ?> mg/dL)</div>
        <div class="prog-bar"><div class="prog-fill" style="width:<?= $stats['in_range'] ?>%;background:<?= $stats['in_range']>=70?'var(--success)':($stats['in_range']>=50?'var(--warning)':'var(--danger)') ?>"></div></div>
      </div>
    </div>
  </div>
</div>

<!-- RECENT LOGS -->
<div class="card mt-5">
  <div class="card-head"><h3><?= icon('logs',17,'var(--navy)') ?> Recent Readings</h3><a href="?page=logs" class="btn btn-sm btn-outline">View All</a></div>
  <?php if (empty($recentLogs)): ?>
  <div class="empty-state"><div class="ei"><?= icon('activity',52) ?></div><h3>No readings yet</h3><p>Start tracking your glucose to populate this dashboard.</p><a href="?page=add_log" class="btn btn-gold mt-4">Add First Reading</a></div>
  <?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Level</th><th>Status</th><th>Type</th><th>Time</th><th>Method</th><th>Mood</th></tr></thead>
    <tbody>
    <?php foreach ($recentLogs as $log):
      list($st,$sc) = glucoseStatus($log['level'],$patient);
      $tb = ['fasting'=>'bg-blue','postprandial'=>'bg-orange','random'=>'bg-navy','bedtime'=>'bg-grey'][$log['reading_type']]??'bg-navy';
    ?>
    <tr>
      <td><strong style="color:<?=$sc?>;font-size:15px"><?=$log['level']?></strong> <span class="text-xs text-muted">mg/dL</span></td>
      <td><span class="badge" style="background:<?=$sc?>22;color:<?=$sc?>"><?=$st?></span></td>
      <td><span class="badge badge-sharp <?=$tb?>"><?=ucfirst($log['reading_type'])?><?=($log['reading_type']==='postprandial'&&$log['meal_gap_minutes'])?' ('.$log['meal_gap_minutes'].'min)':''?></span></td>
      <td class="text-sm" style="white-space:nowrap"><?=$log['test_time']?date('h:i A',strtotime($log['test_time'])):'—'?><br><span class="text-muted"><?=date('d M',strtotime($log['logged_at']))?></span></td>
      <td class="text-sm"><?=h($log['test_method'])?><?=$log['lab_name']?'<br><span class="text-xs text-muted">'.h($log['lab_name']).'</span>':''?></td>
      <td class="text-sm text-muted"><?=h($log['mood']?:'—')?><?=$log['energy_level']?'<br><span class="text-xs">Energy '.$log['energy_level'].'/5</span>':''?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php break;

// ═══════════════════════════════════════════ PROFILE
case 'profile':
    $age = calcAge($patient['dob']);
    $initials = strtoupper(substr($patient['first_name'],0,1).substr($patient['last_name'],0,1));
    $diagSince = $patient['diagnosis_date'] ? floor((time()-strtotime($patient['diagnosis_date']))/86400/365.25*10)/10 : '?';
    if (isset($_GET['saved'])): ?>
<div class="alert al-success"><?= icon('check',16) ?> Profile updated successfully.</div>
<?php endif; ?>

<div class="profile-banner">
  <div class="pb-avatar"><?=$initials?></div>
  <div class="pb-name"><?=h($patient['first_name'].' '.$patient['last_name'])?></div>
  <div class="pb-sub">Age <?=$age??'—'?> &bull; <?=h($patient['sex'])?> &bull; <?=h($patient['diabetes_type'])?></div>
  <div class="pb-chips">
    <span class="pb-chip">BMI <?=$patient['bmi']?> — <?=$patient['bmi_category']?></span>
    <span class="pb-chip">Dr. <?=h($patient['doctor_name']?:'N/A')?></span>
    <span class="pb-chip">Diagnosed <?=$patient['diagnosis_date']?></span>
    <span class="pb-chip">Living with diabetes <?=$diagSince?> yrs</span>
  </div>
</div>

<!-- EDIT FORM -->
<div class="card">
  <div class="card-head"><h3><?= icon('edit',17,'var(--navy)') ?> Edit Profile</h3></div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="action" value="update_profile">
      <div class="form-grid">
        <div class="fg"><label>First Name <span class="req">*</span></label><input type="text" name="first_name" required value="<?=h($patient['first_name'])?>"></div>
        <div class="fg"><label>Last Name <span class="req">*</span></label><input type="text" name="last_name" required value="<?=h($patient['last_name'])?>"></div>
        <div class="fg">
          <label>Date of Birth <span class="req">*</span></label>
          <input type="date" name="dob" required value="<?=h($patient['dob'])?>" id="editDob" onchange="updateAgeDisplay()">
          <span class="hint" id="ageDisplay">Age: <?=$age??'—'?> years (auto-calculated)</span>
        </div>
        <div class="fg"><label>Sex</label><select name="sex"><option <?=$patient['sex']==='Male'?'selected':''?>>Male</option><option <?=$patient['sex']==='Female'?'selected':''?>>Female</option><option <?=$patient['sex']==='Other'?'selected':''?>>Other</option></select></div>
        <div class="fg"><label>Weight (kg) <span class="req">*</span></label><input type="number" name="weight_kg" required step="0.1" min="20" max="300" value="<?=$patient['weight_kg']?>" id="ewkg" oninput="calcEditBMI()"></div>
        <div class="fg">
          <label>Height <span class="req">*</span></label>
          <div style="display:flex;gap:8px;">
            <input type="number" name="height_ft" id="ehft" required min="1" max="8" placeholder="Ft" value="<?=$patient['height_ft']?>" style="width:50%" oninput="calcEditBMI()">
            <input type="number" name="height_in" id="ehin" required min="0" max="11" placeholder="In" value="<?=$patient['height_in']?>" style="width:50%" oninput="calcEditBMI()">
          </div>
        </div>
        <div class="fg">
          <div class="bmi-box" id="editBmiBox">
            <div class="bv" id="editBmiVal"><?=$patient['bmi']?></div>
            <div class="bc" id="editBmiCat"><?=$patient['bmi_category']?></div>
          </div>
        </div>
        <div class="fg"><label>Doctor Name</label><input type="text" name="doctor_name" value="<?=h($patient['doctor_name'])?>"></div>
        <div class="fg"><label>Doctor Contact / Clinic</label><input type="text" name="doctor_contact" value="<?=h($patient['doctor_contact'])?>"></div>
        <div class="fg"><label>Diagnosis Date</label><input type="date" name="diagnosis_date" value="<?=h($patient['diagnosis_date'])?>"></div>
        <div class="fg"><label>Diabetes Type</label><select name="diabetes_type"><option value="Type 1 (No Insulin Dependency)" <?=(strpos($patient['diabetes_type'],'Type 1')===0)?'selected':''?>>Type 1 — No Insulin Dependency</option><option value="Type 2 (Insulin Resistance)" <?=(strpos($patient['diabetes_type'],'Type 2')===0)?'selected':''?>>Type 2 — Insulin Resistance</option><option value="LADA" <?=$patient['diabetes_type']==='LADA'?'selected':''?>>LADA</option><option value="Gestational" <?=$patient['diabetes_type']==='Gestational'?'selected':''?>>Gestational</option><option value="Pre-diabetic" <?=$patient['diabetes_type']==='Pre-diabetic'?'selected':''?>>Pre-diabetic</option></select></div>
        <div class="fg"><label>Ketones at Diagnosis</label><div class="radio-group"><label class="radio-opt"><input type="radio" name="ketones" value="Yes" <?=$patient['ketones']==='Yes'?'checked':''?>> Yes</label><label class="radio-opt"><input type="radio" name="ketones" value="No" <?=$patient['ketones']==='No'?'checked':''?>> No</label><label class="radio-opt"><input type="radio" name="ketones" value="Unknown" <?=$patient['ketones']==='Unknown'?'checked':''?>> Unknown</label></div></div>
      </div>
      <hr class="divider">
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary"><?= icon('save',16,'#fff') ?> Save Changes</button>
        <a href="?page=profile" class="btn btn-outline">Cancel</a>
      </div>
    </form>
  </div>
</div>

<!-- STATS TILE -->
<div class="card mt-5">
  <div class="card-head"><h3><?= icon('activity',17,'var(--navy)') ?> Lifetime Statistics</h3></div>
  <div class="card-body">
    <div class="info-grid">
      <div class="info-tile"><div class="it-l">Total Readings</div><div class="it-v"><?=$stats['total']?></div></div>
      <div class="info-tile"><div class="it-l">Overall Avg</div><div class="it-v"><?=$stats['avg']?:' — '?> mg/dL</div></div>
      <div class="info-tile"><div class="it-l">Est. HbA1c</div><div class="it-v"><?=$stats['a1c']?$stats['a1c'].'%':'—'?></div></div>
      <div class="info-tile"><div class="it-l">Avg Fasting</div><div class="it-v"><?=$stats['fasting_avg']?:' — '?> mg/dL</div></div>
      <div class="info-tile"><div class="it-l">Avg Post-Meal</div><div class="it-v"><?=$stats['pp_avg']?:' — '?> mg/dL</div></div>
      <div class="info-tile"><div class="it-l">In Range</div><div class="it-v"><?=$stats['in_range']?>%</div></div>
      <div class="info-tile"><div class="it-l">Highest</div><div class="it-v" style="color:var(--danger)"><?=$stats['max']?:' — '?> mg/dL</div></div>
      <div class="info-tile"><div class="it-l">Lowest</div><div class="it-v" style="color:var(--warning)"><?=$stats['min']?:' — '?> mg/dL</div></div>
      <div class="info-tile"><div class="it-l">Logging Streak</div><div class="it-v"><?=$stats['streak']?> days</div></div>
      <div class="info-tile"><div class="it-l">Reports in Vault</div><div class="it-v"><?=$stats['vault_count']?></div></div>
    </div>
    <div style="margin-top:12px;padding:12px 14px;background:var(--cream);border-radius:var(--r-sm);">
      <div class="text-sm text-muted"><strong>Symptoms at Diagnosis:</strong> <?=h($patient['symptoms'])?></div>
    </div>
  </div>
</div>
<script>
function calcEditBMI(){
  var w=parseFloat(document.getElementById('ewkg').value);
  var ft=parseInt(document.getElementById('ehft').value)||0;
  var inch=parseInt(document.getElementById('ehin').value)||0;
  if(!w||(!ft&&!inch))return;
  var hm=((ft*30.48)+(inch*2.54))/100;
  var bmi=Math.round((w/(hm*hm))*10)/10;
  var cat=bmi<18.5?'Underweight':bmi<25?'Normal Weight':bmi<30?'Overweight':'Obese';
  document.getElementById('editBmiVal').textContent=bmi;
  document.getElementById('editBmiCat').textContent=cat;
}
function updateAgeDisplay(){
  var dob=document.getElementById('editDob').value;
  if(!dob)return;
  var diff=new Date()-new Date(dob);
  var age=Math.floor(diff/31557600000);
  document.getElementById('ageDisplay').textContent='Age: '+age+' years (auto-calculated)';
}
</script>
<?php break;

// ═══════════════════════════════════════════ ADD LOG
case 'add_log':
    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
    $editLog = null;
    if ($editId) {
        $editLog = $db->prepare("SELECT * FROM glucose_logs WHERE id=?");
        $editLog->execute([$editId]);
        $editLog = $editLog->fetch(PDO::FETCH_ASSOC);
    }
    $v = $editLog ?? [];
?>
<div class="card" style="max-width:720px">
  <div class="card-head"><h3><?= icon('add',17,'var(--navy)') ?> <?= $editLog ? 'Edit Reading' : 'Add Glucose Reading' ?></h3></div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="action" value="<?= $editLog ? 'edit_log' : 'add_log' ?>">
      <?php if ($editLog): ?><input type="hidden" name="log_id" value="<?= $editId ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="fg">
          <label>Glucose Level (mg/dL) <span class="req">*</span></label>
          <input type="number" name="level" id="gLevel" required step="0.1" min="10" max="700" placeholder="e.g. 120" value="<?=h($v['level']??'')?>" oninput="previewStatus(this.value)">
          <div id="statusPrev" style="margin-top:5px;font-size:13px;font-weight:600;min-height:18px"></div>
        </div>
        <div class="fg"><label>Date of Test <span class="req">*</span></label><input type="date" name="log_date" required value="<?= isset($v['logged_at']) ? h(date('Y-m-d',strtotime($v['logged_at']))) : date('Y-m-d') ?>"></div>
        <div class="fg"><label>Time of Test (IST) <span class="req">*</span></label><input type="time" name="test_time" required value="<?= isset($v['test_time']) ? h($v['test_time']) : date('H:i') ?>"></div>
        <div class="fg full">
          <label>Reading Type <span class="req">*</span></label>
          <div class="radio-group">
            <label class="radio-opt"><input type="radio" name="reading_type" value="fasting" <?=($v['reading_type']??'')==='fasting'?'checked':''?> required onchange="togglePP(false)"> Fasting</label>
            <label class="radio-opt"><input type="radio" name="reading_type" value="postprandial" <?=($v['reading_type']??'')==='postprandial'?'checked':''?> onchange="togglePP(true)"> Post-Meal</label>
            <label class="radio-opt"><input type="radio" name="reading_type" value="random" <?=($v['reading_type']??'')==='random'?'checked':''?> onchange="togglePP(false)"> Random</label>
            <label class="radio-opt"><input type="radio" name="reading_type" value="bedtime" <?=($v['reading_type']??'')==='bedtime'?'checked':''?> onchange="togglePP(false)"> Bedtime</label>
          </div>
        </div>
        <div class="fg" id="ppGroup" style="display:<?= ($v['reading_type']??'')==='postprandial'?'flex':'none' ?>;flex-direction:column;gap:5px">
          <label>Time After Meal (minutes)</label>
          <input type="number" name="meal_gap" id="mealGap" min="0" max="360" placeholder="e.g. 120" value="<?=h($v['meal_gap_minutes']??'')?>">
        </div>
        <div class="fg full">
          <label>Test Method <span class="req">*</span></label>
          <div class="radio-group">
            <label class="radio-opt"><input type="radio" name="test_method" value="Glucometer" <?=($v['test_method']??'')==='Glucometer'?'checked':''?> required onchange="toggleLab(false)"> Glucometer</label>
            <label class="radio-opt"><input type="radio" name="test_method" value="Lab Test" <?=($v['test_method']??'')==='Lab Test'?'checked':''?> onchange="toggleLab(true)"> Lab Blood Test</label>
          </div>
        </div>
        <div class="fg" id="labGroup" style="display:<?= ($v['test_method']??'')==='Lab Test'?'flex':'none' ?>;flex-direction:column;gap:5px">
          <label>Lab / Hospital Name</label>
          <input type="text" name="lab_name" placeholder="e.g. Dr. Lal PathLabs" value="<?=h($v['lab_name']??'')?>">
        </div>
        <div class="fg"><label>Symptoms Noticed</label><input type="text" name="symptoms" placeholder="e.g. Headache, dizziness, none" value="<?=h($v['symptoms']??'')?>"></div>
        <div class="fg">
          <label>Mood</label>
          <select name="mood">
            <option value="">Select mood</option>
            <?php foreach (['Great','Good','Okay','Tired','Stressed','Anxious','Unwell'] as $m): ?>
            <option <?=($v['mood']??'')===$m?'selected':''?>><?=$m?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Energy Level (1–5)</label>
          <select name="energy_level">
            <option value="">Select</option>
            <?php for ($e=1;$e<=5;$e++): ?>
            <option value="<?=$e?>" <?=($v['energy_level']??'')==$e?'selected':''?>><?=$e?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="fg full"><label>Notes</label><textarea name="notes" placeholder="Any observations..."><?=h($v['notes']??'')?></textarea></div>
      </div>
      <hr class="divider">
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary"><?= icon('save',15,'#fff') ?> <?= $editLog ? 'Update' : 'Save' ?> Reading</button>
        <a href="?page=logs" class="btn btn-outline">Cancel</a>
      </div>
    </form>
  </div>
</div>
<script>
var tL=<?=$patient['target_low']?>,tH=<?=$patient['target_high']?>;
function previewStatus(v){
  v=parseFloat(v);var el=document.getElementById('statusPrev');
  if(!v){el.textContent='';return;}
  if(v<tL){el.style.color='#e67e22';el.textContent='Low — below '+tL+' mg/dL';}
  else if(v>tH){el.style.color='#c0392b';el.textContent='High — above '+tH+' mg/dL';}
  else{el.style.color='#27ae60';el.textContent='In target range ('+tL+'–'+tH+' mg/dL)';}
}
function togglePP(show){document.getElementById('ppGroup').style.display=show?'flex':'none';if(!show)document.getElementById('mealGap').value='';}
function toggleLab(show){document.getElementById('labGroup').style.display=show?'flex':'none';}
window.addEventListener('load',function(){
  var lv=document.getElementById('gLevel').value;if(lv)previewStatus(lv);
  <?php if(($v['reading_type']??'')==='postprandial'): ?>togglePP(true);<?php endif; ?>
  <?php if(($v['test_method']??'')==='Lab Test'): ?>toggleLab(true);<?php endif; ?>
});
</script>
<?php break;

// ═══════════════════════════════════════════ LOGS
case 'logs':
    if (isset($_GET['added'])): ?><div class="alert al-success"><?=icon('check',16)?> Reading saved.</div><?php endif; ?>
<?php if (isset($_GET['edited'])): ?><div class="alert al-success"><?=icon('check',16)?> Reading updated.</div><?php endif; ?>
<?php
    $filter = $_GET['filter'] ?? 'all';
    $allowed = ['fasting','postprandial','random','bedtime'];
    if (in_array($filter, $allowed)) {
        $stmt = $db->prepare("SELECT * FROM glucose_logs WHERE reading_type=? ORDER BY logged_at DESC");
        $stmt->execute([$filter]);
        $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $filter = 'all';
        $allLogs = $db->query("SELECT * FROM glucose_logs ORDER BY logged_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
?>
<div class="flex items-center justify-between flex-wrap gap-2" style="margin-bottom:14px;">
  <div class="flex gap-2 flex-wrap">
    <?php foreach (['all'=>'All','fasting'=>'Fasting','postprandial'=>'Post-Meal','random'=>'Random','bedtime'=>'Bedtime'] as $k=>$lbl): ?>
    <a href="?page=logs&filter=<?=$k?>" class="btn btn-sm <?=$filter===$k?'btn-primary':'btn-outline'?>"><?=$lbl?></a>
    <?php endforeach; ?>
  </div>
  <div class="flex gap-2">
    <a href="?page=add_log" class="btn btn-gold btn-sm"><?=icon('add',14,'#fff')?> Add</a>
    <a href="?action=export_csv" class="btn btn-sm btn-outline"><?=icon('export',14)?> CSV</a>
  </div>
</div>

<?php if (empty($allLogs)): ?>
<div class="card"><div class="empty-state"><div class="ei"><?=icon('logs',52)?></div><h3>No readings found</h3><p>Log your first glucose reading to begin tracking.</p><a href="?page=add_log" class="btn btn-gold mt-4">Add Reading</a></div></div>
<?php else: ?>
<div class="card">
  <div class="tbl-wrap"><table>
    <thead><tr><th>#</th><th>Level</th><th>Status</th><th>Type</th><th>Time &amp; Date</th><th>Method</th><th>Mood</th><th class="col-hide-sm">Notes / Symptoms</th><th class="col-actions">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($allLogs as $i => $log):
      list($st,$sc)=glucoseStatus($log['level'],$patient);
      $tb=['fasting'=>'bg-blue','postprandial'=>'bg-orange','random'=>'bg-navy','bedtime'=>'bg-grey'][$log['reading_type']]??'bg-navy';
      $notesFull = trim(($log['symptoms']?:'').($log['symptoms']&&$log['notes']?' · ':'').($log['notes']?:''));
      if(!$notesFull) $notesFull='—';
      $notesShort = mb_strlen($notesFull)>40 ? mb_substr($notesFull,0,40).'…' : $notesFull;
    ?>
    <tr>
      <td class="text-xs text-muted"><?=$i+1?></td>
      <td><strong style="color:<?=$sc?>;font-size:15px"><?=$log['level']?></strong> <span class="text-xs text-muted">mg/dL</span></td>
      <td><span class="badge" style="background:<?=$sc?>22;color:<?=$sc?>"><?=$st?></span></td>
      <td><span class="badge <?=$tb?>"><?=ucfirst($log['reading_type'])?><?=($log['reading_type']==='postprandial'&&$log['meal_gap_minutes'])?' ('.$log['meal_gap_minutes'].'m)':''?></span></td>
      <td class="text-sm" style="white-space:nowrap"><?=$log['test_time']?date('h:i A',strtotime($log['test_time'])):'—'?><br><span class="text-muted text-xs"><?=date('d M Y',strtotime($log['logged_at']))?></span></td>
      <td class="text-sm"><?=h($log['test_method'])?><?=$log['lab_name']?'<br><span class="text-xs text-muted">'.h($log['lab_name']).'</span>':''?></td>
      <td class="text-sm"><?=h($log['mood']?:'—')?><?=$log['energy_level']?'<br><span class="text-xs text-muted">E:'.$log['energy_level'].'/5</span>':''?></td>
      <td class="text-sm col-hide-sm" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?=h($notesFull)?>"><?=h($notesShort)?></td>
      <td class="col-actions">
        <div class="flex gap-2">
          <a href="?page=add_log&edit=<?=$log['id']?>" class="btn btn-xs btn-outline"><?=icon('edit',13)?></a>
          <a href="?action=delete_log&id=<?=$log['id']?>" class="btn btn-xs btn-danger" onclick="return confirm('Delete this reading?')"><?=icon('trash',13,'#fff')?></a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; break;

// ═══════════════════════════════════════════ VAULT
case 'vault':
    if (isset($_GET['uploaded'])): ?><div class="alert al-success"><?=icon('check',16)?> Report uploaded successfully.</div><?php endif; ?>
<?php
    $vaultFiles = $db->query("SELECT * FROM vault_files ORDER BY uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $reportTypes = ['HbA1c','Fasting Blood Sugar','Postprandial Blood Sugar','KFT (Kidney Function Test)','LFT (Liver Function Test)','Lipid Profile','CBC (Complete Blood Count)','Urine Test','Thyroid Panel','Eye Exam Report','ECG','X-Ray','Other'];
?>
<div class="grid-2" style="align-items:start;">
  <div class="card">
    <div class="card-head"><h3><?=icon('vault',17,'var(--navy)')?> Upload Report</h3></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_vault">
        <div class="fg" style="margin-bottom:14px"><label>Report File (PDF or JPG) <span class="req">*</span></label><input type="file" name="report_file" accept=".pdf,.jpg,.jpeg" required><span class="hint">PDF and JPG/JPEG only</span></div>
        <div class="fg" style="margin-bottom:14px">
          <label>Report Type <span class="req">*</span></label>
          <select name="report_type" id="reportTypeSel" required onchange="document.getElementById('customTypeWrap').style.display=this.value==='__custom__'?'flex':'none'">
            <option value="">Select type</option>
            <?php foreach($reportTypes as $rt): ?><option><?=$rt?></option><?php endforeach; ?>
            <option value="__custom__">+ Custom (type your own)</option>
          </select>
        </div>
        <div class="fg" id="customTypeWrap" style="display:none;margin-bottom:14px"><label>Custom Report Name</label><input type="text" name="report_type_custom" placeholder="e.g. Vitamin D Test"></div>
        <div class="form-grid" style="margin-bottom:14px">
          <div class="fg"><label>Report Date <span class="req">*</span></label><input type="date" name="report_date" required value="<?=date('Y-m-d')?>"></div>
          <div class="fg"><label>Report Time (IST)</label><input type="time" name="report_time" value="<?=date('H:i')?>"></div>
        </div>
        <div class="fg" style="margin-bottom:16px"><label>Notes</label><input type="text" name="vault_notes" placeholder="e.g. 3-month follow-up HbA1c"></div>
        <button type="submit" class="btn btn-primary"><?=icon('download',15,'#fff')?> Upload to Vault</button>
      </form>
    </div>
  </div>

  <div>
    <?php if (empty($vaultFiles)): ?>
    <div class="card"><div class="empty-state"><div class="ei"><?=icon('vault',52)?></div><h3>Vault is empty</h3><p>Upload medical reports — HbA1c, KFT, lipid profiles, etc.</p></div></div>
    <?php else: ?>
    <div class="vault-grid">
      <?php foreach ($vaultFiles as $vf):
        $isPdf = strtolower($vf['file_type']) === 'pdf';
      ?>
      <div class="vault-item">
        <div class="vi-icon"><?=$isPdf?icon('file',40,'var(--navy)'):icon('image',40,'var(--navy)')?></div>
        <div class="vi-name"><?=h($vf['report_type'])?></div>
        <div class="vi-meta"><?=h(date('d M Y',strtotime($vf['report_date'])))?><?=$vf['report_time']?' · '.h(date('h:i A',strtotime($vf['report_time']))):''?><br><?=h(substr($vf['original_name'],0,24)).(strlen($vf['original_name'])>24?'…':'')?><?=$vf['notes']?'<br><em>'.h($vf['notes']).'</em>':''?></div>
        <div class="vi-actions">
          <button type="button" class="btn btn-xs btn-outline" onclick="openVaultView(<?=$vf['id']?>,'<?=$isPdf?'pdf':'img'?>','<?=vaultToken($vf['id'])?>')"><?=icon('image',12)?></button>
          <a href="?action=download_vault&id=<?=$vf['id']?>" class="btn btn-xs btn-outline"><?=icon('download',12)?></a>
          <a href="?action=delete_vault&id=<?=$vf['id']?>" class="btn btn-xs btn-danger" onclick="return confirm('Delete this file permanently?')"><?=icon('trash',12,'#fff')?></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- VAULT VIEWER MODAL -->
<div class="modal-bg" id="vaultModal">
  <div class="modal-box" style="max-width:760px">
    <div class="modal-head"><h3><?=icon('vault',16,'var(--navy)')?> Report Viewer</h3><button class="modal-close" onclick="closeVaultView()"><?=icon('close',18)?></button></div>
    <div class="modal-body" style="padding:0;min-height:60vh;">
      <div id="vaultViewFrame" style="width:100%;height:70vh;"></div>
    </div>
  </div>
</div>
<script>
function openVaultView(id,type,token){
  var f=document.getElementById('vaultViewFrame');
  var base=window.location.origin+window.location.pathname;
  var publicUrl=base+'?action=public_vault&id='+id+'&token='+token;
  if (type==='pdf'){
    var gview='https://docs.google.com/viewer?url='+encodeURIComponent(publicUrl)+'&embedded=true';
    f.innerHTML='<iframe src="'+gview+'" style="width:100%;height:70vh;border:none;"></iframe>'
      +'<p style="text-align:center;font-size:12px;color:var(--muted);padding:8px;">PDF not loading? '
      +'<a href="'+publicUrl+'" target="_blank" style="color:var(--gold);font-weight:600;">Open it directly</a>'
      +' — Google\'s viewer needs this server to be reachable from the internet.</p>';
  } else {
    f.innerHTML='<img src="'+publicUrl+'" style="max-width:100%;max-height:70vh;display:block;margin:0 auto;">';
  }
  document.getElementById('vaultModal').classList.add('open');
}
function closeVaultView(){
  document.getElementById('vaultModal').classList.remove('open');
  document.getElementById('vaultViewFrame').innerHTML='';
}
</script>
<?php break;

// ═══════════════════════════════════════════ JOURNAL
case 'journal':
    if (isset($_GET['added'])): ?><div class="alert al-success"><?=icon('check',16)?> Journal entry added.</div><?php endif; ?>
<?php
    $journalEntries = $db->query("SELECT * FROM journal ORDER BY logged_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $cats = ['general'=>'General','symptoms'=>'Symptoms','medication'=>'Medication','diet'=>'Diet','exercise'=>'Exercise','mood'=>'Mood','doctor'=>'Doctor Visit'];
    $catColors = ['general'=>'bg-navy','symptoms'=>'bg-red','medication'=>'bg-blue','diet'=>'bg-green','exercise'=>'bg-green','mood'=>'bg-orange','doctor'=>'bg-blue'];
?>
<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h3><?=icon('journal',17,'var(--navy)')?> New Entry</h3></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_journal">
        <div class="fg" style="margin-bottom:14px"><label>Category</label><select name="category"><?php foreach($cats as $k=>$lbl): ?><option value="<?=$k?>"><?=$lbl?></option><?php endforeach; ?></select></div>
        <div class="fg" style="margin-bottom:16px"><label>Entry <span class="req">*</span></label><textarea name="entry" required placeholder="What did you notice today? Any symptoms, dietary changes, doctor advice, mood..." style="min-height:110px"></textarea></div>
        <button type="submit" class="btn btn-primary"><?=icon('save',15,'#fff')?> Save Entry</button>
      </form>
    </div>
  </div>

  <div>
    <?php if (empty($journalEntries)): ?>
    <div class="card"><div class="empty-state"><div class="ei"><?=icon('journal',52)?></div><h3>No journal entries</h3><p>Keep notes about symptoms, diet, medication, and doctor visits.</p></div></div>
    <?php else: ?>
    <?php foreach ($journalEntries as $je):
      $cc = $catColors[$je['category']] ?? 'bg-navy';
    ?>
    <div class="journal-item">
      <div class="flex items-center justify-between" style="margin-bottom:6px;flex-wrap:wrap;gap:6px;">
        <div class="flex gap-2 items-center">
          <span class="badge <?=$cc?>"><?=$cats[$je['category']]??$je['category']?></span>
          <span class="text-xs text-muted"><?=date('d M Y, h:i A',strtotime($je['logged_at']))?></span>
        </div>
        <a href="?action=delete_journal&id=<?=$je['id']?>" class="btn btn-xs btn-danger" onclick="return confirm('Delete entry?')"><?=icon('trash',12,'#fff')?></a>
      </div>
      <p class="text-sm"><?=nl2br(h($je['entry']))?></p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php break;

// ═══════════════════════════════════════════ SETTINGS
case 'settings':
    $tab = $_GET['tab'] ?? 'targets';
    $pwErr = $_SESSION['pw_err'] ?? ''; $pwOk = $_SESSION['pw_ok'] ?? false;
    unset($_SESSION['pw_err'], $_SESSION['pw_ok']);
?>
<?php if (isset($_GET['saved'])): ?><div class="alert al-success"><?=icon('check',16)?> Settings saved.</div><?php endif; ?>

<div class="flex gap-2" style="margin-bottom:16px;flex-wrap:wrap;">
  <a href="?page=settings&tab=targets" class="btn btn-sm <?=$tab==='targets'?'btn-primary':'btn-outline'?>"><?=icon('target',14)?> Glucose Targets</a>
  <a href="?page=settings&tab=security" class="btn btn-sm <?=$tab==='security'?'btn-primary':'btn-outline'?>"><?=icon('lock',14)?> Security</a>
  <a href="?page=settings&tab=about" class="btn btn-sm <?=$tab==='about'?'btn-primary':'btn-outline'?>"><?=icon('dashboard',14)?> About</a>
</div>

<?php if ($tab === 'targets'): ?>
<div class="card" style="max-width:500px">
  <div class="card-head"><h3><?=icon('target',17,'var(--navy)')?> Glucose Targets</h3></div>
  <div class="card-body">
    <p class="text-sm text-muted" style="margin-bottom:16px">Readings outside this range will be flagged on your dashboard and logs.</p>
    <form method="POST">
      <input type="hidden" name="action" value="update_targets">
      <div class="fg" style="margin-bottom:14px"><label>Low Threshold (mg/dL)</label><input type="number" name="target_low" value="<?=$patient['target_low']?>" min="40" max="100" step="1"><span class="hint">Below this = Low. ADA recommended minimum ~70</span></div>
      <div class="fg" style="margin-bottom:16px"><label>High Threshold (mg/dL)</label><input type="number" name="target_high" value="<?=$patient['target_high']?>" min="120" max="400" step="1"><span class="hint">Above this = High. ADA post-meal target &lt;180</span></div>
      <button type="submit" class="btn btn-primary"><?=icon('save',15,'#fff')?> Save Targets</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'security'): ?>
<div class="card" style="max-width:500px">
  <div class="card-head"><h3><?=icon('lock',17,'var(--navy)')?> Change Password</h3></div>
  <div class="card-body">
    <?php if ($pwErr): ?><div class="alert al-danger"><?=icon('alert',16)?> <?=h($pwErr)?></div><?php endif; ?>
    <?php if ($pwOk): ?><div class="alert al-success"><?=icon('check',16)?> Password changed successfully.</div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="fg" style="margin-bottom:14px"><label>Current Password <span class="req">*</span></label><input type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="fg" style="margin-bottom:14px"><label>New Password <span class="req">*</span></label><input type="password" name="new_password" required minlength="6" autocomplete="new-password"><span class="hint">Minimum 6 characters</span></div>
      <div class="fg" style="margin-bottom:16px"><label>Confirm New Password <span class="req">*</span></label><input type="password" name="new_password2" required autocomplete="new-password"></div>
      <button type="submit" class="btn btn-primary"><?=icon('lock',15,'#fff')?> Update Password</button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="card" style="max-width:640px">
  <div class="card-head"><h3><?=icon('dashboard',17,'var(--navy)')?> App Information</h3></div>
  <div class="card-body">
    <div class="info-grid">
      <div class="info-tile"><div class="it-l">App</div><div class="it-v">Glycotrack</div></div>
      <div class="info-tile"><div class="it-l">Version</div><div class="it-v">1.1.2</div></div>
      <div class="info-tile"><div class="it-l">Database</div><div class="it-v">SQLite 3</div></div>
      <div class="info-tile"><div class="it-l">Font</div><div class="it-v">Noto Sans</div></div>
      <div class="info-tile"><div class="it-l">Developer</div><div class="it-v">Vineet Pratap Singh</div></div>
      <div class="info-tile"><div class="it-l">GitHub</div><div class="it-v"><a href="https://github.com/psvineet" target="_blank" style="color:var(--gold)">@psvineet</a></div></div>
      <div class="info-tile full"><div class="it-l">Contact</div><div class="it-v"><a href="mailto:connect.vps@icloud.com" style="color:var(--gold)">connect.vps@icloud.com</a></div></div>
    </div>
    <div class="alert al-warning" style="margin-top:16px"><?=icon('alert',16)?> <strong>Disclaimer:</strong> Glycotrack is a personal record-keeping tool only, not a medical device. Always consult your physician for medical decisions.</div>
  </div>
</div>
<?php endif; break; } // end switch ?>

  </div><!-- content -->
</div><!-- main -->
</div><!-- app -->

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('active');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('active');}

// IST Clock — single row
(function istClock(){
  var el=document.getElementById('istClock');
  if(!el)return;
  function tick(){
    var now=new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Kolkata'}));
    var h=now.getHours(),m=now.getMinutes();
    var ampm=h>=12?'PM':'AM';
    var hh=h%12||12;
    var mm=String(m).padStart(2,'0');
    var d=now.getDate();
    var mo=now.toLocaleString('en-IN',{month:'short',timeZone:'Asia/Kolkata'});
    el.innerHTML=
      '<span class="ic-time">'+hh+':'+mm+' '+ampm+'</span>'+
      '<span class="ic-sep">·</span>'+
      '<span class="ic-date">'+d+' '+mo+' IST</span>';
  }
  tick();setInterval(tick,1000);
})();
function showChart(type,btn){
  document.getElementById('chart-line').style.display=type==='line'?'':'none';
  document.getElementById('chart-bar').style.display=type==='bar'?'':'none';
  document.querySelectorAll('.chart-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
}

// Chart point tooltip — value only shows on hover/click/tap, not by default
(function(){
  var tip = document.createElement('div');
  tip.id = 'chartTip';
  tip.style.cssText = 'position:fixed;display:none;background:var(--navy);color:#fff;font-size:12px;font-weight:600;padding:6px 10px;border-radius:6px;pointer-events:none;z-index:500;box-shadow:0 4px 14px rgba(0,0,0,.22);white-space:nowrap;';
  document.body.appendChild(tip);
  function showTip(el, x, y){
    tip.textContent = el.getAttribute('data-v') + '  ·  ' + el.getAttribute('data-d');
    tip.style.left = (x+12)+'px';
    tip.style.top  = (y-12)+'px';
    tip.style.display = 'block';
  }
  function hideTip(){ tip.style.display = 'none'; }
  document.addEventListener('mouseover', function(e){
    if (e.target.classList && e.target.classList.contains('chart-pt')) showTip(e.target, e.clientX, e.clientY);
  });
  document.addEventListener('mousemove', function(e){
    if (e.target.classList && e.target.classList.contains('chart-pt')) showTip(e.target, e.clientX, e.clientY);
  });
  document.addEventListener('mouseout', function(e){
    if (e.target.classList && e.target.classList.contains('chart-pt')) hideTip();
  });
  document.addEventListener('click', function(e){
    if (e.target.classList && e.target.classList.contains('chart-pt')) {
      var rect = e.target.getBoundingClientRect();
      showTip(e.target, rect.left+rect.width/2, rect.top);
      e.stopPropagation();
    } else { hideTip(); }
  });
})();
</script>
</body>
</html>
