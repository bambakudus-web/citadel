<?php
session_start();
require_once 'includes/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . roleRedirect($_SESSION['role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier  = trim($_POST['identifier'] ?? '');
    $password    = $_POST['password'] ?? '';
    $fingerprint = $_POST['device_fingerprint'] ?? '';
    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your ID and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE index_no=? OR email=? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['device_fingerprint'] && $fingerprint && $user['device_fingerprint'] !== $fingerprint) {
                $error = 'Access denied. This account is registered to another device. Contact admin.';
            } else {
                if ($fingerprint && !$user['device_fingerprint']) {
                    $pdo->prepare("UPDATE users SET device_fingerprint=? WHERE id=?")->execute([$fingerprint, $user['id']]);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['user']    = ['id'=>$user['id'],'full_name'=>$user['full_name'],'index_no'=>$user['index_no'],'email'=>$user['email'],'role'=>$user['role']];
                header('Location: ' . roleRedirect($user['role']));
                exit;
            }
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

function roleRedirect(string $role): string {
    return match($role) {
        'admin'    => 'pages/admin/dashboard.php',
        'rep'      => 'pages/rep/dashboard.php',
        'lecturer' => 'pages/lecturer/dashboard.php',
        default    => 'pages/student/dashboard.php',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Sign In</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#080b12;--surface:#0e1420;--border:#1e2a3a;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--error:#e05c5c}
    html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
    .bg-scene{position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.18) 0%,transparent 70%),radial-gradient(ellipse 40% 40% at 80% 80%,rgba(201,168,76,.07) 0%,transparent 60%),var(--bg)}
    .grid-lines{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(74,111,165,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(74,111,165,.06) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse 80% 80% at 50% 0%,black 20%,transparent 100%)}
    .page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
    .card{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.8rem 2.4rem;position:relative;animation:fadeUp .7s ease both}
    .card::before,.card::after{content:'';position:absolute;width:18px;height:18px;border-color:var(--gold);border-style:solid}
    .card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
    .card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
    .brand{text-align:center;margin-bottom:2.2rem}
    .brand-icon{width:52px;height:52px;margin:0 auto 1rem}
    .brand-icon svg{width:100%;height:100%}
    .brand-name{font-family:'Cinzel',serif;font-size:1.7rem;font-weight:700;letter-spacing:.18em;color:var(--gold);text-shadow:0 0 32px rgba(201,168,76,.3)}
    .brand-sub{font-size:.72rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
    .divider{display:flex;align-items:center;gap:.8rem;margin-bottom:1.8rem}
    .divider span{height:1px;flex:1;background:var(--border)}
    .divider em{font-style:normal;font-size:.65rem;letter-spacing:.2em;color:var(--muted);text-transform:uppercase}
    .field{margin-bottom:1.2rem}
    .field label{display:block;font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
    .field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.75rem 1rem;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.95rem;outline:none;transition:border-color .2s}
    .field input:focus{border-color:var(--steel)}
    .field input::placeholder{color:var(--muted)}
    .error-msg{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--error);font-size:.8rem;padding:.6rem .9rem;border-radius:2px;margin-bottom:1.2rem;display:<?= $error?'block':'none' ?>}
    .btn-primary{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#080b12;font-family:'Cinzel',serif;font-size:.85rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.4rem}
    .btn-primary:hover{opacity:.88;transform:translateY(-1px)}
    .card-footer{text-align:center;margin-top:1.6rem;font-size:.8rem;color:var(--muted)}
    .card-footer a{color:var(--gold);text-decoration:none}
    @keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:480px){.card{padding:2rem 1.4rem}.brand-name{font-size:1.4rem}}
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
      <div class="brand-sub">Attendance &amp; Access System</div>
    </div>

    <div class="divider"><span></span><em>Secure Sign In</em><span></span></div>

    <div class="error-msg"><?= htmlspecialchars($error) ?></div>

    <form method="POST">
      <input type="hidden" name="device_fingerprint" id="fp">
      <div class="field">
        <label>Index Number / Staff ID / Email</label>
        <input type="text" name="identifier" placeholder="e.g. 52430540001" required autocomplete="off">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-primary">Enter Citadel</button>
    </form>
    <a href="reset_password.php" style="display:block;text-align:center;margin-top:1rem;color:var(--muted);text-decoration:none;font-size:.8rem">Forgot password?</a>

    <div class="card-footer">New student? <a href="register.php">Register here</a></div>
  </div>
</div>
<script>
  async function fp(){
    const raw=[navigator.userAgent,navigator.language,screen.width,screen.height,new Date().getTimezoneOffset()].join('|');
    const buf=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(raw));
    return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
  }
  fp().then(v=>{const e=document.getElementById('fp');if(e)e.value=v});
</script>
</body>
</html>
