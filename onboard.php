<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/brevo_mail.php';

$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName  = trim($_POST['school_name']  ?? '');
    $schoolCode  = strtolower(trim($_POST['school_code'] ?? ''));
    $adminName   = trim($_POST['admin_name']   ?? '');
    $adminEmail  = trim($_POST['admin_email']  ?? '');
    $adminPass   = $_POST['admin_password']    ?? '';
    $confirm     = $_POST['confirm_password']  ?? '';
    $phone       = trim($_POST['phone']        ?? '');

    if (!$schoolName || !$schoolCode || !$adminName || !$adminEmail || !$adminPass) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[a-z0-9]{2,10}$/', $schoolCode)) {
        $error = 'School code must be 2-10 characters, letters and numbers only.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($adminPass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check slug uniqueness
        $check = $pdo->prepare("SELECT id FROM institutions WHERE slug=?");
        $check->execute([$schoolCode]);
        if ($check->fetch()) {
            $error = 'School code "' . strtoupper($schoolCode) . '" is already taken. Choose another.';
        } else {
            try {
                $pdo->beginTransaction();

                // Create institution
                $pdo->prepare("
                    INSERT INTO institutions (name, slug, email, phone, is_active, plan)
                    VALUES (?, ?, ?, ?, 1, 'free')
                ")->execute([$schoolName, $schoolCode, $adminEmail, $phone ?: null]);
                $instId = $pdo->lastInsertId();

                // Create default department
                $pdo->prepare("INSERT INTO departments (institution_id, name, code) VALUES (?, ?, ?)")
                    ->execute([$instId, 'General', 'GEN']);
                $deptId = $pdo->lastInsertId();

                // Create default program
                $pdo->prepare("INSERT INTO programs (department_id, name, code, duration_yrs) VALUES (?, ?, ?, 2)")
                    ->execute([$deptId, 'General Program', 'GEN-P']);
                $progId = $pdo->lastInsertId();

                // Create admin user
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, role, institution_id, department_id, is_active)
                    VALUES (?, ?, ?, 'admin', ?, ?, 1)
                ")->execute([$adminName, $adminEmail, $hash, $instId, $deptId]);

                $pdo->commit();
                $success = true;
                // Send welcome email to new admin
                try {
                    $instRow = $pdo->prepare("SELECT name FROM institutions WHERE id=? LIMIT 1");
                    $instRow->execute([$instId]);
                    $instName = $instRow->fetchColumn() ?: $schoolName;
                    sendWelcomeEmail($adminEmail, $adminName, $adminEmail, $adminPass, $instName);
                } catch(Exception $e) { /* non-fatal */ }
            } catch (Exception $e) {
                $pdo->rollBack();
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
<title>Citadel — Register Your School</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{min-height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.15) 0%,transparent 70%);pointer-events:none}
.page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
.card{width:100%;max-width:520px;background:var(--surface);border:1px solid var(--border);border-radius:2px;position:relative;animation:fadeUp .7s ease both}
.card::before,.card::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
.card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
.card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.card-head{padding:2rem 2.4rem 1.5rem;border-bottom:1px solid var(--border);text-align:center}
.brand{display:flex;align-items:center;justify-content:center;gap:.8rem;margin-bottom:.8rem}
.brand svg{width:36px;height:36px}
.brand-name{font-family:'Cinzel',serif;font-size:1.2rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
.card-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--muted);letter-spacing:.1em}
.card-body{padding:2rem 2.4rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{margin-bottom:1rem}
.field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.field input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.7rem .9rem;font-family:'DM Sans',sans-serif;font-size:.88rem;border-radius:2px;outline:none;transition:border-color .2s}
.field input:focus{border-color:var(--steel)}
.field input.code-input{text-transform:uppercase;font-family:'Cinzel',serif;letter-spacing:.2em}
.field-hint{font-size:.68rem;color:var(--muted);margin-top:.3rem}
.section-label{font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin:1.5rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.btn{width:100%;padding:.85rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.85rem;font-weight:700;letter-spacing:.2em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.5rem}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.alert{padding:.7rem 1rem;border-radius:2px;font-size:.82rem;margin-bottom:1.2rem}
.alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
.alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
.preview{background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.15);border-radius:2px;padding:.8rem 1rem;font-size:.78rem;color:var(--muted);margin-bottom:1rem}
.preview strong{color:var(--gold)}
.footer-link{text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted)}
.footer-link a{color:var(--gold);text-decoration:none}
@media(max-width:540px){.form-row{grid-template-columns:1fr}.card-body{padding:1.5rem}}
</style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="card-head">
      <div class="brand">
        <svg viewBox="0 0 52 52" fill="none">
          <polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/>
          <polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/>
          <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
          <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
          <line x1="26" y1="27" x2="26" y2="31" stroke="#c9a84c" stroke-width="1.5"/>
        </svg>
        <div class="brand-name">CITADEL</div>
      </div>
      <div class="card-title">Register Your Institution</div>
    </div>
    <div class="card-body">
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">
          ✓ Your school has been registered successfully!<br><br>
          Your school code is: <strong><?= strtoupper(htmlspecialchars($schoolCode)) ?></strong><br>
          Share this code with your students and staff so they can log in.
        </div>
        <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none">Go to Login →</a>
      <?php else: ?>

      <div class="preview" id="code-preview" style="display:none">
        Students will log in with school code: <strong id="preview-code"></strong>
      </div>

      <form method="POST">
        <div class="section-label">Institution Details</div>
        <div class="form-row">
          <div class="field">
            <label>Institution Name</label>
            <input type="text" name="school_name" placeholder="e.g. Kumasi Technical University" required
              value="<?= htmlspecialchars($_POST['school_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label>School Code</label>
            <input type="text" name="school_code" id="school-code" class="code-input" placeholder="e.g. KTU" maxlength="10" required
              oninput="updatePreview(this.value)" value="<?= htmlspecialchars($_POST['school_code'] ?? '') ?>">
            <div class="field-hint">2-10 characters, letters & numbers only</div>
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Institution Email</label>
            <input type="email" name="admin_email" placeholder="admin@yourschool.edu" required
              value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Phone (optional)</label>
            <input type="text" name="phone" placeholder="+233..." value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>

        <div class="section-label">Admin Account</div>
        <div class="form-row">
          <div class="field">
            <label>Admin Full Name</label>
            <input type="text" name="admin_name" placeholder="Your full name" required
              value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Admin Password</label>
            <input type="password" name="admin_password" placeholder="Min. 8 characters" required>
          </div>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat password" required>
        </div>

        <button type="submit" class="btn">Register Institution</button>
      </form>

      <?php endif; ?>

      <div class="footer-link">Already registered? <a href="login.php">Sign in here</a></div>
    </div>
  </div>
</div>
<script>
function updatePreview(val) {
  const preview = document.getElementById('code-preview');
  const text    = document.getElementById('preview-code');
  if (val.trim()) {
    preview.style.display = 'block';
    text.textContent = val.toUpperCase();
  } else {
    preview.style.display = 'none';
  }
}
// Run on load if value exists
const codeInput = document.getElementById('school-code');
if (codeInput && codeInput.value) updatePreview(codeInput.value);
</script>
</body>
</html>
