<?php
require_once 'includes/db.php';

$step    = 1;
$error   = '';
$success = '';
$userId  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: Verify index number
    if ($action === 'verify_index') {
        $index = trim($_POST['index_no'] ?? '');
        if (!$index) {
            $error = 'Please enter your index number.';
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE index_no=?");
            $stmt->execute([$index]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = 'Index number not found.';
            } else {
                $step   = 2;
                $userId = $user['id'];
                $userName = $user['full_name'];
            }
        }
    }

    // Step 2: Set new password
    if ($action === 'reset_password') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';

        if (!$userId) {
            $error = 'Invalid request.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.'; $step = 2;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.'; $step = 2;
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $userId]);
            $success = 'Password reset successfully! You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Reset Password</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
    body{min-height:100vh;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;padding:1rem}
    body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 0%,rgba(74,111,165,.15) 0%,transparent 70%);pointer-events:none}
    .box{background:var(--surface);border:1px solid var(--border);border-radius:2px;width:100%;max-width:420px;position:relative;z-index:1}
    .box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
    .box-head{padding:2rem 2rem 1.5rem;text-align:center;border-bottom:1px solid var(--border)}
    .logo{display:flex;align-items:center;justify-content:center;gap:.8rem;margin-bottom:1rem}
    .logo svg{width:40px;height:40px}
    .logo-name{font-family:'Cinzel',serif;font-size:1.2rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
    .box-title{font-family:'Cinzel',serif;font-size:.85rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase}
    .box-body{padding:1.8rem 2rem}
    .form-field{margin-bottom:1.2rem}
    .form-field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
    .form-field input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.75rem 1rem;font-family:'DM Sans',sans-serif;font-size:.9rem;border-radius:2px;outline:none;transition:border-color .2s}
    .form-field input:focus{border-color:var(--gold)}
    .btn{width:100%;padding:.8rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s}
    .btn:hover{opacity:.85}
    .alert{padding:.7rem 1rem;border-radius:2px;font-size:.82rem;margin-bottom:1.2rem}
    .alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
    .alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
    .back-link{display:block;text-align:center;margin-top:1.2rem;color:var(--muted);text-decoration:none;font-size:.8rem}
    .back-link:hover{color:var(--gold)}
    .user-found{background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.8rem 1rem;margin-bottom:1.2rem;font-size:.85rem;color:var(--gold)}
  </style>
</head>
<body>
<div class="box">
  <div class="box-head">
    <div class="logo">
      <svg viewBox="0 0 52 52" fill="none"><polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/><polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/><rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/><circle cx="26" cy="25" r="2" fill="#c9a84c"/><line x1="26" y1="27" x2="26" y2="31" stroke="#4a6fa5" stroke-width="1.5"/></svg>
      <div class="logo-name">CITADEL</div>
    </div>
    <div class="box-title">Reset Password</div>
  </div>
  <div class="box-body">
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;padding:.8rem">Go to Login</a>
    <?php elseif ($step === 1): ?>
      <p style="font-size:.83rem;color:var(--muted);margin-bottom:1.5rem">Enter your index number to reset your password.</p>
      <form method="POST">
        <input type="hidden" name="action" value="verify_index">
        <div class="form-field">
          <label>Index Number</label>
          <input type="text" name="index_no" placeholder="e.g. 52430540001" required autofocus>
        </div>
        <button type="submit" class="btn">Verify Index Number</button>
      </form>
    <?php elseif ($step === 2): ?>
      <div class="user-found">✓ Found: <?= htmlspecialchars($userName ?? '') ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <div class="form-field">
          <label>New Password</label>
          <input type="password" name="password" placeholder="Min. 8 characters" required autofocus>
        </div>
        <div class="form-field">
          <label>Confirm Password</label>
          <input type="password" name="confirm" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn">Reset Password</button>
      </form>
    <?php endif; ?>
    <a href="login.php" class="back-link">← Back to Login</a>
  </div>
</div>
</body>
</html>
