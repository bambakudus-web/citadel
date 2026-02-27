<?php
// change_password.php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

$user   = currentUser();
$userId = $user['id'];
$msg    = ''; $msgType = '';

// Handle phone update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
    $phone = trim($_POST['phone'] ?? '');
    $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone, $userId]);
    $msg = 'Phone number updated!'; $msgType = 'success';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        $msg = 'All fields are required.'; $msgType = 'error';
    } elseif (strlen($new) < 8) {
        $msg = 'New password must be at least 8 characters.'; $msgType = 'error';
    } elseif ($new !== $confirm) {
        $msg = 'New passwords do not match.'; $msgType = 'error';
    } else {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row && password_verify($current, $row['password_hash'])) {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $userId]);
            $msg = 'Password changed successfully!'; $msgType = 'success';
        } else {
            $msg = 'Current password is incorrect.'; $msgType = 'error';
        }
    }
}

// Determine back link based on role
$backLink = match($user['role'] ?? '') {
    'admin'    => 'pages/admin/dashboard.php',
    'rep'      => 'pages/rep/dashboard.php',
    'lecturer' => 'pages/lecturer/dashboard.php',
    default    => 'pages/student/dashboard.php',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Change Password</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#080b12;--surface:#0e1420;--border:#1e2a3a;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
    html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
    .bg-scene{position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.15) 0%,transparent 70%),var(--bg)}
    .grid-lines{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(74,111,165,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(74,111,165,.05) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse 80% 80% at 50% 0%,black 20%,transparent 100%)}
    .page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
    .card{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.4rem;position:relative;animation:fadeUp .6s ease both}
    .card::before,.card::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
    .card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
    .card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
    @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    .brand{text-align:center;margin-bottom:2rem}
    .brand-name{font-family:'Cinzel',serif;font-size:1.4rem;font-weight:700;letter-spacing:.18em;color:var(--gold)}
    .brand-sub{font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
    .user-info{display:flex;align-items:center;gap:.8rem;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:2px;padding:.8rem 1rem;margin-bottom:1.5rem}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--gold-dim),var(--gold));display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.9rem;font-weight:700;color:#080b12;flex-shrink:0}
    .user-name{font-size:.85rem;color:var(--text);font-weight:500}
    .user-role{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
    .divider{display:flex;align-items:center;gap:.8rem;margin-bottom:1.5rem}
    .divider span{height:1px;flex:1;background:var(--border)}
    .divider em{font-style:normal;font-size:.62rem;letter-spacing:.2em;color:var(--muted);text-transform:uppercase}
    .alert{padding:.65rem .9rem;border-radius:2px;font-size:.8rem;margin-bottom:1.2rem}
    .alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
    .alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
    .field{margin-bottom:1.1rem;position:relative}
    .field label{display:block;font-size:.68rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
    .field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.72rem 2.5rem .72rem 1rem;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.92rem;outline:none;transition:border-color .2s}
    .field input:focus{border-color:var(--steel)}
    .field input::placeholder{color:var(--muted)}
    .toggle-pw{position:absolute;right:.75rem;top:2.1rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.85rem;padding:.2rem}
    .strength-bar{height:3px;background:var(--border);border-radius:2px;margin-top:.4rem;overflow:hidden}
    .strength-fill{height:100%;border-radius:2px;width:0%;transition:width .4s,background .4s}
    .strength-label{font-size:.65rem;color:var(--muted);margin-top:.25rem}
    .btn-primary{width:100%;padding:.82rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#080b12;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.4rem}
    .btn-primary:hover{opacity:.88;transform:translateY(-1px)}
    .back-link{display:block;text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted);text-decoration:none}
    .back-link span{color:var(--gold)}
    .back-link:hover span{opacity:.75}
    @media(max-width:480px){.card{padding:1.8rem 1.2rem}}
  </style>
</head>
<body>
<div class="bg-scene"></div>
<div class="grid-lines"></div>
<div class="page">
  <div class="card">
    <div class="brand">
      <div class="brand-name">CITADEL</div>
      <div class="brand-sub">Change Password</div>
    </div>

    <!-- User info strip -->
    <div class="user-info">
      <div class="user-avatar"><?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="user-role"><?= htmlspecialchars($user['role'] ?? '') ?> · <?= htmlspecialchars($user['index_no'] ?? '') ?></div>
      </div>
    </div>

    <div class="divider"><span></span><em>Secure Update</em><span></span></div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="field">
        <label>Current Password</label>
        <input type="password" name="current_password" id="pw-current" placeholder="Your current password" required>
        <button type="button" class="toggle-pw" onclick="togglePw('pw-current',this)">👁</button>
      </div>

      <div class="field">
        <label>New Password</label>
        <input type="password" name="new_password" id="pw-new" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)">
        <button type="button" class="toggle-pw" onclick="togglePw('pw-new',this)">👁</button>
        <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
        <div class="strength-label" id="strength-label"></div>
      </div>

      <div class="field">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" id="pw-confirm" placeholder="Repeat new password" required oninput="checkMatch()">
        <button type="button" class="toggle-pw" onclick="togglePw('pw-confirm',this)">👁</button>
        <div class="strength-label" id="match-label"></div>
      </div>

      <button type="submit" class="btn-primary">Update Password</button>
    </form>
    <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border)">
      <div style="font-family:Cinzel,serif;font-size:.8rem;color:var(--gold);letter-spacing:.12em;margin-bottom:1rem">WHATSAPP NUMBER</div>
      <form method="POST">
        <input type="hidden" name="phone" value="">
        <div class="field">
          <label>Phone Number</label>
          <input type="text" name="phone" placeholder="+233XXXXXXXXX" value="<?= htmlspecialchars(currentUser()['phone'] ?? '') ?>">
        </div>
        <button type="submit" class="btn-primary" style="margin-top:.5rem">Update Phone</button>
      </form>
    </div>

    <a href="<?= $backLink ?>" class="back-link">← Back to <span>Dashboard</span></a>
  </div>
</div>

<script>
function togglePw(id, btn) {
  const input = document.getElementById(id);
  if (input.type === 'password') { input.type = 'text'; btn.textContent = '🙈'; }
  else { input.type = 'password'; btn.textContent = '👁'; }
}

function checkStrength(val) {
  const fill  = document.getElementById('strength-fill');
  const label = document.getElementById('strength-label');
  let score = 0;
  if (val.length >= 8)  score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const levels = [
    { w: '25%', bg: 'var(--danger)',  text: 'Weak' },
    { w: '50%', bg: 'var(--warning)', text: 'Fair' },
    { w: '75%', bg: 'var(--gold)',    text: 'Good' },
    { w: '100%',bg: 'var(--success)', text: 'Strong' },
  ];
  const lvl = levels[Math.max(0, score - 1)] || levels[0];
  fill.style.width      = val.length ? lvl.w  : '0%';
  fill.style.background = val.length ? lvl.bg : '';
  label.textContent     = val.length ? lvl.text : '';
  label.style.color     = val.length ? lvl.bg : '';
}

function checkMatch() {
  const newPw  = document.getElementById('pw-new').value;
  const conPw  = document.getElementById('pw-confirm').value;
  const label  = document.getElementById('match-label');
  if (!conPw) { label.textContent = ''; return; }
  if (newPw === conPw) { label.textContent = '✓ Passwords match'; label.style.color = 'var(--success)'; }
  else { label.textContent = '✗ Passwords do not match'; label.style.color = 'var(--danger)'; }
}
</script>
</body>
</html>
