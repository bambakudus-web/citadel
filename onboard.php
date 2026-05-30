<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/brevo_mail.php';

$error = ''; $success = ''; $schoolCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName  = trim($_POST['school_name']  ?? '');
    $schoolCode  = strtolower(trim($_POST['school_code'] ?? ''));
    $adminName   = trim($_POST['admin_name']   ?? '');
    $adminEmail  = trim($_POST['admin_email']  ?? '');
    $adminPass   = $_POST['admin_password']    ?? '';
    $confirm     = $_POST['confirm_password']  ?? '';
    $phone       = trim($_POST['phone']        ?? '');
    $instType    = in_array($_POST['inst_type']??'university', ['university','shs','jhs','primary','other']) ? $_POST['inst_type'] : 'university';

    if (!$schoolName || !$schoolCode || !$adminName || !$adminEmail || !$adminPass) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[a-z0-9]{2,10}$/', $schoolCode)) {
        $error = 'School code must be 2-10 characters, letters and numbers only.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($adminPass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $pdo->prepare("SELECT id FROM institutions WHERE slug=?");
        $check->execute([$schoolCode]);
        if ($check->fetch()) {
            $error = 'School code "' . strtoupper($schoolCode) . '" is already taken. Choose another.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO institutions (name, slug, email, phone, is_active, plan, inst_type) VALUES (?, ?, ?, ?, 0, 'free', ?)")
                    ->execute([$schoolName, $schoolCode, $adminEmail, $phone ?: null, $instType]);
                $instId = $pdo->lastInsertId();

                // Create default department based on type
                $deptName = match($instType) {
                    'shs'     => 'General',
                    'jhs'     => 'General',
                    'primary' => 'General',
                    default   => 'General'
                };
                $pdo->prepare("INSERT INTO departments (institution_id, name, code) VALUES (?, ?, ?)")
                    ->execute([$instId, $deptName, 'GEN']);
                $deptId = $pdo->lastInsertId();

                // Create default streams/programs based on type
                if ($instType === 'shs') {
                    $streams = [
                        ['General Science', 'SCI', 3],
                        ['General Arts', 'ARTS', 3],
                        ['Business', 'BUS', 3],
                        ['Home Economics', 'HEC', 3],
                        ['Visual Arts', 'VIS', 3],
                    ];
                    foreach ($streams as $s) {
                        $pdo->prepare("INSERT INTO programs (department_id, name, code, duration_yrs) VALUES (?, ?, ?, ?)")
                            ->execute([$deptId, $s[0], $s[1], $s[2]]);
                    }
                } elseif ($instType === 'jhs') {
                    foreach (['JHS 1','JHS 2','JHS 3'] as $cls) {
                        $code = str_replace(' ','',$cls);
                        $pdo->prepare("INSERT INTO programs (department_id, name, code, duration_yrs) VALUES (?, ?, ?, 1)")
                            ->execute([$deptId, $cls, $code]);
                    }
                } elseif ($instType === 'primary') {
                    foreach (['Primary 1','Primary 2','Primary 3','Primary 4','Primary 5','Primary 6'] as $cls) {
                        $code = str_replace(' ','',str_replace('Primary','P',$cls));
                        $pdo->prepare("INSERT INTO programs (department_id, name, code, duration_yrs) VALUES (?, ?, ?, 1)")
                            ->execute([$deptId, $cls, $code]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO programs (department_id, name, code, duration_yrs) VALUES (?, ?, ?, 2)")
                        ->execute([$deptId, 'General Program', 'GEN-P']);
                }

                // Create admin user
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, institution_id, department_id, is_active) VALUES (?, ?, ?, 'admin', ?, ?, 1)")
                    ->execute([$adminName, $adminEmail, $hash, $instId, $deptId]);
                $pdo->commit();
                $success = true;

                // Notify super admins
                try {
                    $superAdmins = $pdo->query("SELECT email, full_name FROM users WHERE role='super_admin' AND is_active=1 LIMIT 5")->fetchAll();
                    foreach ($superAdmins as $sa) {
                        if (!filter_var($sa['email'], FILTER_VALIDATE_EMAIL)) continue;
                        $typeLabel = ['university'=>'University','shs'=>'Senior High School','jhs'=>'Junior High School','primary'=>'Primary School','other'=>'Other'][$instType] ?? $instType;
                        $html = "<div style='font-family:sans-serif;background:#060910;color:#e8eaf0;padding:2rem;border-radius:4px'>
                            <div style='font-family:Georgia,serif;font-size:1.2rem;color:#c9a84c;letter-spacing:4px;margin-bottom:1rem'>CITADEL</div>
                            <h2 style='color:#e8eaf0;margin-bottom:.8rem'>New School Registration</h2>
                            <table style='margin:1rem 0;width:100%'>
                                <tr><td style='color:#6b7a8d;padding:.3rem 0'>School:</td><td style='color:#e8eaf0'><strong>$schoolName</strong></td></tr>
                                <tr><td style='color:#6b7a8d;padding:.3rem 0'>Type:</td><td style='color:#c9a84c'>$typeLabel</td></tr>
                                <tr><td style='color:#6b7a8d;padding:.3rem 0'>Code:</td><td style='color:#c9a84c'><strong>" . strtoupper($schoolCode) . "</strong></td></tr>
                                <tr><td style='color:#6b7a8d;padding:.3rem 0'>Admin:</td><td style='color:#e8eaf0'>$adminName ($adminEmail)</td></tr>
                            </table>
                            <a href='https://citadel-production-5edc.up.railway.app/pages/super_admin/schools.php' style='display:inline-block;background:linear-gradient(135deg,#7a5f28,#c9a84c);color:#060910;padding:12px 24px;border-radius:2px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:2px'>Review & Approve</a>
                        </div>";
                        sendBrevoEmail($sa['email'], $sa['full_name'], "New School Registration: $schoolName", $html);
                    }
                    $pendingHtml = "<div style='font-family:sans-serif;background:#060910;color:#e8eaf0;padding:2rem;border-radius:4px'>
                        <div style='font-family:Georgia,serif;font-size:1.2rem;color:#c9a84c;letter-spacing:4px;margin-bottom:1rem'>CITADEL</div>
                        <h2 style='color:#e8eaf0;margin-bottom:.8rem'>Registration Received</h2>
                        <p style='color:#6b7a8d'>Hi $adminName, your registration for <strong style='color:#e8eaf0'>$schoolName</strong> has been received and is pending approval.</p>
                        <div style='background:#0c1018;border:1px solid #1a2535;border-left:3px solid #c9a84c;padding:1rem;margin-top:1.2rem;border-radius:2px'>
                            <div style='color:#6b7a8d;font-size:.8rem'>School Code</div>
                            <div style='color:#c9a84c;font-size:1.4rem;font-family:Georgia,serif;letter-spacing:4px'>" . strtoupper($schoolCode) . "</div>
                        </div>
                    </div>";
                    sendBrevoEmail($adminEmail, $adminName, 'Citadel Registration Pending Approval', $pendingHtml);
                } catch(Exception $e) {}
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

// Type labels and descriptions
$typeInfo = [
    'university' => [
        'label' => 'University / Polytechnic / College',
        'desc'  => 'For universities, polytechnics, technical colleges and other tertiary institutions.',
        'color' => '#4a6fa5',
        'details' => [
            'Programs & Departments',
            'Semester-based system',
            'Index number for students',
            'Course Rep role',
            'Code + Selfie attendance verification',
        ]
    ],
    'shs' => [
        'label' => 'Senior High School (SHS)',
        'desc'  => 'For SHS schools with Science, Arts, Business and other streams.',
        'color' => '#5a9f7a',
        'details' => [
            'Streams (Science, Arts, Business etc.)',
            'Term-based system',
            'Student ID number',
            'Class Prefect role',
            'Teacher marks attendance from list',
        ]
    ],
    'jhs' => [
        'label' => 'Junior High School (JHS)',
        'desc'  => 'For JHS 1, 2 and 3 classes.',
        'color' => '#8a6fd4',
        'details' => [
            'Classes (JHS 1, JHS 2, JHS 3)',
            'Term-based system',
            'Student ID number',
            'Class Prefect role',
            'Teacher marks attendance from list',
        ]
    ],
    'primary' => [
        'label' => 'Primary School',
        'desc'  => 'For Primary 1 through 6.',
        'color' => '#c9a84c',
        'details' => [
            'Classes (Primary 1-6)',
            'Term-based system',
            'Student ID number',
            'Class Captain role',
            'Teacher marks attendance from list',
        ]
    ],
    'other' => [
        'label' => 'Other Institution',
        'desc'  => 'For any other type of educational institution.',
        'color' => '#6b7a8d',
        'details' => [
            'Flexible structure',
            'Customizable terminology',
            'Standard attendance system',
        ]
    ],
];

$selectedType = $_POST['inst_type'] ?? 'university';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Register Your Institution</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{min-height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.15) 0%,transparent 70%);pointer-events:none}
.page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
.card{width:100%;max-width:580px;background:var(--surface);border:1px solid var(--border);border-radius:2px;position:relative;animation:fadeUp .7s ease both}
.card::before,.card::after{content:'';position:absolute;width:16px;height:16px;border-color:var(--gold);border-style:solid}
.card::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
.card::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.card-head{padding:2rem 2.4rem 1.5rem;border-bottom:1px solid var(--border);text-align:center}
.brand{display:flex;align-items:center;justify-content:center;gap:.8rem;margin-bottom:.8rem}
.brand-name{font-family:'Cinzel',serif;font-size:1.2rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
.card-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--muted);letter-spacing:.1em}
.card-body{padding:2rem 2.4rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{margin-bottom:1rem}
.field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.field input,.field select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.7rem .9rem;font-family:'DM Sans',sans-serif;font-size:.88rem;border-radius:2px;outline:none;transition:border-color .2s}
.field input:focus,.field select:focus{border-color:var(--steel)}
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


