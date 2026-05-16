<?php
require_once '../../includes/security.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole('lecturer');

$user   = currentUser();
$userId = $_SESSION['user_id'];

// Active semester
$activeSem = $pdo->query("SELECT * FROM semesters WHERE is_active=1 LIMIT 1")->fetch();
$semId     = $activeSem['id'] ?? null;

// Lecturer's assigned courses this semester
$myCourses = [];
if ($semId) {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(DISTINCT ce.student_id) AS enrolled_count
        FROM course_assignments ca
        JOIN courses c ON c.id = ca.course_id
        LEFT JOIN course_enrollments ce ON ce.course_id = c.id AND ce.status = 'active'
        WHERE ca.lecturer_id = ? AND ca.semester_id = ?
        GROUP BY c.id ORDER BY c.code ASC
    ");
    $stmt->execute([$userId, $semId]);
    $myCourses = $stmt->fetchAll();
}

// Today's timetable for this lecturer
$today = date('l');
$todayClasses = $pdo->prepare("
    SELECT t.*, c.name AS course_name, c.code AS course_code, c.id AS course_id
    FROM timetable t
    LEFT JOIN courses c ON c.id = t.course_id
    WHERE t.lecturer_id = ? AND t.day_of_week = ?
    ORDER BY t.start_time
");
$todayClasses->execute([$userId, $today]);
$todayClasses = $todayClasses->fetchAll();

// Fallback: if timetable has no course_id yet, use old columns
if (empty($todayClasses)) {
    $todayClasses = $pdo->prepare("SELECT * FROM timetable WHERE lecturer_id=? AND day_of_week=? ORDER BY start_time");
    $todayClasses->execute([$userId, $today]);
    $todayClasses = $todayClasses->fetchAll();
}

// Full timetable
$myTimetable = $pdo->prepare("SELECT * FROM timetable WHERE lecturer_id=? ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'), start_time");
$myTimetable->execute([$userId]);
$myTimetable = $myTimetable->fetchAll();

// Active session for this lecturer
$activeSession = $pdo->prepare("SELECT s.*, c.id AS course_id, c.name AS course_name_full FROM sessions s LEFT JOIN courses c ON c.id=s.course_id WHERE s.lecturer_id=? AND s.active_status=1 ORDER BY s.start_time DESC LIMIT 1");
$activeSession->execute([$userId]);
$activeSession = $activeSession->fetch();

// Past sessions
$pastSessions = $pdo->prepare("
    SELECT s.*,
           COUNT(DISTINCT CASE WHEN a.status IN ('present','late') THEN a.student_id END) as attended,
           COUNT(DISTINCT ce.student_id) as enrolled_count
    FROM sessions s
    LEFT JOIN attendance a ON s.id = a.session_id
    LEFT JOIN course_enrollments ce ON ce.course_id = s.course_id AND ce.status = 'active'
    WHERE s.lecturer_id = ?
    GROUP BY s.id ORDER BY s.start_time DESC LIMIT 20
");
$pastSessions->execute([$userId]);
$pastSessions = $pastSessions->fetchAll();

// Handle start session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_session') {
    verifyCsrf();
    $courseId   = (int)($_POST['course_id'] ?? 0);
    $courseCode = trim($_POST['course_code'] ?? '');
    $courseName = trim($_POST['course_name'] ?? '');
    $secretKey  = bin2hex(random_bytes(16));

    // If course_id given, fetch details
    if ($courseId) {
        $c = $pdo->prepare("SELECT * FROM courses WHERE id=?");
        $c->execute([$courseId]); $c = $c->fetch();
        if ($c) { $courseCode = $c['code']; $courseName = $c['name']; }
    }

    $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE lecturer_id=? AND active_status=1")->execute([$userId]);
    $ins = $pdo->prepare("INSERT INTO sessions (course_code, course_name, course_id, semester_id, lecturer_id, secret_key, start_time, active_status) VALUES (?,?,?,?,?,?,NOW(),1)");
    $ins->execute([$courseCode, $courseName, $courseId ?: null, $semId, $userId, $secretKey]);
    header('Location: dashboard.php'); exit;
}

// Handle end session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end_session') {
    verifyCsrf();
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid) {
        // Use the API logic inline
        $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE id=? AND lecturer_id=?")->execute([$sid, $userId]);
        $marked = $pdo->prepare("SELECT student_id FROM attendance WHERE session_id=? AND status IN ('present','late')");
        $marked->execute([$sid]); $markedIds = array_column($marked->fetchAll(), 'student_id');
        $pdo->prepare("DELETE FROM attendance WHERE session_id=? AND status='pending'")->execute([$sid]);
        // Mark absent only enrolled students
        $sess = $pdo->prepare("SELECT course_id FROM sessions WHERE id=?");
        $sess->execute([$sid]); $sess = $sess->fetch();
        if ($sess && $sess['course_id']) {
            $enrolled = $pdo->prepare("SELECT student_id FROM course_enrollments WHERE course_id=? AND status='active'");
            $enrolled->execute([$sess['course_id']]);
        } else {
            $enrolled = $pdo->query("SELECT id AS student_id FROM users WHERE role IN ('student','rep') AND is_active=1");
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,'absent',NOW())");
        foreach ($enrolled->fetchAll() as $s) {
            if (!in_array($s['student_id'], $markedIds)) $ins->execute([$sid, $s['student_id']]);
        }
    }
    header('Location: dashboard.php'); exit;
}

