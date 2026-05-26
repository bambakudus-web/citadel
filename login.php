<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once 'includes/db.php';

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $map = ['super_admin'=>'super_admin','admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
    header('Location: pages/' . ($map[$role] ?? 'student') . '/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    header('Content-Type: application/json');
    $schoolCode  = strtolower(trim($_POST['school_code'] ?? ''));
    $identifier  = trim($_POST['identifier'] ?? '');
    $password    = $_POST['password'] ?? '';
    $fingerprint = $_POST['device_fingerprint'] ?? '';

    if (!$identifier || !$password) {
        echo json_encode(['ok'=>false,'msg'=>'Please enter your ID and password.']); exit;
    }

    if (empty($schoolCode) || $schoolCode === 'citadel') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='super_admin' LIMIT 1");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
        $institution = null;
    } else {
        $inst = $pdo->prepare("SELECT * FROM institutions WHERE slug=? AND is_active=1 LIMIT 1");
        $inst->execute([$schoolCode]);
        $institution = $inst->fetch();
        if (!$institution) {
            echo json_encode(['ok'=>false,'msg'=>'School code "'.strtoupper(htmlspecialchars($schoolCode)).'" not found.']); exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (index_no=? OR email=?) AND institution_id=? LIMIT 1");
        $stmt->execute([$identifier, $identifier, $institution['id']]);
        $user = $stmt->fetch();
    }

    if (!$user) { echo json_encode(['ok'=>false,'msg'=>'Invalid credentials.']); exit; }
    if (!empty($user['is_locked'])) { echo json_encode(['ok'=>false,'msg'=>'Account locked. Contact your administrator.']); exit; }
    if (isset($user['is_active']) && !$user['is_active']) { echo json_encode(['ok'=>false,'msg'=>'Account deactivated.']); exit; }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = ($user['login_attempts'] ?? 0) + 1;
        if ($attempts >= 3) {
            $pdo->prepare("UPDATE users SET login_attempts=?, is_locked=1 WHERE id=?")->execute([$attempts,$user['id']]);
            echo json_encode(['ok'=>false,'msg'=>'Account locked after too many failed attempts.']);
        } elseif ($attempts == 2) {
            $pdo->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$attempts,$user['id']]);
            echo json_encode(['ok'=>false,'msg'=>'Wrong password. 1 attempt left before lockout.']);
        } else {
            $pdo->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$attempts,$user['id']]);
            echo json_encode(['ok'=>false,'msg'=>'Invalid credentials.']);
        }
        exit;
    }

    if (!empty($user['device_fingerprint']) && $fingerprint
        && $user['device_fingerprint'] !== $fingerprint
        && !in_array($user['role'], ['admin','rep','super_admin'])) {
        echo json_encode(['ok'=>false,'msg'=>'Access denied. Account tied to another device.']); exit;
    }
    if ($fingerprint && empty($user['device_fingerprint'])) {
        $pdo->prepare("UPDATE users SET device_fingerprint=? WHERE id=?")->execute([$fingerprint,$user['id']]);
    }
    $pdo->prepare("UPDATE users SET login_attempts=0 WHERE id=?")->execute([$user['id']]);

    $_SESSION['user_id']        = $user['id'];
    $_SESSION['role']           = $user['role'];
    $_SESSION['institution_id'] = $user['institution_id'] ?? 1;
    // Store institution type for terminology
    if ($institution) $_SESSION['inst_type'] = $institution['inst_type'] ?? 'university';
    $_SESSION['user'] = [
        'id'=>$user['id'],'full_name'=>$user['full_name'],
        'index_no'=>$user['index_no'],'email'=>$user['email'],
        'role'=>$user['role'],'institution_id'=>$user['institution_id'] ?? 1,
        'program_id'=>$user['program_id'] ?? null,'level'=>$user['level'] ?? null,
    ];

    $map = ['super_admin'=>'super_admin','admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
    echo json_encode(['ok'=>true,'redirect'=>'pages/'.($map[$user['role']] ?? 'student').'/dashboard.php']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Attendance Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#c9a84c">
<script>if("serviceWorker"in navigator)navigator.serviceWorker.register("/sw.js");</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;
  --gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;
  --text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;
  background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.18) 0%,transparent 70%),
             radial-gradient(ellipse 40% 40% at 90% 80%,rgba(201,168,76,.08) 0%,transparent 60%);pointer-events:none}