.type-features{background:rgba(74,111,165,.06);border:1px solid rgba(74,111,165,.2);border-radius:2px;padding:.8rem 1rem;margin-bottom:1.2rem;display:none}
.type-features.show{display:block}
.type-features-title{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--steel);margin-bottom:.5rem}
.type-features ul{list-style:none;padding:0}
.type-features ul li{font-size:.75rem;color:var(--muted);padding:.2rem 0;display:flex;align-items:center;gap:.4rem}
.type-features ul li::before{content:'';color:var(--success);font-size:.7rem}

/* Form sections that show/hide per type */
.form-section{display:none}
.form-section.show{display:block}

@media(max-width:540px){
  .form-row{grid-template-columns:1fr}
  .card-body{padding:1.5rem}
  .type-grid{grid-template-columns:1fr 1fr}
}
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:inherit!important}}
</style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="card-head">
      <div class="brand">
        <svg viewBox="0 0 52 52" fill="none" width="36" height="36">
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
           Registration submitted! Your institution is pending approval.<br><br>
          Your school code is: <strong><?= strtoupper(htmlspecialchars($schoolCode)) ?></strong><br>
          Share this with your staff and students to log in.
        </div>
        <a href="login.php#login" class="btn" style="display:block;text-align:center;text-decoration:none">Go to Login →</a>
      <?php else: ?>
      <form method="POST" id="onboard-form">

        <!-- STEP 1: Choose institution type -->
        <div class="section-label">Step 1 — Institution Type</div>
        <div class="field">
          <label>What type of institution are you registering?</label>
          <select name="inst_type" id="inst-type-select" onchange="selectType(this.value)" required>
            <option value="university" <?= $selectedType==='university'?'selected':'' ?>> University / Polytechnic / College</option>
            <option value="shs" <?= $selectedType==='shs'?'selected':'' ?>> Senior High School (SHS)</option>
            <option value="jhs" <?= $selectedType==='jhs'?'selected':'' ?>> Junior High School (JHS)</option>
            <option value="primary" <?= $selectedType==='primary'?'selected':'' ?>> Primary School</option>
            <option value="other" <?= $selectedType==='other'?'selected':'' ?>> Other Institution</option>
          </select>
        </div>

        <!-- Features preview per type -->
        <?php foreach($typeInfo as $val => $info): ?>
        <div class="type-features <?= $selectedType===$val?'show':'' ?>" id="feat-<?= $val ?>" style="border-color:<?= $info['color'] ?>33;background:<?= $info['color'] ?>11">
          <div class="type-features-title" style="color:<?= $info['color'] ?>"><?= $info['label'] ?> — What you get:</div>
          <ul><?php foreach($info['details'] as $d): ?><li><?= $d ?></li><?php endforeach; ?></ul>
        </div>
        <?php endforeach; ?>

        <!-- STEP 2: Institution details -->
        <div class="section-label">Step 2 — Institution Details</div>
        <div class="form-row">
          <div class="field">
            <label id="lbl-name">Institution Name</label>
            <input type="text" name="school_name" id="inp-school-name" placeholder="e.g. Kumasi Technical University" required value="<?= htmlspecialchars($_POST['school_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label>School Code</label>
            <input type="text" name="school_code" id="school-code" class="code-input" placeholder="e.g. KTU" maxlength="10" required oninput="updatePreview(this.value)" value="<?= htmlspecialchars($_POST['school_code'] ?? '') ?>">
            <div class="field-hint">2–10 characters, letters & numbers only</div>
          </div>
        </div>
        <div id="preview-wrap" style="display:none" class="preview">
          Staff & students will log in with school code: <strong id="preview-code"></strong>
        </div>
        <div class="form-row">
          <div class="field">
            <label id="lbl-email">Admin Email</label>
            <input type="email" name="admin_email" id="inp-email" placeholder="admin@yourschool.edu" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Phone (optional)</label>
            <input type="text" name="phone" placeholder="+233..." value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>

        <!-- STEP 3: Admin account -->
        <div class="section-label">Step 3 — Admin Account</div>
        <div class="form-row">
          <div class="field">
            <label id="lbl-admin">Your Full Name</label>
            <input type="text" name="admin_name" id="inp-admin" placeholder="Your full name" required value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Password</label>
            <input type="password" name="admin_password" placeholder="Min. 8 characters" required>
          </div>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat password" required>
        </div>

        <button type="submit" class="btn" id="submit-btn">Register Institution →</button>
      </form>
      <div class="footer-link">Already registered? <a href="login.php#login">Sign in here</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