function generateCode(string $secret, int $window): string {
    $hash = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

$currentCode   = $activeSession ? generateCode($activeSession['secret_key'], (int)floor(time() / 120)) : '';
$timeRemaining = 120 - (time() % 120);

$liveAttendance = [];
if ($activeSession) {
    $la = $pdo->prepare("SELECT u.full_name, u.index_no, a.status, a.timestamp FROM attendance a JOIN users u ON a.student_id=u.id WHERE a.session_id=? AND a.status IN ('present','late') ORDER BY a.timestamp DESC");
    $la->execute([$activeSession['id']]); $liveAttendance = $la->fetchAll();
}

// Enrolled count for active session
$enrolledCount = 0;
if ($activeSession && $activeSession['course_id']) {
    $ec = $pdo->prepare("SELECT COUNT(*) FROM course_enrollments WHERE course_id=? AND status='active'");
    $ec->execute([$activeSession['course_id']]); $enrolledCount = $ec->fetchColumn();
} else {
    $enrolledCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('student','rep') AND is_active=1")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Citadel — Lecturer</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--lec:#8a6fd4;--lec-dim:#3d2a6a;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050;--sidebar-w:240px}
body.light{--bg:#f0f2f5;--surface:#ffffff;--surface2:#f5f7fa;--border:#dde1e9;--text:#1a2035;--muted:#5a6a7d;--gold:#8a6520;--gold-dim:#c9a84c;--steel:#2a4f8a}
body.light::before{display:none}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 60% 40% at 50% 0%,rgba(138,111,212,.1) 0%,transparent 60%);pointer-events:none}
.layout{display:flex;min-height:100vh;position:relative;z-index:1}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s}
.sidebar-brand{padding:1.6rem 1.4rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.8rem}
.sidebar-brand svg{width:32px;height:32px;flex-shrink:0}
.brand-name{font-family:'Cinzel',serif;font-size:1rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
.brand-role{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--lec)}
.sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto;scrollbar-width:none}
.nav-section{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.4rem .4rem}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.4rem;color:var(--muted);text-decoration:none;font-size:.85rem;cursor:pointer;border-left:2px solid transparent;transition:all .2s}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-item.active{color:var(--lec);border-left-color:var(--lec);background:rgba(138,111,212,.06)}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-user{padding:1rem 1.4rem;border-top:1px solid var(--border)}
.u-name{font-size:.82rem;color:var(--text);font-weight:500}
.u-role{font-size:.68rem;color:var(--muted);margin-bottom:.5rem}
.sidebar-user a{color:var(--danger);text-decoration:none;font-size:.78rem}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--gold);letter-spacing:.1em}
.badge-lec{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:rgba(138,111,212,.12);border:1px solid var(--lec-dim);color:var(--lec);padding:.25rem .7rem;border-radius:2px}
.content{padding:2rem;flex:1}
.page-section{display:none}
.page-section.active{display:block;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.8rem;flex-wrap:wrap;gap:1rem}
.section-title{font-family:'Cinzel',serif;font-size:1.1rem;color:var(--text);letter-spacing:.08em}
.section-title span{color:var(--lec)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.3rem 1.5rem;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.lec::before{background:linear-gradient(90deg,transparent,var(--lec),transparent)}
.stat-card.gold::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.stat-card.green::before{background:linear-gradient(90deg,transparent,var(--success),transparent)}
.stat-label{font-size:.65rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.stat-value{font-size:1.9rem;font-weight:600;color:var(--text);line-height:1}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:.35rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.card-head{padding:1rem 1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-head-title{font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
.card-body{padding:1.2rem 1.4rem}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.data-table{width:100%;border-collapse:collapse;font-size:.83rem}
.data-table th{text-align:left;font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);padding:.6rem .8rem;border-bottom:1px solid var(--border)}
.data-table td{padding:.65rem .8rem;border-bottom:1px solid rgba(30,42,53,.5);color:var(--text);vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:rgba(255,255,255,.02)}
.pill{display:inline-block;font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;padding:.2rem .6rem;border-radius:2px}
.pill-green{background:rgba(76,175,130,.12);color:var(--success);border:1px solid rgba(76,175,130,.3)}
.pill-red{background:rgba(224,92,92,.12);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
.pill-gold{background:rgba(201,168,76,.12);color:var(--gold);border:1px solid rgba(201,168,76,.3)}
.pill-lec{background:rgba(138,111,212,.12);color:var(--lec);border:1px solid rgba(138,111,212,.3)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;font-family:'DM Sans',sans-serif;font-size:.78rem;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;text-decoration:none}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.btn-lec{background:linear-gradient(135deg,var(--lec-dim),var(--lec));color:#fff;font-weight:600}
.btn-danger{background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-sm{padding:.3rem .7rem;font-size:.72rem}
.tt-item{display:flex;align-items:center;gap:1rem;background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--lec);padding:.7rem 1rem;border-radius:2px;margin-bottom:.5rem}
.tt-time{font-size:.75rem;color:var(--gold);min-width:100px;font-weight:500}
.tt-day{font-family:'Cinzel',serif;font-size:.78rem;color:var(--lec);letter-spacing:.15em;margin:1.2rem 0 .5rem;text-transform:uppercase}
.code-display-zone{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2.5rem 2rem;text-align:center;position:relative;overflow:hidden}
.code-display-zone::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--lec),transparent)}
.live-badge{display:inline-flex;align-items:center;gap:.4rem;font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--success);background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.25);padding:.25rem .8rem;border-radius:2px;margin-bottom:1.5rem}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.code-number{font-family:'Cinzel',serif;font-size:3.5rem;font-weight:700;letter-spacing:.5em;color:var(--gold);line-height:1;margin-bottom:.5rem;padding-left:.5em}
.code-course{font-size:.75rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:1.5rem}
.code-timer{display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.8rem}
.ring-wrap{position:relative;width:56px;height:56px}
.ring-wrap svg{width:100%;height:100%;transform:rotate(-90deg)}
.ring-wrap circle{fill:none;stroke-width:3}
.ring-track{stroke:var(--border)}
.ring-fill{stroke:var(--lec);stroke-dasharray:150.8;stroke-linecap:round;transition:stroke-dashoffset 1s linear,stroke .3s}
.ring-num{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.95rem;font-weight:700;color:var(--lec)}
.timer-text{font-size:.72rem;color:var(--muted)}
.attend-counter{display:flex;align-items:center;justify-content:center;gap:2rem;background:var(--surface2);border:1px solid var(--border);border-radius:2px;padding:1rem 1.5rem;margin-bottom:1.5rem}
.counter-item{text-align:center}
.counter-value{font-size:1.6rem;font-weight:600;color:var(--text);line-height:1}
.counter-label{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.start-form{background:var(--surface2);border:1px solid var(--border);border-radius:2px;padding:1.6rem;max-width:520px}
.form-field{margin-bottom:1rem}
.form-field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.form-field input,.form-field select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.65rem .9rem;font-family:'DM Sans',sans-serif;font-size:.88rem;border-radius:2px;outline:none;transition:border-color .2s}
.form-field input:focus,.form-field select:focus{border-color:var(--lec)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.course-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-bottom:2rem}
.course-card{background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--lec);border-radius:2px;padding:1.2rem}
.course-card-code{font-size:.7rem;color:var(--lec);letter-spacing:.12em;margin-bottom:.3rem}
.course-card-name{font-size:.88rem;color:var(--text);margin-bottom:.6rem}
.course-card-meta{font-size:.72rem;color:var(--muted)}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.content{padding:1rem;overflow-x:hidden}.topbar{padding:.8rem 1rem}.code-number{font-size:2rem}.two-col{grid-template-columns:1fr}.data-table{font-size:.72rem}.data-table th,.data-table td{padding:.4rem .5rem}.tt-item{flex-direction:column;gap:.3rem}.stat-value{font-size:1.5rem}.topbar-title{font-size:.78rem}#menu-btn{display:block}.form-row{grid-template-columns:1fr}}
@media(min-width:769px){#menu-btn{display:none}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <svg viewBox="0 0 52 52" fill="none"><polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/><polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/><rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#8a6fd4" stroke-width="1.5"/><circle cx="26" cy="25" r="2" fill="#c9a84c"/><line x1="26" y1="27" x2="26" y2="31" stroke="#8a6fd4" stroke-width="1.5"/></svg>
    <div><div class="brand-name">CITADEL</div><div class="brand-role">Lecturer</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Sessions</div>
    <a class="nav-item active" onclick="showSection('session',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <?= $activeSession ? '🟢 Live Session' : 'Start Session' ?>
    </a>
    <a class="nav-item" onclick="showSection('history',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Past Sessions
    </a>
    <div class="nav-section">Schedule</div>
    <a class="nav-item" onclick="showSection('courses',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      My Courses
    </a>
    <a class="nav-item" onclick="showSection('timetable',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      My Timetable
    </a>
  </nav>
  <div class="sidebar-user">
    <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? 'Lecturer') ?></div>
    <div class="u-role">Lecturer <?= $activeSem ? '· '.htmlspecialchars($activeSem['name']) : '' ?></div>
    <a href="../../change_password.php" style="color:var(--muted);font-size:.78rem;display:block;margin-bottom:.4rem">Change Password</a>
    <a href="../../logout.php">Sign out</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:1rem">
      <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" style="background:none;border:none;color:var(--muted);cursor:pointer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title" id="page-title">LIVE SESSION</div>
    </div>
    <div style="display:flex;align-items:center;gap:1rem">
      <span style="font-size:.75rem;color:var(--muted)"><?= date('l, d M Y') ?></span>
      <span class="badge-lec">Lecturer</span>
      <button id="theme-btn" onclick="toggleTheme()" style="background:none;border:1px solid var(--border);color:var(--muted);cursor:pointer;padding:.25rem .6rem;border-radius:2px;font-size:.75rem">🌙</button>
    </div>
  </div>

  <div class="content">

    <!-- ══ LIVE SESSION ══ -->
    <div class="page-section active" id="sec-session">
      <?php if ($activeSession): ?>
        <div class="section-header">
          <div class="section-title">Live: <span><?= htmlspecialchars($activeSession['course_code']) ?></span></div>
          <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="end_session"><input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>"><button type="submit" class="btn btn-danger" onclick="return confirm('End this session?')">End Session</button></form>
        </div>
        <div class="two-col">
          <div>
            <div class="code-display-zone">
              <div class="live-badge"><div class="live-dot"></div>Session Active</div>
              <div class="code-course"><?= htmlspecialchars($activeSession['course_code']) ?> · <?= htmlspecialchars($activeSession['course_name'] ?? '') ?></div>
              <div class="code-number" id="live-code"><?= substr($currentCode,0,3).' '.substr($currentCode,3) ?></div>
              <div class="code-timer">
                <div class="ring-wrap">
                  <svg viewBox="0 0 54 54"><circle class="ring-track" cx="27" cy="27" r="24"/><circle class="ring-fill" id="ring-fill" cx="27" cy="27" r="24" stroke-dashoffset="0"/></svg>
                  <div class="ring-num" id="ring-num"><?= $timeRemaining ?></div>
                </div>
                <div class="timer-text">seconds until<br>code refreshes</div>
              </div>
              <p style="font-size:.72rem;color:var(--muted)">Show this code to the class. Refreshes every 2 minutes.</p>
            </div>
            <div class="attend-counter" style="margin-top:1rem">
              <div class="counter-item"><div class="counter-value" id="live-count"><?= count($liveAttendance) ?></div><div class="counter-label">Marked</div></div>
              <div class="counter-item"><div class="counter-value"><?= $enrolledCount ?></div><div class="counter-label">Enrolled</div></div>
              <div class="counter-item"><div class="counter-value" style="color:var(--danger)"><?= max(0, $enrolledCount - count($liveAttendance)) ?></div><div class="counter-label">Absent</div></div>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><div class="card-head-title">Live Attendance</div><span class="pill pill-green" id="live-pill"><?= count($liveAttendance) ?> present</span></div>
            <div class="card-body" style="padding:0;max-height:420px;overflow-y:auto">
              <table class="data-table"><thead><tr><th>Student</th><th>Index</th><th>Status</th><th>Time</th></tr></thead>
              <tbody id="live-tbody">
                <?php if(empty($liveAttendance)): ?><tr id="empty-row"><td colspan="4" style="color:var(--muted)">Waiting for students...</td></tr>
                <?php else: foreach($liveAttendance as $a): ?>
                  <tr><td><?= htmlspecialchars($a['full_name']) ?></td><td style="color:var(--gold);font-size:.78rem"><?= $a['index_no'] ?></td><td><span class="pill pill-<?= $a['status']==='present'?'green':'gold' ?>"><?= $a['status'] ?></span></td><td style="color:var(--muted);font-size:.72rem"><?= date('H:i',strtotime($a['timestamp'])) ?></td></tr>
                <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="section-header"><div class="section-title">Start <span>Session</span></div></div>
        <?php if (!empty($todayClasses)): ?>
          <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">Quick start from today's schedule:</p>
          <div style="display:flex;flex-wrap:wrap;gap:.7rem;margin-bottom:2rem">
            <?php foreach ($todayClasses as $tc): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="start_session">
                <input type="hidden" name="course_id" value="<?= $tc['course_id'] ?? '' ?>">
                <input type="hidden" name="course_code" value="<?= htmlspecialchars($tc['course_code']) ?>">
                <input type="hidden" name="course_name" value="<?= htmlspecialchars($tc['course_name']) ?>">
                <button type="submit" class="btn btn-lec">▶ <?= htmlspecialchars($tc['course_code']) ?> · <?= substr($tc['start_time'],0,5) ?>–<?= substr($tc['end_time'],0,5) ?></button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="start-form">
          <p style="font-size:.78rem;color:var(--muted);margin-bottom:1.2rem">Or start a manual session:</p>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="start_session">
            <div class="form-row">
              <div class="form-field">
                <label>Course</label>
                <select name="course_id" id="course-sel" onchange="fillCourseName()">
                  <?php foreach ($myCourses as $mc): ?>
                    <option value="<?= $mc['id'] ?>" data-code="<?= htmlspecialchars($mc['code']) ?>" data-name="<?= htmlspecialchars($mc['name']) ?>">
                      <?= htmlspecialchars($mc['code']) ?> — <?= htmlspecialchars($mc['name']) ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if (empty($myCourses)): ?><option value="">No assigned courses</option><?php endif; ?>
                </select>
              </div>
              <div class="form-field">
                <label>Course Name (auto)</label>
                <input type="text" name="course_name" id="course-name" value="<?= htmlspecialchars($myCourses[0]['name'] ?? '') ?>" readonly>
              </div>
            </div>
            <input type="hidden" name="course_code" id="course-code-hidden" value="<?= htmlspecialchars($myCourses[0]['code'] ?? '') ?>">
            <button type="submit" class="btn btn-lec" style="width:100%;justify-content:center;padding:.8rem" <?= empty($myCourses) ? 'disabled' : '' ?>>Start Attendance Session</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══ MY COURSES ══ -->
    <div class="page-section" id="sec-courses">
      <div class="section-header"><div class="section-title">My <span>Courses</span><?php if($activeSem): ?><span style="font-size:.65rem;color:var(--muted);font-family:'DM Sans',sans-serif;letter-spacing:.1em;margin-left:.8rem"><?= htmlspecialchars($activeSem['name']) ?></span><?php endif; ?></div></div>
      <?php if (empty($myCourses)): ?>
        <div class="card"><div class="card-body" style="color:var(--muted)">No courses assigned to you this semester. Contact admin.</div></div>
      <?php else: ?>
        <div class="course-cards">
          <?php foreach ($myCourses as $c): ?>
            <div class="course-card">
              <div class="course-card-code"><?= htmlspecialchars($c['code']) ?></div>
              <div class="course-card-name"><?= htmlspecialchars($c['name']) ?></div>
              <div class="course-card-meta"><?= $c['enrolled_count'] ?> students enrolled · <?= $c['credit_hrs'] ?> credit hrs</div>
              <form method="POST" style="margin-top:.8rem">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="start_session">
                <input type="hidden" name="course_id" value="<?= $c['id'] ?>">
                <input type="hidden" name="course_code" value="<?= htmlspecialchars($c['code']) ?>">
                <input type="hidden" name="course_name" value="<?= htmlspecialchars($c['name']) ?>">
                <button type="submit" class="btn btn-lec btn-sm">▶ Start Session</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══ PAST SESSIONS ══ -->
    <div class="page-section" id="sec-history">
      <div class="section-header"><div class="section-title">Past <span>Sessions</span></div></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Course</th><th>Date</th><th>Duration</th><th>Attended</th><th>Rate</th><th>Status</th></tr></thead>
        <tbody>
          <?php if(empty($pastSessions)): ?><tr><td colspan="6" style="color:var(--muted)">No sessions yet.</td></tr>
          <?php else: foreach($pastSessions as $ps):
            $dur='—';
            if($ps['end_time']){$mins=(strtotime($ps['end_time'])-strtotime($ps['start_time']))/60;$dur=round($mins).' min';}
            $total = $ps['enrolled_count'] ?: 1;
            $rate = round(($ps['attended']/$total)*100);
          ?>
            <tr>
              <td style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($ps['course_code']) ?></td>
              <td style="color:var(--muted);font-size:.78rem"><?= date('d M Y H:i',strtotime($ps['start_time'])) ?></td>
              <td><?= $dur ?></td>
              <td><?= $ps['attended'] ?> / <?= $ps['enrolled_count'] ?></td>
              <td><span style="color:<?= $rate>=75?'var(--success)':($rate>=50?'var(--warning)':'var(--danger)') ?>;font-weight:600"><?= $rate ?>%</span></td>
              <td><span class="pill pill-<?= $ps['active_status']?'green':'lec' ?>"><?= $ps['active_status']?'Active':'Closed' ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ TIMETABLE ══ -->
    <div class="page-section" id="sec-timetable">
      <div class="section-header"><div class="section-title">My <span>Timetable</span></div></div>
      <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day):
        $dayCls=array_filter($myTimetable,fn($c)=>$c['day_of_week']===$day);
        if(empty($dayCls)) continue; ?>
        <div class="tt-day"><?= $day ?></div>
        <?php foreach($dayCls as $c): ?>
          <div class="tt-item">
            <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
            <div style="flex:1">
              <div style="font-size:.7rem;color:var(--muted);letter-spacing:.1em"><?= htmlspecialchars($c['course_code']) ?></div>
              <div style="font-size:.85rem;color:var(--text)"><?= htmlspecialchars($c['course_name']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)">📍 <?= htmlspecialchars($c['room'] ?? '') ?></div>
            </div>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="start_session">
              <input type="hidden" name="course_id" value="<?= $c['course_id'] ?? '' ?>">
              <input type="hidden" name="course_code" value="<?= htmlspecialchars($c['course_code']) ?>">
              <input type="hidden" name="course_name" value="<?= htmlspecialchars($c['course_name']) ?>">
              <button type="submit" class="btn btn-lec btn-sm">Start</button>
            </form>
          </div>
        <?php endforeach;
      endforeach; ?>
    </div>

  </div>
