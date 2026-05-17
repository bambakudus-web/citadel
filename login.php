<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . roleRedirect($_SESSION['role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolCode  = strtolower(trim($_POST['school_code'] ?? ''));
    $identifier  = trim($_POST['identifier'] ?? '');
    $password    = $_POST['password'] ?? '';
    $fingerprint = $_POST['device_fingerprint'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your ID and password.';
    } else {
        // Super admin — no school code needed
        if (empty($schoolCode) || $schoolCode === 'citadel') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (email=?) AND role='super_admin' LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        } else {
            // Find institution by slug
            $inst = $pdo->prepare("SELECT * FROM institutions WHERE slug=? AND is_active=1 LIMIT 1");
            $inst->execute([$schoolCode]);
            $institution = $inst->fetch();

            if (!$institution) {
                $error = 'School code "' . htmlspecialchars(strtoupper($schoolCode)) . '" not found. Check your school code.';
                goto render;
            }

            // Find user scoped to this institution
            $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE (index_no=? OR email=?)
                AND institution_id=?
                LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier, $institution['id']]);
            $user = $stmt->fetch();
        }

        if ($user) {
            if ($user['is_locked'] ?? false) {
                $error = '🔒 Your account has been locked. Please contact the administrator.';
            } elseif (isset($user['is_active']) && !$user['is_active']) {
                $error = 'Your account has been deactivated. Contact your administrator.';
            } elseif (password_verify($password, $user['password_hash'])) {
                $pdo->prepare("UPDATE users SET login_attempts=0 WHERE id=?")->execute([$user['id']]);

                // Device fingerprint check (skip for admin roles)
                if ($user['device_fingerprint'] && $fingerprint &&
                    $user['device_fingerprint'] !== $fingerprint &&
                    !in_array($user['role'], ['admin','rep','super_admin'])) {
                    $error = 'Access denied. This account is registered to another device.';
                } else {
                    if ($fingerprint && !$user['device_fingerprint']) {
                        $pdo->prepare("UPDATE users SET device_fingerprint=? WHERE id=?")->execute([$fingerprint, $user['id']]);
                    }

                    // Store institution context in session
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['role']           = $user['role'];
                    $_SESSION['institution_id'] = $user['institution_id'] ?? 1;
                    $_SESSION['school_slug']    = $schoolCode ?: 'ktu';
                    $_SESSION['user']           = [
                        'id'             => $user['id'],
                        'full_name'      => $user['full_name'],
                        'index_no'       => $user['index_no'],
                        'email'          => $user['email'],
                        'role'           => $user['role'],
                        'institution_id' => $user['institution_id'] ?? 1,
                        'program_id'     => $user['program_id'] ?? null,
                        'level'          => $user['level'] ?? null,
                    ];
                    header('Location: ' . roleRedirect($user['role']));
                    exit;
                }
            } else {
                $attempts = ($user['login_attempts'] ?? 0) + 1;
                if ($attempts >= 3) {
                    $pdo->prepare("UPDATE users SET login_attempts=?, is_locked=1 WHERE id=?")->execute([$attempts, $user['id']]);
                    $error = '🔒 Account locked after too many failed attempts. Contact admin.';
                } elseif ($attempts == 2) {
                    $pdo->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$attempts, $user['id']]);
                    $error = '⚠ Invalid credentials. 1 more failed attempt will lock your account.';
                } else {
                    $pdo->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$attempts, $user['id']]);
                    $error = 'Invalid credentials. Please try again.';
                }
            }
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

