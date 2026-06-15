<?php
/**
 * login.php — Admin login + first-time password setup
 *
 * HOW IT WORKS (no technical steps needed):
 * 1. First time you visit this page on Hostinger, it shows a "Set Password" screen.
 * 2. You type your password twice and click Save.
 * 3. It saves securely and takes you to the login screen.
 * 4. Every visit after that shows the normal login form.
 *
 * The password is stored as a secure bcrypt hash in data/admin_password.php
 * That file is never accessible via browser URL (blocked by data/.htaccess).
 */

session_start();

// Where the password hash is stored (outside web root access, blocked by .htaccess)
define('PASS_FILE', __DIR__ . '/../data/admin_password.php');

// ── Is a password already set? ──────────────────────────────────────────────
$passwordIsSet = file_exists(PASS_FILE);

// ── Already logged in → go to dashboard ─────────────────────────────────────
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = '';
$mode    = $passwordIsSet ? 'login' : 'setup'; // 'setup' on first visit, 'login' after

// ── HANDLE FIRST-TIME SETUP (POST) ──────────────────────────────────────────
if ($mode === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $pw1 = $_POST['new_password']      ?? '';
    $pw2 = $_POST['confirm_password']  ?? '';

    if (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match. Please try again.';
    } else {
        $hash = password_hash($pw1, PASSWORD_BCRYPT);
        // Write hash as a PHP file (double protection: even if .htaccess fails, PHP tags prevent raw output)
        $content = "<?php\n// Admin password hash — do not edit manually\ndefine('ADMIN_HASH_STORED', " . var_export($hash, true) . ");\n";
        if (file_put_contents(PASS_FILE, $content) !== false) {
            // Auto-login after setup
            session_regenerate_id(true);
            $_SESSION['admin_logged_in']  = true;
            $_SESSION['admin_login_time'] = time();
            header('Location: index.php');
            exit;
        } else {
            $error = 'Could not save password. Please check that the data/ folder has permission 755 in Hostinger File Manager.';
        }
    }
}

// ── HANDLE LOGIN (POST) ──────────────────────────────────────────────────────
if ($mode === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    require PASS_FILE; // loads ADMIN_HASH_STORED constant
    $submitted = $_POST['password'] ?? '';
    if (password_verify($submitted, ADMIN_HASH_STORED)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in']  = true;
        $_SESSION['admin_login_time'] = time();
        header('Location: index.php');
        exit;
    } else {
        sleep(1); // slow down brute-force attempts
        $error = 'Incorrect password. Try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AIC Admin — <?= $mode === 'setup' ? 'Set Password' : 'Login' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0D0D0D;--s1:#141414;--s2:#1A1A1A;--s3:#222;
  --border:rgba(255,255,255,0.07);--border2:rgba(255,255,255,0.12);
  --text:#E8E8E8;--muted:#666;--accent:#C8FF00;--accent2:#FF5C35;
  --green:#00E676;--red:#FF4444;
  --mono:'IBM Plex Mono',monospace;--sans:'DM Sans',sans-serif;--display:'Syne',sans-serif
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;display:flex;align-items:center;justify-content:center}
.wrap{width:100%;max-width:400px;padding:20px}
.card{background:var(--s1);border:1px solid var(--border);border-radius:14px;padding:36px 32px}
.eyebrow{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--muted);text-transform:uppercase;margin-bottom:6px}
.title{font-family:var(--display);font-size:22px;font-weight:700;color:#fff;margin-bottom:4px}
.title span{color:var(--accent)}
.subtitle{font-size:11px;color:var(--muted);margin-bottom:28px;font-family:var(--mono);line-height:1.6}
.setup-banner{background:rgba(200,255,0,.06);border:1px solid rgba(200,255,0,.15);border-radius:8px;padding:12px 14px;margin-bottom:22px;font-size:11px;line-height:1.6;color:var(--text)}
.setup-banner strong{color:var(--accent);font-family:var(--mono)}
.field{margin-bottom:16px}
.field label{display:block;font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;background:var(--s3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:var(--sans);outline:none;transition:border-color .15s}
.field input:focus{border-color:var(--accent)}
.hint{font-size:10px;color:var(--muted);margin-top:5px;font-family:var(--mono)}
.btn{width:100%;padding:13px;background:var(--accent);color:#000;border:none;border-radius:8px;font-family:var(--display);font-size:13px;font-weight:700;letter-spacing:.5px;cursor:pointer;transition:opacity .2s;margin-top:6px}
.btn:hover{opacity:.85}
.error{background:rgba(255,68,68,.08);border:1px solid rgba(255,68,68,.2);color:var(--red);border-radius:6px;padding:10px 14px;font-size:12px;margin-bottom:16px;font-family:var(--mono);line-height:1.5}
.footer{margin-top:22px;text-align:center;font-size:10px;color:var(--muted);font-family:var(--mono)}
.footer a{color:var(--muted);text-decoration:none}
.footer a:hover{color:var(--text)}
.step-list{list-style:none;padding:0;margin:0}
.step-list li{display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;font-size:11px;color:var(--text)}
.step-num{background:var(--accent);color:#000;font-family:var(--mono);font-size:9px;font-weight:700;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">

    <?php if ($mode === 'setup'): ?>
    <!-- ═══ FIRST-TIME SETUP SCREEN ═══ -->
    <div class="eyebrow">First-Time Setup</div>
    <div class="title">AI <span>Collective</span></div>
    <div class="subtitle">Admin Panel — Rate Card Engine</div>

    <div class="setup-banner">
      <strong>Welcome!</strong> This is the first time you're setting up the admin panel.<br>
      Choose a password below. You'll use this every time you log in.
    </div>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <label>Choose Your Password</label>
        <input type="password" name="new_password" autofocus autocomplete="new-password" placeholder="Min. 8 characters">
        <div class="hint">Use letters, numbers, and symbols. At least 8 characters.</div>
      </div>
      <div class="field">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Type the same password again">
      </div>
      <button type="submit" class="btn">Save Password &amp; Enter Admin Panel</button>
    </form>

    <div class="footer" style="margin-top:20px;text-align:left">
      <ul class="step-list">
        <li><div class="step-num">1</div>Type your password above (write it down somewhere safe)</li>
        <li><div class="step-num">2</div>Type it again to confirm</li>
        <li><div class="step-num">3</div>Click Save — you'll land straight in the admin panel</li>
        <li><div class="step-num">4</div>This screen only appears once — next time you'll see the login form</li>
      </ul>
    </div>

    <?php else: ?>
    <!-- ═══ NORMAL LOGIN SCREEN ═══ -->
    <div class="eyebrow">Admin Access</div>
    <div class="title">AI <span>Collective</span></div>
    <div class="subtitle">Rate Card Engine — Internal Use Only</div>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" autofocus autocomplete="current-password" placeholder="Enter your admin password">
      </div>
      <button type="submit" class="btn">Enter Admin Panel</button>
    </form>

    <div class="footer">
      <a href="../">&#8592; Back to Client View</a>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
