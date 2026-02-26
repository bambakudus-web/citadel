<?php
// register.php
session_start();
require_once 'includes/db.php';

// Already logged in? Redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: pages/student/dashboard.php');
    exit;
}

$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName    = trim($_POST['full_name']  ?? '');
    $indexNo     = trim($_POST['index_no']   ?? '');
    $email       = trim($_POST['email']      ?? '') ?: ($indexNo . '@citadel.edu');
    $password    = $_POST['password']        ?? '';
    $confirm     = $_POST['confirm']         ?? '';
    $fingerprint = $_POST['device_fingerprint'] ?? '';

    if (empty($fullName) || empty($indexNo) || empty($password)) {
        $error = 'Full name, index number and password are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check if index number already exists
        $check = $pdo->prepare("SELECT id FROM users WHERE index_no=? OR email=?");
        $check->execute([$indexNo, $email]);
        if ($check->fetch()) {
            $error = 'This index number or email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (full_name, index_no, email, password_hash, role, device_fingerprint) VALUES (?,?,?,?,'student',?)");
                $stmt->execute([$fullName, $indexNo, $email, $hash, $fingerprint]);
                $success = 'Account created! You can now sign in.';
            } catch (Exception $e) {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Register</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#080b12;--surface:#0e1420;--border:#1e2a3a;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050}
    html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
    .bg-scene{position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.15) 0%,transparent 70%),radial-gradient(ellipse 40% 40% at 80% 80%,rgba(201,168,76,.06) 0%,transparent 60%),var(--bg)}
    .grid-lines{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(74,111,165,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(74,111,165,.05) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse 80% 80% at 50% 0%,black 20%,transparent 100%)}
    .page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
    .card{width:100%;max-width:460px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.4rem;position:relative;animation:fadeUp .7s ease both}
    .card::before,.card::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
    .card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
    .card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
    @keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
    .brand{text-align:center;margin-bottom:1.8rem}
    .brand-icon{width:44px;height:44px;margin:0 auto .8rem}
    .brand-icon svg{width:100%;height:100%}
    .brand-name{font-family:'Cinzel',serif;font-size:1.5rem;font-weight:700;letter-spacing:.18em;color:var(--gold)}
    .brand-sub{font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-top:.25rem}
    .divider{display:flex;align-items:center;gap:.8rem;margin-bottom:1.5rem}
    .divider span{height:1px;flex:1;background:var(--border)}
    .divider em{font-style:normal;font-size:.62rem;letter-spacing:.2em;color:var(--muted);text-transform:uppercase}
    .alert{padding:.65rem .9rem;border-radius:2px;font-size:.8rem;margin-bottom:1.2rem}
    .alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
    .alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    .field{margin-bottom:1rem;position:relative}
    .field label{display:block;font-size:.68rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
    .field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.72rem 2.5rem .72rem 1rem;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.92rem;outline:none;transition:border-color .2s}
    .field input:focus{border-color:var(--steel)}
    .field input::placeholder{color:var(--muted)}
    .field input.valid{border-color:var(--success)}
    .field input.invalid{border-color:var(--danger)}
    .toggle-pw{position:absolute;right:.75rem;top:2.1rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.85rem}
    .strength-bar{height:3px;background:var(--border);border-radius:2px;margin-top:.3rem;overflow:hidden}
    .strength-fill{height:100%;border-radius:2px;width:0%;transition:width .4s,background .4s}
    .field-hint{font-size:.65rem;margin-top:.25rem}
    .btn-primary{width:100%;padding:.82rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#080b12;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.5rem}
    .btn-primary:hover{opacity:.88;transform:translateY(-1px)}
    .card-footer{text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted)}
    .card-footer a{color:var(--gold);text-decoration:none}
    @media(max-width:500px){.form-row{grid-template-columns:1fr}.card{padding:1.8rem 1.2rem}}
  </style>