const typeLabels = {
  university: {name:'Institution Name', email:'Admin Email', admin:'Your Full Name', btn:'Register Institution →'},
  shs:        {name:'School Name', email:'Principal\'s Email', admin:'Principal\'s Full Name', btn:'Register School →'},
  jhs:        {name:'School Name', email:'Headmaster\'s Email', admin:'Headmaster\'s Full Name', btn:'Register School →'},
  primary:    {name:'School Name', email:'Headmaster\'s Email', admin:'Headmaster\'s Full Name', btn:'Register School →'},
  other:      {name:'Institution Name', email:'Admin Email', admin:'Your Full Name', btn:'Register →'},
};

function selectType(val) {
  // Update radio
  document.querySelectorAll('input[name=inst_type]').forEach(r => r.checked = r.value === val);
  // Show features
  document.querySelectorAll('.type-features').forEach(f => f.classList.remove('show'));
  document.getElementById('feat-'+val)?.classList.add('show');
  // Update labels
  const t = typeLabels[val] || typeLabels.university;
  document.getElementById('lbl-name').textContent = t.name;
  document.getElementById('lbl-email').textContent = t.email;
  document.getElementById('lbl-admin').textContent = t.admin;
  document.getElementById('submit-btn').textContent = t.btn;
  // Update placeholder
  const placeholders = {
    university: 'e.g. Kumasi Technical University',
    shs: 'e.g. Prempeh College',
    jhs: 'e.g. Roman Hill JHS',
    primary: 'e.g. St. Francis Primary School',
    other: 'e.g. My Institution',
  };
  document.getElementById('inp-school-name').placeholder = placeholders[val] || placeholders.university;
}

function updatePreview(v) {
  const wrap = document.getElementById('preview-wrap');
  const code = document.getElementById('preview-code');
  if (v.length >= 2) { wrap.style.display='block'; code.textContent=v.toUpperCase(); }
  else wrap.style.display='none';
}

// Init on load
selectType('<?= $selectedType ?>');
</script>
</body>
</html>