</div>
</div>

<script>
function showSection(name,el){document.querySelectorAll('.page-section').forEach(s=>s.classList.remove('active'));document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));document.getElementById('sec-'+name).classList.add('active');document.getElementById('page-title').textContent=name.replace('-',' ').toUpperCase();if(el)el.classList.add('active');document.getElementById('sidebar').classList.remove('open')}

function fillCourseName(){
  const sel=document.getElementById('course-sel');
  const opt=sel?.options[sel.selectedIndex];
  if(opt){
    document.getElementById('course-name').value=opt.dataset.name||'';
    document.getElementById('course-code-hidden').value=opt.dataset.code||'';
  }
}

<?php if($activeSession): ?>
let timeLeft=<?= $timeRemaining ?>;
const circumference=150.8;
const ringFill=document.getElementById('ring-fill');
const ringNum=document.getElementById('ring-num');
function updateRing(){if(!ringFill)return;const offset=circumference*(1-timeLeft/120);ringFill.style.strokeDashoffset=offset;ringNum.textContent=timeLeft;if(timeLeft<=10)ringFill.style.stroke='var(--danger)';else if(timeLeft<=20)ringFill.style.stroke='var(--warning)';else ringFill.style.stroke='var(--lec)'}
updateRing();
setInterval(()=>{timeLeft--;if(timeLeft<0)timeLeft=119;updateRing()},1000);
setInterval(()=>{fetch('../../api/get_code.php?session_id=<?= $activeSession['id'] ?>').then(r=>r.json()).then(d=>{if(d.code){const el=document.getElementById('live-code');if(el)el.textContent=d.code.slice(0,3)+' '+d.code.slice(3)}})},120000);
setInterval(()=>{fetch('../../api/live_attendance.php?session_id=<?= $activeSession['id'] ?>').then(r=>r.json()).then(data=>{if(!data.rows)return;const tbody=document.getElementById('live-tbody');const pill=document.getElementById('live-pill');const count=document.getElementById('live-count');if(count)count.textContent=data.total;if(pill)pill.textContent=data.total+' present';if(tbody&&data.rows.length>0){const empty=document.getElementById('empty-row');if(empty)empty.remove();tbody.innerHTML=data.rows.map(r=>`<tr><td>${r.full_name}</td><td style="color:var(--gold);font-size:.78rem">${r.index_no}</td><td><span class="pill pill-${r.status==='present'?'green':'gold'}">${r.status}</span></td><td style="color:var(--muted);font-size:.72rem">${r.time}</td></tr>`).join('')}})},10000);
<?php endif; ?>

function toggleTheme(){const body=document.body;const btn=document.getElementById('theme-btn');if(body.classList.contains('light')){body.classList.remove('light');localStorage.setItem('theme','dark');if(btn)btn.textContent='🌙';}else{body.classList.add('light');localStorage.setItem('theme','light');if(btn)btn.textContent='☀️';}}
(function(){if(localStorage.getItem('theme')==='light'){document.body.classList.add('light');const btn=document.getElementById('theme-btn');if(btn)btn.textContent='☀️';}})();

const csrfToken="<?= csrfToken() ?>";
const originalFetch=window.fetch;
window.fetch=function(url,options={}){options.headers=options.headers||{};options.headers['X-CSRF-Token']=csrfToken;return originalFetch(url,options)};
</script>
<?php require_once '../../includes/toast.php'; ?>
</body>
</html>
