<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/brevo_mail.php';

$step    = 'request'; // request | sent | reset | done
$error   = '';
$success = '';
$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Add reset token columns if missing
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL");
} catch(Exception $e) {}

// ── If token in URL → show reset form ──
if ($token && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE reset_token=? AND reset_expires > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) {
        $error = 'This reset link has expired or is invalid. Please request a new one.';
        $step  = 'request';
    } else {
        $step = 'reset';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: Send reset email
    if ($action === 'send_reset') {
        $identifier = trim($_POST['identifier'] ?? '');
        $schoolCode = strtolower(trim($_POST['school_code'] ?? ''));

        if (!$identifier) {
            $error = 'Please enter your email or index number.';
        } else {
            // Find institution
            $inst = null;
            if ($schoolCode) {
                $si = $pdo->prepare("SELECT id FROM institutions WHERE slug=? LIMIT 1");
                $si->execute([$schoolCode]);
                $inst = $si->fetch();
            }

            $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE (email=? OR index_no=?)" . ($inst ? " AND institution_id=?" : "") . " LIMIT 1");
            $params = [$identifier, $identifier];
            if ($inst) $params[] = $inst['id'];
            $stmt->execute($params);
            $user = $stmt->fetch();

            if (!$user || !$user['email']) {
                $error = 'No account found with that email or index number.';
            } else {
                // Generate token
                $resetToken = bin2hex(random_bytes(32));
                $expires    = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")->execute([$resetToken, $expires, $user['id']]);

                // Detect base URL
                $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $proto . '://' . $_SERVER['HTTP_HOST'];

                $result = sendPasswordResetEmail($user['email'], $user['full_name'], $resetToken, $baseUrl);

                if ($result['success']) {
                    $step = 'sent';
                    $success = 'Reset link sent to ' . substr($user['email'], 0, 3) . str_repeat('*', strpos($user['email'],'@')-3) . substr($user['email'], strpos($user['email'],'@'));
                } else {
                    $error = 'Failed to send email. Please contact your administrator.';
                    error_log('Brevo error: ' . json_encode($result));
                }
            }
        }
    }

    // Step 2: Set new password
    if ($action === 'do_reset') {
        $token    = trim($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';

        $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Token expired. Please request a new reset link.';
            $step  = 'request';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
            $step  = 'reset';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
            $step  = 'reset';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL, is_locked=0, login_attempts=0 WHERE id=?")->execute([$hash, $user['id']]);
            $step    = 'done';
            $success = 'Password reset successfully!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;padding:1rem}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 0%,rgba(74,111,165,.15) 0%,transparent 70%);pointer-events:none}
.box{background:var(--surface);border:1px solid var(--border);border-radius:2px;width:100%;max-width:420px;position:relative;z-index:1}
.box::before,.box::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
.box::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
.box::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
.box-head{padding:2rem 2rem 1.5rem;text-align:center;border-bottom:1px solid var(--border)}
.logo{display:flex;align-items:center;justify-content:center;gap:.8rem;margin-bottom:.8rem}
.logo-name{font-family:'Cinzel',serif;font-size:1.2rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
.box-title{font-family:'Cinzel',serif;font-size:.82rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase}
.box-body{padding:1.8rem 2rem}
.ff{margin-bottom:1.1rem}
.ff label{display:block;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.42rem}
.ff input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.72rem 1rem;font-family:'DM Sans',sans-serif;font-size:.9rem;border-radius:2px;outline:none;transition:border-color .2s}
.ff input:focus{border-color:var(--gold)}
.btn{width:100%;padding:.8rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s;display:block;text-align:center;text-decoration:none}
.btn:hover{opacity:.85}
.alert{padding:.65rem .9rem;border-radius:2px;font-size:.8rem;margin-bottom:1.1rem}
.err{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
.ok{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
.back{display:block;text-align:center;margin-top:1.1rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .2s}
.back:hover{color:var(--gold)}
.hint{font-size:.78rem;color:var(--muted);margin-bottom:1.4rem;line-height:1.6}
.sent-icon{font-size:3rem;text-align:center;margin-bottom:1rem}
</style>
</head>
<body>
<div class="box">
  <div class="box-head">
    <div class="logo">
      <svg viewBox="0 0 52 52" fill="none" width="36" height="36"><polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/><rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/><circle cx="26" cy="25" r="2" fill="#c9a84c"/></svg>
      <div class="logo-name">CITADEL</div>
    </div>
    <div class="box-title">Password Reset</div>
  </div>
  <div class="box-body">
    <?php if($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if($step === 'request'): ?>
      <p class="hint">Enter your school code and email or index number. We'll send a reset link to your registered email.</p>
      <form method="POST">
        <input type="hidden" name="action" value="send_reset">
        <div class="ff"><label>School Code</label><input type="text" name="school_code" placeholder="e.g. KTU" style="text-transform:uppercase;font-family:'Cinzel',serif;letter-spacing:.15em"></div>
        <div class="ff"><label>Email or Index Number</label><input type="text" name="identifier" placeholder="your@email.com or index no." autofocus></div>
        <button type="submit" class="btn">Send Reset Link</button>
      </form>

    <?php elseif($step === 'sent'): ?>
      <div class="sent-icon">📧</div>
      <div class="alert ok"><?= htmlspecialchars($success) ?></div>
      <p class="hint" style="text-align:center">Check your inbox for the reset link. It expires in 1 hour.<br><br>Don't see it? Check your spam folder.</p>

    <?php elseif($step === 'reset'): ?>
      <p class="hint">Enter your new password below.</p>
      <form method="POST">
        <input type="hidden" name="action" value="do_reset">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="ff"><label>New Password</label><input type="password" name="password" placeholder="Min. 8 characters" autofocus></div>
        <div class="ff"><label>Confirm Password</label><input type="password" name="confirm" placeholder="Repeat password"></div>
        <button type="submit" class="btn">Set New Password</button>
      </form>

    <?php elseif($step === 'done'): ?>
      <div style="text-align:center;padding:1rem 0">
        <div style="font-size:3rem;margin-bottom:1rem">✓</div>
        <div class="alert ok"><?= htmlspecialchars($success) ?></div>
        <p class="hint" style="text-align:center">You can now sign in with your new password.</p>
        <a href="index.php" class="btn">Go to Login</a>
      </div>
    <?php endif; ?>

    <a href="index.php" class="back">← Back to Login</a>
  </div>
</div>
</body>
</html>