</head>
<body>
<div class="bg-scene"></div>
<div class="grid-lines"></div>
<div class="page">
  <div class="card">
    <div class="brand">
      <div class="brand-icon">
        <svg viewBox="0 0 52 52" fill="none">
          <polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/>
          <polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/>
          <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
          <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
          <line x1="26" y1="27" x2="26" y2="31" stroke="#c9a84c" stroke-width="1.5"/>
        </svg>
      </div>
      <div class="brand-name">CITADEL</div>
      <div class="brand-sub">Create Account</div>
    </div>

    <div class="divider"><span></span><em>Student Registration</em><span></span></div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <div style="text-align:center;margin-top:.5rem">
        <a href="login.php" style="color:var(--gold);font-size:.85rem">→ Go to Sign In</a>
      </div>
    <?php else: ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="device_fingerprint" id="fp">

      <div class="form-row">
        <div class="field">
          <label>Full Name</label>
          <input type="text" name="full_name" placeholder="Surname, Firstname" required oninput="validateName(this)">
        </div>
        <div class="field">
          <label>Index Number</label>
          <input type="text" name="index_no" placeholder="e.g. 52430540001" required oninput="validateIndex(this)">
        </div>
      </div>

      <div class="field">
        <label>Email <span style="color:var(--muted);font-size:.65rem;text-transform:none;letter-spacing:0">(optional)</span></label>
        <input type="email" name="email" placeholder="auto-generated if left blank">
      </div>

      <div class="field">
        <label>Password</label>
        <input type="password" name="password" id="pw" placeholder="Min. 8 characters" required oninput="checkStrength(this.value);checkMatch()">
        <button type="button" class="toggle-pw" onclick="togglePw('pw',this)">👁</button>
        <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
        <div class="field-hint" id="strength-label"></div>
      </div>

      <div class="field">
        <label>Confirm Password</label>
        <input type="password" name="confirm" id="pw2" placeholder="Repeat password" required oninput="checkMatch()">
        <button type="button" class="toggle-pw" onclick="togglePw('pw2',this)">👁</button>
        <div class="field-hint" id="match-label"></div>
      </div>

      <button type="submit" class="btn-primary">Create Account</button>
    </form>

    <?php endif; ?>

    <div class="card-footer">Already have an account? <a href="login.php">Sign in here</a></div>
  </div>
</div>

<script>
async function getFingerprint() {
  const raw = [navigator.userAgent, navigator.language, screen.width, screen.height, new Date().getTimezoneOffset()].join('|');
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2,'0')).join('');
}
getFingerprint().then(v => { const e = document.getElementById('fp'); if(e) e.value = v; });

function togglePw(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

function checkStrength(val) {
  const fill = document.getElementById('strength-fill');
  const label = document.getElementById('strength-label');
  let score = 0;
  if (val.length >= 8) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const levels = [
    {w:'25%', bg:'var(--danger)',  text:'Weak — add numbers & symbols'},
    {w:'50%', bg:'var(--warning)', text:'Fair — add uppercase letters'},
    {w:'75%', bg:'var(--gold)',    text:'Good — almost there!'},
    {w:'100%',bg:'var(--success)', text:'Strong password ✓'},
  ];
  const lvl = val.length ? levels[Math.max(0,score-1)] : {w:'0%',bg:'',text:''};
  fill.style.width = lvl.w; fill.style.background = lvl.bg;
  label.textContent = lvl.text; label.style.color = lvl.bg;
}

function checkMatch() {
  const pw = document.getElementById('pw').value;
  const pw2 = document.getElementById('pw2').value;
  const label = document.getElementById('match-label');
  if (!pw2) { label.textContent = ''; return; }
  if (pw === pw2) { label.textContent = '✓ Passwords match'; label.style.color = 'var(--success)'; }
  else { label.textContent = '✗ Passwords do not match'; label.style.color = 'var(--danger)'; }
}

function validateName(input) {
  input.classList.toggle('valid', input.value.trim().length > 2);
  input.classList.toggle('invalid', input.value.trim().length > 0 && input.value.trim().length <= 2);
}

function validateIndex(input) {
  const valid = /^\d{10,14}$/.test(input.value.trim());
  input.classList.toggle('valid', valid);
  input.classList.toggle('invalid', !valid && input.value.length > 0);
}
</script>
</body>
</html>
