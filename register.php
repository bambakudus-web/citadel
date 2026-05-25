<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/brevo_mail.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: pages/student/dashboard.php');
    exit;
}

$error = ''; $success = '';
$step  = 1;
$institution = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: Verify school code
    if ($action === 'verify_school') {
        $schoolCode = strtolower(trim($_POST['school_code'] ?? ''));
        if (!$schoolCode) { $error = 'Enter your school code.'; }
        else {
            $inst = $pdo->prepare("SELECT * FROM institutions WHERE slug=? AND is_active=1");
            $inst->execute([$schoolCode]);
            $institution = $inst->fetch();
            if (!$institution) { $error = 'School code "'.strtoupper($schoolCode).'" not found.'; }
            else { $step = 2; }
        }
    }

    // Step 2: Register
    if ($action === 'register') {
        $schoolCode = strtolower(trim($_POST['school_code'] ?? ''));
        $inst = $pdo->prepare("SELECT * FROM institutions WHERE slug=? AND is_active=1");
        $inst->execute([$schoolCode]); $institution = $inst->fetch();

        if (!$institution) { $error = 'Invalid school code.'; $step = 1; }
        else {
            $fullName    = trim($_POST['full_name'] ?? '');
            $indexNo     = trim($_POST['index_no']  ?? '');
            $email       = trim($_POST['email']      ?? '') ?: ($indexNo.'@'.strtolower($institution['slug']).'.edu.gh');
            $password    = $_POST['password']        ?? '';
            $confirm     = $_POST['confirm']         ?? '';
            $fingerprint = $_POST['device_fingerprint'] ?? '';

            if (!$fullName || !$indexNo || !$password) { $error = 'All fields required.'; $step = 2; }
            elseif (strlen($password) < 8) { $error = 'Password must be 8+ characters.'; $step = 2; }
            elseif ($password !== $confirm) { $error = 'Passwords do not match.'; $step = 2; }
            else {
                $check = $pdo->prepare("SELECT id FROM users WHERE (index_no=? OR email=?) AND institution_id=?");
                $check->execute([$indexNo, $email, $institution['id']]);
                if ($check->fetch()) { $error = 'Index number or email already registered.'; $step = 2; }
                else {
                    // Get selected program
                    $prog = $pdo->prepare("SELECT p.id, p.department_id FROM programs p JOIN departments d ON d.id=p.department_id WHERE p.id=? AND d.institution_id=?");
                    $prog->execute([$programId, $institution['id']]); $prog = $prog->fetch();
                    if (!$prog) { $error = 'Invalid program selected.'; $step = 2; }

                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    try {
                        $pdo->prepare("
                            INSERT INTO users (full_name, index_no, email, password_hash, role, institution_id, department_id, program_id, level, device_fingerprint)
                            VALUES (?,?,?,?,'student',?,?,?,?,?)
                        ")->execute([$fullName, $indexNo, $email, $hash, $institution['id'], $prog['department_id'] ?? null, $prog['id'] ?? null, $level, $fingerprint ?: null]);

                        $newId = $pdo->lastInsertId();

                        // Auto-enroll in active semester courses
                        $activeSem = $pdo->prepare("SELECT id FROM semesters WHERE institution_id=? AND is_active=1 LIMIT 1");
                        $activeSem->execute([$institution['id']]); $activeSem = $activeSem->fetch();

                        if ($activeSem && $prog) {
                            $courses = $pdo->prepare("SELECT id FROM courses WHERE semester_id=? AND program_id=?");
                            $courses->execute([$activeSem['id'], $prog['id']]);
                            $enroll = $pdo->prepare("INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id) VALUES (?,?,?)");
                            foreach ($courses->fetchAll() as $c) {
                                $enroll->execute([$c['id'], $newId, $activeSem['id']]);
                            }
                        }
                        $success = true;
                        // Send welcome email
                        try {
                            $realEmail = $_POST['email'] ?? '';
                            if ($realEmail && filter_var($realEmail, FILTER_VALIDATE_EMAIL)) {
                                sendWelcomeEmail($realEmail, $fullName, $indexNo, $password, $institution['name']);
                            }
                        } catch(Exception $e2) {}
                    } catch (Exception $e) { $error = 'Registration failed. Try again.'; $step = 2; }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Register</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080b12;--surface:#0e1420;--border:#1e2a3a;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.15) 0%,transparent 70%);pointer-events:none}
.page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
.card{width:100%;max-width:460px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.4rem;position:relative;animation:fadeUp .7s ease both}
.card::before,.card::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
.card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
.card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.brand{text-align:center;margin-bottom:1.8rem}
.brand-name{font-family:'Cinzel',serif;font-size:1.5rem;font-weight:700;letter-spacing:.18em;color:var(--gold)}
.brand-sub{font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-top:.25rem}
.school-found{background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.8rem 1rem;margin-bottom:1.2rem;font-size:.85rem}
.school-found strong{color:var(--gold)}
.alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger);padding:.7rem 1rem;border-radius:2px;font-size:.82rem;margin-bottom:1.2rem}
.alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success);padding:.7rem 1rem;border-radius:2px;font-size:.82rem;margin-bottom:1.2rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{margin-bottom:1rem}
.field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.field input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.72rem 1rem;font-family:'DM Sans',sans-serif;font-size:.92rem;border-radius:2px;outline:none;transition:border-color .2s}
.field input:focus{border-color:var(--steel)}
.field input.code-input{text-transform:uppercase;font-family:'Cinzel',serif;letter-spacing:.2em}
.btn{width:100%;padding:.82rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#080b12;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.2em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.5rem}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.footer{text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted)}
.footer a{color:var(--gold);text-decoration:none}
@media(max-width:500px){.form-row{grid-template-columns:1fr}.card{padding:1.8rem 1.2rem}}

/* Safari zoom fix — inputs must be 16px */
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:inherit!important}}
</style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="brand">
      <div class="brand-name">CITADEL</div>
      <div class="brand-sub">Student Registration</div>
    </div>

    <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($success): ?>
      <div class="alert-success">✓ Account created! You can now sign in with school code <strong><?= strtoupper(htmlspecialchars($_POST['school_code'])) ?></strong>.</div>
      <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none">Go to Login</a>

    <?php elseif ($step === 1): ?>
      <form method="POST">
        <input type="hidden" name="action" value="verify_school">
        <div class="field">
          <label>School Code</label>
          <input type="text" name="school_code" class="code-input" placeholder="e.g. KTU" required autofocus value="<?= htmlspecialchars($_POST['school_code'] ?? '') ?>">
        </div>
        <button type="submit" class="btn">Continue →</button>
      </form>

    <?php elseif ($step === 2 && $institution): ?>
      <div class="school-found">📍 Registering at: <strong><?= htmlspecialchars($institution['name']) ?></strong></div>
      <form method="POST">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="school_code" value="<?= htmlspecialchars($_POST['school_code']) ?>">
        <input type="hidden" name="device_fingerprint" id="fp">
        <div class="form-row">
          <div class="field"><label>Full Name</label><input type="text" name="full_name" required placeholder="Surname, Firstname"></div>
          <div class="field"><label>Index Number</label><input type="text" name="index_no" required placeholder="52430540001"></div>
        </div>
        <div class="field"><label>Email (optional)</label><input type="email" name="email" placeholder="auto-generated if blank"></div>
        <div class="form-row">
          <div class="field">
            <label>Program</label>
            <select name="program_id" required style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.72rem 1rem;border-radius:2px;outline:none;font-family:'DM Sans',sans-serif">
              <option value="">Select your program...</option>
              <?php foreach($programs as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> <?= $p['code'] ? '('.$p['code'].')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Year / Level</label>
            <select name="level" required style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.72rem 1rem;border-radius:2px;outline:none;font-family:'DM Sans',sans-serif">
              <option value="1">Year 1</option>
              <option value="2">Year 2</option>
              <option value="3">Year 3</option>
              <option value="4">Year 4</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="field"><label>Password</label><input type="password" name="password" required placeholder="Min. 8 characters"></div>
          <div class="field"><label>Confirm Password</label><input type="password" name="confirm" required placeholder="Repeat password"></div>
        </div>
        <button type="submit" class="btn">Create Account</button>
      </form>
    <?php endif; ?>

    <div class="footer">Already have an account? <a href="login.php">Sign in here</a></div>
  </div>
</div>
<script>
async function fp() {
  const raw = [navigator.userAgent,navigator.language,screen.width,screen.height,new Date().getTimezoneOffset()].join('|');
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
}
fp().then(v => { const e = document.getElementById('fp'); if(e) e.value = v; });
</script>
</body>
</html>