function roleRedirect(string $role): string {
    return match($role) {
        'super_admin' => 'pages/super_admin/dashboard.php',
        'admin'       => 'pages/admin/dashboard.php',
        'rep'         => 'pages/rep/dashboard.php',
        'lecturer'    => 'pages/lecturer/dashboard.php',
        default       => 'pages/student/dashboard.php',
    };
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Citadel — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#c9a84c">
<script>if("serviceWorker"in navigator)navigator.serviceWorker.register("/sw.js");</script>
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
.brand{text-align:center;margin-bottom:2rem}
.brand-icon{width:52px;height:52px;margin:0 auto 1rem}
.brand-icon svg{width:100%;height:100%}
.brand-name{font-family:'Cinzel',serif;font-size:1.7rem;font-weight:700;letter-spacing:.18em;color:var(--gold);text-shadow:0 0 32px rgba(201,168,76,.3)}
.brand-sub{font-size:.72rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
.school-badge{display:none;align-items:center;gap:.6rem;background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.6rem 1rem;margin-bottom:1.2rem;font-size:.82rem;color:var(--gold)}
.school-badge.show{display:flex}
.divider{display:flex;align-items:center;gap:.8rem;margin-bottom:1.5rem}
.divider span{height:1px;flex:1;background:var(--border)}
.divider em{font-style:normal;font-size:.65rem;letter-spacing:.2em;color:var(--muted);text-transform:uppercase}
.field{margin-bottom:1.1rem}
.field label{display:block;font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
.field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.75rem 1rem;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.95rem;outline:none;transition:border-color .2s}
.field input:focus{border-color:var(--steel)}
.field input::placeholder{color:var(--muted)}
.field input.school-input{text-transform:uppercase;letter-spacing:.15em;font-family:'Cinzel',serif;font-size:.9rem}
.error-msg{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--error);font-size:.8rem;padding:.6rem .9rem;border-radius:2px;margin-bottom:1.2rem}
.btn-primary{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#080b12;font-family:'Cinzel',serif;font-size:.85rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.4rem}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.card-footer{text-align:center;margin-top:1.4rem;font-size:.8rem;color:var(--muted)}
.card-footer a{color:var(--gold);text-decoration:none}
.step{display:none}
.step.active{display:block;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
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

    <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['timeout'])): ?>
    <div class="error-msg" style="border-color:rgba(201,168,76,.3);background:rgba(201,168,76,.08);color:var(--gold)">⏱ Session expired. Please sign in again.</div>
    <?php endif; ?>

    <!-- Step 1: School Code -->
    <div class="step active" id="step-school">
      <div class="divider"><span></span><em>Enter School Code</em><span></span></div>
      <div class="field">
        <label>School Code</label>
        <input type="text" id="school-code-input" class="school-input" placeholder="e.g. KTU" maxlength="10" autocomplete="off" autofocus>
        <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem">Enter your institution's unique code. Platform admins use <strong style="color:var(--gold)">CITADEL</strong>.</div>
      </div>
      <button class="btn-primary" onclick="goToLogin()">Continue →</button>
      <div class="card-footer" style="margin-top:1rem">New institution? <a href="onboard.php">Register your school</a></div>
    </div>

    <!-- Step 2: Login -->
    <div class="step" id="step-login">
      <div class="school-badge" id="school-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        <span id="school-badge-text">KTU</span>
        <button onclick="goBack()" style="background:none;border:none;color:var(--muted);cursor:pointer;margin-left:auto;font-size:.75rem">Change</button>
      </div>
      <div class="divider"><span></span><em>Secure Sign In</em><span></span></div>
      <form method="POST" id="login-form">
        <input type="hidden" name="device_fingerprint" id="fp">
        <input type="hidden" name="school_code" id="school-code-hidden">
        <div class="field">
          <label>Index Number / Email</label>
          <input type="text" name="identifier" placeholder="e.g. 52430540001" required autocomplete="off"
            value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
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
</div>

<script>
// If there was a POST error, skip to step 2
<?php if ($error || !empty($_POST['school_code'])): ?>
const savedCode = "<?= htmlspecialchars($_POST['school_code'] ?? '') ?>";
if (savedCode) {
  document.getElementById('step-school').classList.remove('active');
  document.getElementById('step-login').classList.add('active');
  document.getElementById('school-code-hidden').value = savedCode;
  document.getElementById('school-badge-text').textContent = savedCode.toUpperCase();
  document.getElementById('school-badge').classList.add('show');
}
<?php endif; ?>

function goToLogin() {
  const code = document.getElementById('school-code-input').value.trim();
  if (!code) { alert('Please enter your school code'); return; }
  document.getElementById('school-code-hidden').value = code;
  document.getElementById('school-badge-text').textContent = code.toUpperCase();
  document.getElementById('school-badge').classList.add('show');
  document.getElementById('step-school').classList.remove('active');
  document.getElementById('step-login').classList.add('active');
  document.querySelector('#step-login input[name="identifier"]').focus();
}

function goBack() {
  document.getElementById('step-login').classList.remove('active');
  document.getElementById('step-school').classList.add('active');
  document.getElementById('school-code-input').focus();
}

document.getElementById('school-code-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') goToLogin();
});

async function fp() {
  const raw = [navigator.userAgent, navigator.language, screen.width, screen.height, new Date().getTimezoneOffset()].join('|');
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2,'0')).join('');
}
fp().then(v => { const e = document.getElementById('fp'); if(e) e.value = v; });
</script>
</body>
</html>