.grid{position:fixed;inset:0;z-index:0;
  background-image:linear-gradient(rgba(74,111,165,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(74,111,165,.04) 1px,transparent 1px);
  background-size:60px 60px;mask-image:radial-gradient(ellipse 100% 100% at 50% 0%,black 20%,transparent 100%)}
.page{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column}
nav{display:flex;align-items:center;justify-content:space-between;padding:1.4rem 4rem;border-bottom:1px solid rgba(26,37,53,.6)}
.nav-brand{display:flex;align-items:center;gap:.8rem}
.nav-brand svg{width:36px;height:36px}
.nav-brand-text .name{font-family:'Cinzel',serif;font-size:1.1rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
.nav-brand-text .sub{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted)}
.nav-links{display:flex;align-items:center;gap:1rem}
.btn-outline{padding:.45rem 1.1rem;border:1px solid var(--border);border-radius:2px;color:var(--muted);font-family:'DM Sans',sans-serif;font-size:.82rem;text-decoration:none;cursor:pointer;background:transparent;transition:all .2s}
.btn-outline:hover{border-color:var(--gold);color:var(--gold)}
.btn-primary-sm{padding:.45rem 1.2rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;color:#060910;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;letter-spacing:.12em;border:none;cursor:pointer;transition:opacity .2s}
.btn-primary-sm:hover{opacity:.88}
.hero{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:5rem 2rem 4rem}
.hero-badge{display:inline-flex;align-items:center;gap:.5rem;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.35rem 1rem;font-size:.68rem;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin-bottom:2rem}
.hero-badge-dot{width:6px;height:6px;border-radius:50%;background:var(--gold);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.hero h1{font-family:'Cinzel',serif;font-size:clamp(2.5rem,6vw,4.5rem);font-weight:700;line-height:1.1;letter-spacing:.05em;margin-bottom:1.5rem}
.hero h1 span{color:var(--gold)}
.hero p{font-size:clamp(.9rem,2vw,1.1rem);color:var(--muted);max-width:560px;line-height:1.7;margin-bottom:2.5rem}
.hero-cta{display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;margin-bottom:4rem}
.cta-primary{padding:.85rem 2.2rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;color:#060910;font-family:'Cinzel',serif;font-size:.88rem;font-weight:700;letter-spacing:.15em;border:none;cursor:pointer;transition:opacity .2s,transform .15s}
.cta-primary:hover{opacity:.88;transform:translateY(-2px)}
.cta-secondary{padding:.85rem 2.2rem;border:1px solid var(--border);border-radius:2px;color:var(--muted);font-size:.88rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent}
.cta-secondary:hover{border-color:var(--steel);color:var(--text)}
.hero-stats{display:flex;gap:3rem;flex-wrap:wrap;justify-content:center}
.stat{text-align:center}
.stat-num{font-family:'Cinzel',serif;font-size:2rem;font-weight:700;color:var(--gold)}
.stat-label{font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.features{padding:5rem 4rem;border-top:1px solid var(--border)}
.features-title{text-align:center;font-family:'Cinzel',serif;font-size:1.8rem;margin-bottom:.8rem}
.features-title span{color:var(--gold)}
.features-sub{text-align:center;color:var(--muted);font-size:.9rem;margin-bottom:3rem}
.features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem;max-width:1100px;margin:0 auto}
.feature-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.8rem;position:relative;overflow:hidden;transition:border-color .2s}
.feature-card:hover{border-color:var(--gold-dim)}
.feature-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent);opacity:0;transition:opacity .2s}
.feature-card:hover::before{opacity:1}
.feature-icon{width:40px;height:40px;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.15);border-radius:2px;display:flex;align-items:center;justify-content:center;margin-bottom:1.2rem}
.feature-icon svg{width:20px;height:20px;stroke:var(--gold)}
.feature-title{font-family:'Cinzel',serif;font-size:.88rem;letter-spacing:.08em;margin-bottom:.6rem}
.feature-desc{font-size:.82rem;color:var(--muted);line-height:1.6}
.roles{padding:4rem;border-top:1px solid var(--border);background:var(--surface)}
.roles-title{text-align:center;font-family:'Cinzel',serif;font-size:1.5rem;margin-bottom:2.5rem}
.roles-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;max-width:900px;margin:0 auto}
.role-card{border:1px solid var(--border);border-radius:2px;padding:1.4rem;text-align:center;cursor:pointer;transition:all .2s;background:transparent;width:100%}
.role-card:hover{transform:translateY(-3px)}
.role-card.admin{border-color:rgba(201,168,76,.3);background:rgba(201,168,76,.04)}
.role-card.lecturer{border-color:rgba(138,111,212,.3);background:rgba(138,111,212,.04)}
.role-card.rep{border-color:rgba(90,159,122,.3);background:rgba(90,159,122,.04)}
.role-card.student{border-color:rgba(74,111,165,.3);background:rgba(74,111,165,.04)}
.role-icon{font-size:2rem;margin-bottom:.8rem}
.role-name{font-family:'Cinzel',serif;font-size:.88rem;letter-spacing:.1em;margin-bottom:.4rem}
.role-card.admin .role-name{color:var(--gold)}
.role-card.lecturer .role-name{color:#8a6fd4}
.role-card.rep .role-name{color:#5a9f7a}
.role-card.student .role-name{color:var(--steel)}
.role-desc{font-size:.75rem;color:var(--muted)}
footer{padding:2rem 4rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.footer-brand{font-family:'Cinzel',serif;font-size:.85rem;color:var(--gold);letter-spacing:.15em}
.footer-links{display:flex;gap:1.5rem}
.footer-links a{font-size:.78rem;color:var(--muted);text-decoration:none;transition:color .2s}
.footer-links a:hover{color:var(--text)}
.footer-copy{font-size:.72rem;color:var(--muted)}
/* MODAL */
.modal-overlay{position:fixed;inset:0;z-index:1000;background:rgba(4,6,14,.85);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.6rem 2.4rem;position:relative;transform:translateY(28px) scale(.97);transition:transform .28s cubic-bezier(.34,1.2,.64,1)}
.modal-overlay.open .modal{transform:translateY(0) scale(1)}
.modal::before,.modal::after{content:'';position:absolute;width:18px;height:18px;border-color:var(--gold);border-style:solid}
.modal::before{top:-1px;left:-1px;border-width:2px 0 0 2px}
.modal::after{bottom:-1px;right:-1px;border-width:0 2px 2px 0}
.modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer;transition:color .2s}
.modal-close:hover{color:var(--text)}
.modal-brand{text-align:center;margin-bottom:1.8rem}
.modal-brand-name{font-family:'Cinzel',serif;font-size:1.5rem;font-weight:700;letter-spacing:.18em;color:var(--gold)}
.modal-brand-sub{font-size:.68rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
.m-step{display:none}
.m-step.active{display:block;animation:fadeIn .25s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.divider{display:flex;align-items:center;gap:.8rem;margin-bottom:1.5rem}
.divider span{height:1px;flex:1;background:var(--border)}
.divider em{font-style:normal;font-size:.63rem;letter-spacing:.2em;color:var(--muted);text-transform:uppercase}
.school-badge{display:none;align-items:center;gap:.6rem;background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.55rem .9rem;margin-bottom:1.2rem;font-size:.82rem;color:var(--gold)}
.school-badge.show{display:flex}
.school-badge button{background:none;border:none;color:var(--muted);cursor:pointer;margin-left:auto;font-size:.72rem}
.m-field{margin-bottom:1rem}
.m-field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.45rem}
.m-field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.72rem 1rem;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.92rem;outline:none;transition:border-color .2s}
.m-field input:focus{border-color:var(--steel)}
.m-field input.code-i{text-transform:uppercase;font-family:'Cinzel',serif;letter-spacing:.2em;font-size:.88rem}
.m-hint{font-size:.68rem;color:var(--muted);margin-top:.3rem}
.m-btn{width:100%;padding:.82rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.82rem;font-weight:700;letter-spacing:.2em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.2rem}
.m-btn:hover:not(:disabled){opacity:.88;transform:translateY(-1px)}
.m-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.m-alert{padding:.6rem .9rem;border-radius:2px;font-size:.8rem;margin-bottom:1rem}
.m-alert.err{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
.m-alert.ok{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
.m-footer{text-align:center;margin-top:1.2rem;font-size:.78rem;color:var(--muted)}
.m-footer a{color:var(--gold);text-decoration:none}
.forgot{display:block;text-align:center;margin-top:.8rem;font-size:.78rem;color:var(--muted);text-decoration:none}
.forgot:hover{color:var(--text)}
@media(max-width:768px){nav{padding:1rem 1.5rem}.features,.roles{padding:3rem 1.5rem}footer{padding:1.5rem;flex-direction:column;text-align:center}.modal{padding:2rem 1.4rem}}

/* Safari zoom fix — inputs must be 16px */
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:inherit!important}}
</style>
</head>
<body>
<div class="grid"></div>
<div class="page">
  <nav>
    <div class="nav-brand">
      <svg viewBox="0 0 52 52" fill="none">
        <polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/>
        <polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/>
        <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
        <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
        <line x1="26" y1="27" x2="26" y2="31" stroke="#c9a84c" stroke-width="1.5"/>
      </svg>
      <div class="nav-brand-text">
        <div class="name">CITADEL</div>
        <div class="sub">Attendance System</div>
      </div>
    </div>
    <div class="nav-links">
      <a href="onboard.php" class="btn-outline">Register School</a>
      <button class="btn-primary-sm" onclick="openModal()">Sign In</button>
    </div>
  </nav>

  <section class="hero">
    <div class="hero-badge"><div class="hero-badge-dot"></div>Smart Attendance Management</div>
    <h1>Attendance Tracking<br>for <span>Modern Institutions</span></h1>
    <p>Citadel makes attendance effortless — real-time sessions, selfie verification, instant reports, and full semester management for any institution.</p>
    <div class="hero-cta">
      <button class="cta-primary" onclick="openModal()">Get Started</button>
      <a href="onboard.php" class="cta-secondary">Register Your School</a>
    </div>
    <div class="hero-stats">
      <div class="stat"><div class="stat-num">4</div><div class="stat-label">Role Types</div></div>
      <div class="stat"><div class="stat-num">TOTP</div><div class="stat-label">Secure Codes</div></div>
      <div class="stat"><div class="stat-num">Live</div><div class="stat-label">Real-Time</div></div>
      <div class="stat"><div class="stat-num">PDF</div><div class="stat-label">Certificates</div></div>
    </div>
  </section>

  <section class="features">
    <div class="features-title">Everything You <span>Need</span></div>
    <div class="features-sub">Built for institutions that take attendance seriously</div>
    <div class="features-grid">
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="feature-title">Real-Time Sessions</div><div class="feature-desc">Lecturers start sessions with rotating 6-digit codes. Students mark attendance live with selfie verification.</div></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div class="feature-title">Multi-Role Access</div><div class="feature-desc">Separate dashboards for Admin, Lecturers, Course Reps and Students — each with the right level of control.</div></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="feature-title">Semester Management</div><div class="feature-desc">Create semesters, assign courses and lecturers. Switch semesters without losing historical data.</div></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="feature-title">PDF Certificates</div><div class="feature-desc">Students download attendance certificates per semester. Admins export full records as CSV.</div></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div class="feature-title">Device Security</div><div class="feature-desc">Device fingerprinting prevents proxy attendance. Admins can ban, reset or unlock devices.</div></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><div class="feature-title">Live Analytics</div><div class="feature-desc">Track attendance rates per course, per student, per session. Instant warnings below 75%.</div></div>
    </div>
  </section>

  <section class="roles">
    <div class="roles-title">Sign In As</div>
    <div class="roles-grid">
      <button class="role-card admin" onclick="openModal()"><div class="role-icon">👑</div><div class="role-name">ADMIN</div><div class="role-desc">Full control — manage semesters, courses, users and settings</div></button>
      <button class="role-card lecturer" onclick="openModal()"><div class="role-icon">🎓</div><div class="role-name">LECTURER</div><div class="role-desc">Start sessions, view attendance and track course performance</div></button>
      <button class="role-card rep" onclick="openModal()"><div class="role-icon">📋</div><div class="role-name">COURSE REP</div><div class="role-desc">Approve selfies, manage class records and post announcements</div></button>
      <button class="role-card student" onclick="openModal()"><div class="role-icon">📚</div><div class="role-name">STUDENT</div><div class="role-desc">Mark attendance, track your rate and download certificates</div></button>
    </div>
  </section>

  <footer>
    <div class="footer-brand">CITADEL</div>
    <div class="footer-links">
      <a href="onboard.php">Register School</a>
      <a href="register.php">Student Register</a>
    </div>
    <div class="footer-copy">© <?php echo date('Y'); ?> Citadel Attendance System</div>
  </footer>
</div>

<!-- LOGIN MODAL -->
<div class="modal-overlay" id="loginModal" onclick="overlayClose(event)">
  <div class="modal" role="dialog" aria-modal="true">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <div class="modal-brand">
      <div class="modal-brand-name">CITADEL</div>
      <div class="modal-brand-sub">Secure Sign In</div>
    </div>
    <div id="m-alert" class="m-alert" style="display:none"></div>
    <!-- Step 1 -->
    <div class="m-step active" id="mStep1">
      <div class="divider"><span></span><em>Enter School Code</em><span></span></div>
      <div class="m-field">
        <label>School Code</label>
        <input type="text" id="mSchoolCode" class="code-i" placeholder="e.g. KTU" maxlength="10" autocomplete="off">
        <div class="m-hint">Your institution's unique code. Platform admins use <strong style="color:var(--gold)">CITADEL</strong>.</div>
      </div>
      <button class="m-btn" onclick="mStep1Next()">Continue →</button>
      <div class="m-footer">New institution? <a href="onboard.php">Register your school</a></div>
    </div>
    <!-- Step 2 -->
    <div class="m-step" id="mStep2">
      <div class="school-badge" id="mBadge">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        <span id="mBadgeText"></span>
        <button onclick="mGoBack()">Change</button>
      </div>
      <div class="divider"><span></span><em>Secure Sign In</em><span></span></div>
      <div class="m-field">
        <label>Index Number / Email</label>
        <input type="text" id="mIdentifier" placeholder="e.g. 52430540001" autocomplete="off">
      </div>
      <div class="m-field">
        <label>Password</label>
        <input type="password" id="mPassword" placeholder="••••••••">
      </div>
      <button class="m-btn" id="mSubmitBtn" onclick="mSubmit()">Enter Citadel</button>
      <a href="reset_password.php" class="forgot">Forgot password?</a>
      <div class="m-footer">New student? <a href="register.php">Register here</a></div>
    </div>
  </div>
</div>

<script>
let _fp = '';
(async () => {
  try {
    const raw = [navigator.userAgent,navigator.language,screen.width,screen.height,new Date().getTimezoneOffset()].join('|');
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
    _fp = Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
  } catch(e){}
})();
function openModal(){document.getElementById('loginModal').classList.add('open');document.body.style.overflow='hidden';setTimeout(()=>document.getElementById('mSchoolCode').focus(),280)}
function closeModal(){document.getElementById('loginModal').classList.remove('open');document.body.style.overflow=''}
function overlayClose(e){if(e.target===document.getElementById('loginModal'))closeModal()}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
function showAlert(msg,type='err'){const el=document.getElementById('m-alert');el.className='m-alert '+type;el.textContent=msg;el.style.display='block'}
function hideAlert(){document.getElementById('m-alert').style.display='none'}
function mStep1Next(){
  const code=document.getElementById('mSchoolCode').value.trim();
  if(!code){showAlert('Please enter your school code.');return}
  hideAlert();
  document.getElementById('mBadgeText').textContent=code.toUpperCase();
  document.getElementById('mBadge').classList.add('show');
  document.getElementById('mStep1').classList.remove('active');
  document.getElementById('mStep2').classList.add('active');
  setTimeout(()=>document.getElementById('mIdentifier').focus(),100);
}
function mGoBack(){
  hideAlert();
  document.getElementById('mStep2').classList.remove('active');
  document.getElementById('mStep1').classList.add('active');
  document.getElementById('mBadge').classList.remove('show');
  setTimeout(()=>document.getElementById('mSchoolCode').focus(),100);
}
document.getElementById('mSchoolCode').addEventListener('keydown',e=>{if(e.key==='Enter')mStep1Next()});
document.addEventListener('keydown',e=>{if(e.key==='Enter'&&document.getElementById('mStep2').classList.contains('active'))mSubmit()});
async function mSubmit(){
  const btn=document.getElementById('mSubmitBtn');
  const code=document.getElementById('mSchoolCode').value.trim();
  const id=document.getElementById('mIdentifier').value.trim();
  const pass=document.getElementById('mPassword').value;
  if(!id||!pass){showAlert('Please fill in all fields.');return}
  btn.disabled=true;btn.textContent='Signing in…';hideAlert();
  try{
    const res=await fetch('index.php',{method:'POST',body:new URLSearchParams({_action:'login',school_code:code,identifier:id,password:pass,device_fingerprint:_fp})});
    const data=await res.json();
    if(data.ok){btn.textContent='✓ Redirecting…';window.location.href=data.redirect;}
    else{showAlert(data.msg||'Login failed.');btn.disabled=false;btn.textContent='Enter Citadel';}
  }catch(e){showAlert('Network error. Try again.');btn.disabled=false;btn.textContent='Enter Citadel';}
}
</script>
<script>
if(window.location.hash==='#login'){
  document.addEventListener('DOMContentLoaded',function(){
    var m=document.getElementById('loginModal');
    if(m) m.classList.add('open');
  });
}
</script>
</body>
</html>
