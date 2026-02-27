<?php
// pages/rep/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole('rep', 'admin');

$user   = currentUser();
$userId = $user['id'];

$totalStudents   = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('student','rep')")->fetchColumn();
$todayAttendance = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(timestamp)=CURDATE()")->fetchColumn();
$activeSessions  = $pdo->query("SELECT COUNT(*) FROM sessions WHERE active_status=1")->fetchColumn();

$today     = date('l');
$todayStmt = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
$todayStmt->execute([$today]); $todayClasses = $todayStmt->fetchAll();

$allClasses    = $pdo->query("SELECT DISTINCT course_code, course_name FROM timetable ORDER BY course_code")->fetchAll();
$activeSession = $pdo->query("SELECT * FROM sessions WHERE active_status=1 ORDER BY start_time DESC LIMIT 1")->fetch();

$liveAttendance = [];
if ($activeSession) {
    $la = $pdo->prepare("SELECT u.full_name, u.index_no, a.status, a.timestamp FROM attendance a JOIN users u ON a.student_id=u.id WHERE a.session_id=? AND a.status IN ('present','late') ORDER BY a.timestamp DESC");
    $la->execute([$activeSession['id']]); $liveAttendance = $la->fetchAll();
}

// Pending approvals count
$pendingCount = 0;
if ($activeSession) {
    $pc = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE session_id=? AND status='pending'");
    $pc->execute([$activeSession['id']]); $pendingCount = $pc->fetchColumn();
}

function generateCode(string $secret, int $window): string {
    $hash   = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

$currentCode   = '';
$timeRemaining = 120 - (time() % 120);
if ($activeSession) {
    $currentCode = generateCode($activeSession['secret_key'], (int)floor(time() / 120));
}

$students  = $pdo->query("SELECT * FROM users WHERE role IN ('student','rep') ORDER BY full_name")->fetchAll();
$recentAtt = $pdo->query("SELECT a.*, u.full_name, u.index_no, s.course_code FROM attendance a JOIN users u ON a.student_id=u.id JOIN sessions s ON a.session_id=s.id ORDER BY a.timestamp DESC LIMIT 15")->fetchAll();

$msg = ''; $msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'start_session') {
        $courseCode = trim($_POST['course_code'] ?? '');
        $courseName = trim($_POST['course_name'] ?? '');
        $secretKey  = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE active_status=1")->execute();
        $pdo->prepare("INSERT INTO sessions (course_code, course_name, lecturer_id, secret_key, start_time, active_status) VALUES (?,?,?,?,NOW(),1)")->execute([$courseCode, $courseName, $userId, $secretKey]);
        $newSessionId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,'present',NOW())")->execute([$newSessionId, $userId]);
        header('Location: dashboard.php'); exit;
    }
    if ($action === 'end_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE id=?")->execute([$sid]);
            $marked = $pdo->prepare("SELECT student_id FROM attendance WHERE session_id=? AND status IN ('present','late')");
            $marked->execute([$sid]);
            $markedIds = array_column($marked->fetchAll(), 'student_id');
            $pdo->prepare("DELETE FROM attendance WHERE session_id=? AND status='pending'")->execute([$sid]);
            $students = $pdo->query("SELECT id FROM users WHERE role IN ('student','rep')")->fetchAll();
            foreach ($students as $s) {
                if (!in_array($s['id'], $markedIds)) {
                    try { $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,'absent',NOW())")->execute([$sid, $s['id']]); } catch (Exception $e) {}
                }
            }
        }
        header('Location: dashboard.php'); exit;
    }
    if ($action === 'add') {
        $name = trim($_POST['full_name'] ?? ''); $index = trim($_POST['index_no'] ?? '');
        $email = trim($_POST['email'] ?? '') ?: ($index . '@citadel.edu');
        $hash  = password_hash($index, PASSWORD_DEFAULT);
        if ($name && $index) {
            try {
                $pdo->prepare("INSERT INTO users (full_name, index_no, email, password_hash, role) VALUES (?,?,?,?,'student')")->execute([$name, $index, $email, $hash]);
                $msg = "Student $name added."; $msgType = 'success';
                $students = $pdo->query("SELECT * FROM users WHERE role IN ('student','rep') ORDER BY full_name")->fetchAll();
                $totalStudents = count($students);
            } catch (Exception $e) { $msg = "Error: Index or email already exists."; $msgType = 'error'; }
        } else { $msg = "Name and Index Number are required."; $msgType = 'error'; }
    }
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['full_name'] ?? ''); $index = trim($_POST['index_no'] ?? ''); $email = trim($_POST['email'] ?? '');
        if ($id && $name && $index) {
            $pdo->prepare("UPDATE users SET full_name=?, index_no=?, email=? WHERE id=? AND role IN ('student','rep')")->execute([$name, $index, $email, $id]);
            $msg = "Student updated."; $msgType = 'success';
            $students = $pdo->query("SELECT * FROM users WHERE role IN ('student','rep') ORDER BY full_name")->fetchAll();
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM users WHERE id=? AND role='student'")->execute([$id]);
            $msg = "Student removed."; $msgType = 'success';
            $students = $pdo->query("SELECT * FROM users WHERE role IN ('student','rep') ORDER BY full_name")->fetchAll();
            $totalStudents = count($students);
        }
    }
    if ($action === 'announce') {
        $message = trim($_POST['message'] ?? '');
        if ($message) {
            $pdo->prepare("INSERT INTO announcements (rep_id, message) VALUES (?,?)")->execute([$userId, $message]);
            $msg = 'Announcement posted.'; $msgType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Rep Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--rep:#5a9f7a;--rep-dim:#2a5040;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050;--sidebar-w:240px}
    html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
    body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 60% 40% at 20% 0%,rgba(90,159,122,.1) 0%,transparent 60%);pointer-events:none}
    .layout{display:flex;min-height:100vh;position:relative;z-index:1}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s}
    .sidebar-brand{padding:1.6rem 1.4rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.8rem}
    .sidebar-brand svg{width:32px;height:32px;flex-shrink:0}
    .brand-name{font-family:'Cinzel',serif;font-size:1rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
    .brand-role{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--rep)}
    .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none}
    .nav-section{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.4rem .4rem}
    .nav-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.4rem;color:var(--muted);text-decoration:none;font-size:.85rem;cursor:pointer;border-left:2px solid transparent;transition:all .2s}
    .nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
    .nav-item.active{color:var(--rep);border-left-color:var(--rep);background:rgba(90,159,122,.06)}
    .nav-item svg{width:16px;height:16px;flex-shrink:0}
    .sidebar-user{padding:1rem 1.4rem;border-top:1px solid var(--border)}
    .u-name{font-size:.82rem;color:var(--text);font-weight:500}
    .u-index{font-size:.68rem;color:var(--muted);margin-bottom:.5rem}
    .sidebar-user a{color:var(--danger);text-decoration:none;font-size:.78rem}
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
    .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
    .topbar-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--gold);letter-spacing:.1em}
    .badge-rep{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:rgba(90,159,122,.12);border:1px solid var(--rep-dim);color:var(--rep);padding:.25rem .7rem;border-radius:2px}
    .content{padding:2rem;flex:1}
    .page-section{display:none}
    .page-section.active{display:block;animation:fadeIn .3s ease}
    @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.8rem;flex-wrap:wrap;gap:1rem}
    .section-title{font-family:'Cinzel',serif;font-size:1.1rem;color:var(--text);letter-spacing:.08em}
    .section-title span{color:var(--rep)}
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:2rem}
    .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.3rem 1.5rem;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
    .stat-card.green::before{background:linear-gradient(90deg,transparent,var(--rep),transparent)}
    .stat-card.gold::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}
    .stat-card.steel::before{background:linear-gradient(90deg,transparent,var(--steel),transparent)}
    .stat-card.warn::before{background:linear-gradient(90deg,transparent,var(--warning),transparent)}
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
    .pill-rep{background:rgba(90,159,122,.12);color:var(--rep);border:1px solid rgba(90,159,122,.3)}
    .pill-steel{background:rgba(74,111,165,.12);color:var(--steel);border:1px solid rgba(74,111,165,.3)}
    .pill-warn{background:rgba(224,160,80,.12);color:var(--warning);border:1px solid rgba(224,160,80,.3)}
    .tt-grid{display:flex;flex-direction:column;gap:.5rem}
    .tt-item{display:flex;align-items:center;gap:1rem;background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--rep);padding:.7rem 1rem;border-radius:2px}
    .tt-time{font-size:.75rem;color:var(--gold);min-width:100px;font-weight:500}
    .tt-course-code{font-size:.7rem;color:var(--muted);letter-spacing:.1em}
    .tt-course-name{font-size:.85rem;color:var(--text)}
    .tt-room{font-size:.72rem;color:var(--muted)}
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;font-family:'DM Sans',sans-serif;font-size:.78rem;letter-spacing:.06em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s}
    .btn:hover{opacity:.85;transform:translateY(-1px)}
    .btn-rep{background:linear-gradient(135deg,var(--rep-dim),var(--rep));color:#060910;font-weight:600}
    .btn-gold{background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-weight:600}
    .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
    .btn-danger{background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
    .btn-sm{padding:.3rem .7rem;font-size:.72rem}
    .filter-bar{display:flex;gap:.8rem;margin-bottom:1.2rem;flex-wrap:wrap}
    .filter-bar input,.filter-bar select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:.5rem .9rem;font-family:'DM Sans',sans-serif;font-size:.83rem;border-radius:2px;outline:none;flex:1;min-width:160px}
    .filter-bar input:focus{border-color:var(--rep)}
    .filter-bar input::placeholder{color:var(--muted)}
    .alert{padding:.7rem 1rem;border-radius:2px;font-size:.82rem;margin-bottom:1.2rem}
    .alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
    .alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
    .modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(6,9,16,.85);display:none;align-items:center;justify-content:center;padding:1rem}
    .modal-overlay.open{display:flex}
    .modal{background:var(--surface);border:1px solid var(--border);border-radius:2px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;animation:fadeIn .25s ease}
    .modal-head{padding:1.2rem 1.6rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .modal-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--rep);letter-spacing:.1em}
    .modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem}
    .modal-body{padding:1.6rem}
    .form-field{margin-bottom:1rem}
    .form-field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
    .form-field input,.form-field select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.65rem .9rem;font-family:'DM Sans',sans-serif;font-size:.88rem;border-radius:2px;outline:none;transition:border-color .2s}
    .form-field input:focus,.form-field select:focus{border-color:var(--rep)}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    .code-display-zone{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2rem;text-align:center;position:relative;overflow:hidden}
    .code-display-zone::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--rep),transparent)}
    .live-badge{display:inline-flex;align-items:center;gap:.4rem;font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--success);background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.25);padding:.25rem .8rem;border-radius:2px;margin-bottom:1.2rem}
    .live-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:pulse 1s infinite}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
    .code-number{font-family:'Cinzel',serif;font-size:3.5rem;font-weight:700;letter-spacing:.5em;color:var(--gold);text-shadow:0 0 40px rgba(201,168,76,.3);line-height:1;margin-bottom:.5rem;padding-left:.5em}
    .code-course{font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:1.2rem}
    .code-timer{display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.5rem}
    .ring-wrap{position:relative;width:52px;height:52px}
    .ring-wrap svg{width:100%;height:100%;transform:rotate(-90deg)}
    .ring-wrap circle{fill:none;stroke-width:3}
    .ring-track{stroke:var(--border)}
    .ring-fill{stroke:var(--rep);stroke-dasharray:150.8;stroke-linecap:round;transition:stroke-dashoffset 1s linear,stroke .3s}
    .ring-num{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.9rem;font-weight:700;color:var(--rep)}
    .timer-text{font-size:.72rem;color:var(--muted)}
    .attend-counter{display:flex;align-items:center;justify-content:center;gap:2rem;background:var(--surface2);border:1px solid var(--border);border-radius:2px;padding:1rem 1.5rem;margin-top:1rem}
    .counter-item{text-align:center}
    .counter-value{font-size:1.5rem;font-weight:600;color:var(--text);line-height:1}
    .counter-label{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
    .start-form{background:var(--surface2);border:1px solid var(--border);border-radius:2px;padding:1.6rem;max-width:500px}

    /* Approvals */
    .selfie-thumb{width:48px;height:48px;object-fit:cover;border-radius:50%;border:2px solid var(--border);cursor:pointer;transition:border-color .2s}
    .selfie-thumb:hover{border-color:var(--rep)}
    .pending-badge{background:var(--warning);color:#060910;font-size:.6rem;font-weight:700;padding:.15rem .45rem;border-radius:2px;margin-left:.4rem;display:none}

    @media(max-width:900px){.two-col{grid-template-columns:1fr}}
    @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.content{padding:1rem}.topbar{padding:.8rem 1rem}.stats-grid{grid-template-columns:repeat(2,1fr)}.code-number{font-size:2rem}.data-table{font-size:.75rem}.data-table th,.data-table td{padding:.5rem .5rem}.tt-item{flex-direction:column;gap:.3rem}.tt-time{min-width:unset}.section-title{font-size:.95rem}.two-col{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}.topbar-title{font-size:.78rem}.stat-value{font-size:1.5rem}#menu-btn{display:block}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg viewBox="0 0 52 52" fill="none"><polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/><polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/><rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#5a9f7a" stroke-width="1.5"/><circle cx="26" cy="25" r="2" fill="#c9a84c"/><line x1="26" y1="27" x2="26" y2="31" stroke="#5a9f7a" stroke-width="1.5"/></svg>
      <div><div class="brand-name">CITADEL</div><div class="brand-role">Course Rep</div></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section">Overview</div>
      <a class="nav-item active" onclick="showSection('overview',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Overview
      </a>
      <a class="nav-item" onclick="showSection('timetable',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Timetable
      </a>
      <div class="nav-section">Sessions</div>
      <a class="nav-item" id="session-nav" onclick="showSection('session',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?= $activeSession ? '🟢 Live Session' : 'Start Session' ?>
      </a>
      <a class="nav-item" id="approvals-nav" onclick="showSection('approvals',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Approvals<span class="pending-badge" id="pending-badge"><?= $pendingCount ?></span>
      </a>
      <div class="nav-section">Class Management</div>
      <a class="nav-item" onclick="showSection('students',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Students
      </a>
      <a class="nav-item" onclick="showSection('attendance',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Attendance
      </a>
      <a class="nav-item" onclick="showSection('announce',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Announcements
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? 'Ali Richard') ?></div>
      <div class="u-index">Rep · <?= htmlspecialchars($user['index_no'] ?? '52430540017') ?></div>
      <a href="../../change_password.php" style="color:var(--muted);font-size:.78rem;display:block;margin-bottom:.4rem">Change Password</a>
      <a href="../../logout.php">Sign out</a>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:1rem">
        <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" style="background:none;border:none;color:var(--muted);cursor:pointer;display:none">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title" id="page-title">OVERVIEW</div>
      </div>
      <div style="display:flex;align-items:center;gap:1rem">
        <span style="font-size:.75rem;color:var(--muted)"><?= date('l, d M Y') ?></span>
        <span class="badge-rep">Rep</span>
      </div>
    </div>

    <div class="content">
      <?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <!-- OVERVIEW -->
      <div class="page-section active" id="sec-overview">
        <div class="stats-grid">
          <div class="stat-card green"><div class="stat-label">Class Size</div><div class="stat-value"><?= $totalStudents ?></div><div class="stat-sub">HND CS Year 2</div></div>
          <div class="stat-card gold"><div class="stat-label">Today's Records</div><div class="stat-value"><?= $todayAttendance ?></div><div class="stat-sub"><?= date('d M Y') ?></div></div>
          <div class="stat-card steel"><div class="stat-label">Active Sessions</div><div class="stat-value"><?= $activeSessions ?></div><div class="stat-sub">Live right now</div></div>
          <?php if ($pendingCount > 0): ?>
          <div class="stat-card warn"><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-sub"><a href="#" onclick="showSection('approvals',document.getElementById('approvals-nav'));return false" style="color:var(--warning)">Review now →</a></div></div>
          <?php endif; ?>
        </div>
        <div class="two-col">
          <div class="card">
            <div class="card-head"><div class="card-head-title">Today — <?= $today ?></div><span class="pill pill-rep"><?= count($todayClasses) ?> classes</span></div>
            <div class="card-body">
              <?php if (empty($todayClasses)): ?><p style="color:var(--muted);font-size:.83rem">No classes today.</p>
              <?php else: ?><div class="tt-grid"><?php foreach ($todayClasses as $c): ?>
                <div class="tt-item"><div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div><div><div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div><div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div><div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div></div></div>
              <?php endforeach; ?></div><?php endif; ?>
              <?php if ($activeSession): ?>
                <button class="btn btn-rep" style="width:100%;justify-content:center;margin-top:1rem" onclick="showSection('session',document.getElementById('session-nav'))">View Live Code →</button>
              <?php else: ?>
                <button class="btn btn-gold" style="width:100%;justify-content:center;margin-top:1rem" onclick="showSection('session',document.getElementById('session-nav'))">Start Attendance Session →</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><div class="card-head-title">Recent Attendance</div></div>
            <div class="card-body" style="padding:0">
              <table class="data-table"><thead><tr><th>Student</th><th>Course</th><th>Status</th><th>Time</th></tr></thead>
              <tbody>
                <?php if (empty($recentAtt)): ?><tr><td colspan="4" style="color:var(--muted)">No records yet.</td></tr>
                <?php else: foreach ($recentAtt as $r): ?>
                  <tr><td><?= htmlspecialchars($r['full_name']) ?><br><small style="color:var(--muted)"><?= $r['index_no'] ?></small></td><td style="color:var(--gold);font-size:.78rem"><?= $r['course_code'] ?></td><td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'warn':'red')) ?>"><?= $r['status'] ?></span></td><td style="color:var(--muted);font-size:.72rem"><?= date('H:i',strtotime($r['timestamp'])) ?></td></tr>
                <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>

      <!-- TIMETABLE -->
      <div class="page-section" id="sec-timetable">
        <div class="section-header"><div class="section-title">Class <span>Timetable</span></div></div>
        <?php $days=['Monday','Tuesday','Wednesday','Thursday']; foreach ($days as $day):
          $cls=$pdo->prepare("SELECT t.*,u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
          $cls->execute([$day]); $cls=$cls->fetchAll(); if(empty($cls)) continue; ?>
          <div style="margin-bottom:1.5rem">
            <div style="font-family:'Cinzel',serif;font-size:.78rem;color:var(--rep);letter-spacing:.15em;margin-bottom:.6rem;text-transform:uppercase"><?= $day ?></div>
            <div class="tt-grid"><?php foreach($cls as $c): ?>
              <div class="tt-item"><div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div><div style="flex:1"><div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div><div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div><div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div></div></div>
            <?php endforeach; ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- SESSION -->
      <div class="page-section" id="sec-session">
        <?php if ($activeSession): ?>
          <div class="section-header">
            <div class="section-title">Live: <span><?= htmlspecialchars($activeSession['course_code']) ?></span></div>
            <form method="POST"><input type="hidden" name="action" value="end_session"><input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>"><button type="submit" class="btn btn-danger" onclick="return confirm('End this session?')">End Session</button></form>
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
              <div class="attend-counter">
                <div class="counter-item"><div class="counter-value" id="live-count"><?= count($liveAttendance) ?></div><div class="counter-label">Approved</div></div>
                <div class="counter-item"><div class="counter-value" style="color:var(--warning)" id="pending-count"><?= $pendingCount ?></div><div class="counter-label">Pending</div></div>
                <div class="counter-item"><div class="counter-value"><?= $totalStudents ?></div><div class="counter-label">Total</div></div>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><div class="card-head-title">Approved Students</div><span class="pill pill-green" id="live-pill"><?= count($liveAttendance) ?> present</span></div>
              <div class="card-body" style="padding:0;max-height:400px;overflow-y:auto">
                <table class="data-table"><thead><tr><th>Student</th><th>Index</th><th>Status</th><th>Time</th></tr></thead>
                <tbody id="live-tbody">
                  <?php if(empty($liveAttendance)): ?><tr id="empty-row"><td colspan="4" style="color:var(--muted)">No approved students yet...</td></tr>
                  <?php else: foreach($liveAttendance as $a): ?>
                    <tr><td><?= htmlspecialchars($a['full_name']) ?></td><td style="color:var(--gold);font-size:.78rem"><?= $a['index_no'] ?></td><td><span class="pill pill-green"><?= $a['status'] ?></span></td><td style="color:var(--muted);font-size:.72rem"><?= date('H:i:s',strtotime($a['timestamp'])) ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="section-header"><div class="section-title">Start <span>Session</span></div></div>
          <?php if(!empty($todayClasses)): ?>
            <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">Quick start from today's schedule:</p>
            <div style="display:flex;flex-wrap:wrap;gap:.7rem;margin-bottom:2rem">
              <?php foreach($todayClasses as $tc): ?>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="start_session"><input type="hidden" name="course_code" value="<?= htmlspecialchars($tc['course_code']) ?>"><input type="hidden" name="course_name" value="<?= htmlspecialchars($tc['course_name']) ?>"><button type="submit" class="btn btn-rep">▶ <?= htmlspecialchars($tc['course_code']) ?> · <?= substr($tc['start_time'],0,5) ?>–<?= substr($tc['end_time'],0,5) ?></button></form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="start-form">
            <p style="font-size:.78rem;color:var(--muted);margin-bottom:1.2rem">Or start manually:</p>
            <form method="POST"><input type="hidden" name="action" value="start_session">
              <div class="form-row">
                <div class="form-field"><label>Course Code</label><select name="course_code" id="course-sel" onchange="fillName()"><?php foreach($allClasses as $ac): ?><option value="<?= htmlspecialchars($ac['course_code']) ?>" data-name="<?= htmlspecialchars($ac['course_name']) ?>"><?= htmlspecialchars($ac['course_code']) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label>Course Name</label><input type="text" name="course_name" id="course-name" value="<?= htmlspecialchars($allClasses[0]['course_name'] ?? '') ?>"></div>
              </div>
              <button type="submit" class="btn btn-rep" style="width:100%;justify-content:center;padding:.8rem">Start Attendance Session</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <!-- APPROVALS -->
      <div class="page-section" id="sec-approvals">
        <div class="section-header">
          <div class="section-title">Pending <span>Approvals</span></div>
          <span class="pill pill-warn" id="approvals-count-badge"><?= $pendingCount ?> pending</span>
        </div>
        <?php if (!$activeSession): ?>
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2rem;text-align:center;color:var(--muted)">No active session. Start a session to see pending approvals.</div>
        <?php else: ?>
          <div class="card">
            <div class="card-body" style="padding:0;overflow-x:auto">
              <table class="data-table">
                <thead><tr><th>Student</th><th>Index</th><th>Selfie</th><th>Submitted</th><th>Actions</th></tr></thead>
                <tbody id="approvals-tbody">
                  <tr><td colspan="5" style="color:var(--muted);padding:1.5rem">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- STUDENTS -->
      <div class="page-section" id="sec-students">
        <div class="section-header"><div class="section-title">Class <span>Registry</span></div><button class="btn btn-rep" onclick="openModal('modal-add')">+ Add Student</button></div>
        <div class="filter-bar"><input type="text" id="s-search" placeholder="Search name or index number..." oninput="filterStudents()"></div>
        <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
          <table class="data-table" id="s-table">
            <thead><tr><th>#</th><th>Index No.</th><th>Full Name</th><th>Role</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach($students as $i=>$s): ?>
                <tr data-name="<?= strtolower($s['full_name']) ?>" data-index="<?= $s['index_no'] ?>">
                  <td style="color:var(--muted)"><?= $i+1 ?></td>
                  <td style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($s['index_no']) ?></td>
                  <td><?= htmlspecialchars($s['full_name']) ?></td>
                  <td><span class="pill pill-<?= $s['role']==='rep'?'rep':'steel' ?>"><?= $s['role'] ?></span></td>
                  <td>
                    <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= $s['id'] ?>,'<?= htmlspecialchars(addslashes($s['full_name'])) ?>','<?= $s['index_no'] ?>','<?= $s['email'] ?>')">Edit</button>
                    <?php if($s['role']!=='rep'): ?><button class="btn btn-danger btn-sm" onclick="confirmDel(<?= $s['id'] ?>)">Remove</button><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div></div>
      </div>

      <!-- ATTENDANCE -->
      <div class="page-section" id="sec-attendance">
        <div class="section-header"><div class="section-title">Attendance <span>Records</span></div><div style="display:flex;gap:.6rem;flex-wrap:wrap"><a href="../../api/export_attendance.php" class="btn btn-rep btn-sm">⬇ Export All CSV</a><a href="../../api/export_attendance.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">⬇ Today Only</a></div></div>
        <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
          <table class="data-table"><thead><tr><th>Student</th><th>Index No.</th><th>Course</th><th>Status</th><th>Time</th></tr></thead>
          <tbody>
            <?php $allAtt=$pdo->query("SELECT a.*,u.full_name,u.index_no,s.course_code FROM attendance a JOIN users u ON a.student_id=u.id JOIN sessions s ON a.session_id=s.id ORDER BY a.timestamp DESC LIMIT 100")->fetchAll();
            if(empty($allAtt)): ?><tr><td colspan="5" style="color:var(--muted)">No records yet.</td></tr>
            <?php else: foreach($allAtt as $r): ?>
              <tr><td><?= htmlspecialchars($r['full_name']) ?></td><td style="color:var(--gold);font-size:.78rem"><?= $r['index_no'] ?></td><td><?= $r['course_code'] ?></td><td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'warn':'red')) ?>"><?= $r['status'] ?></span></td><td style="color:var(--muted);font-size:.75rem"><?= date('d M Y H:i',strtotime($r['timestamp'])) ?></td></tr>
            <?php endforeach; endif; ?>
          </tbody></table>
        </div></div>
      </div>

      <!-- ANNOUNCEMENTS -->
      <div class="page-section" id="sec-announce">
        <div class="section-header"><div class="section-title">Class <span>Announcements</span></div></div>
        <div class="card"><div class="card-head"><div class="card-head-title">Post Announcement</div></div>
          <div class="card-body">

            <form method="POST">
              <input type="hidden" name="action" value="announce">
              <div style="background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--rep);padding:1rem 1.2rem;border-radius:2px;margin-bottom:1rem">
                <textarea name="message" placeholder="Type a message to the class..." style="width:100%;background:transparent;border:none;color:var(--text);font-family:DM Sans,sans-serif;font-size:.88rem;resize:vertical;outline:none;min-height:80px" required></textarea>
              </div>
              <button type="submit" class="btn btn-rep">Post to Class</button>
            </form>

        <div class="card" style="margin-top:1.5rem"><div class="card-head"><div class="card-head-title">Recent Announcements</div></div><div class="card-body" style="padding:0"><table class="data-table"><thead><tr><th>Message</th><th>Date</th></tr></thead><tbody><?php $ann=$pdo->query("SELECT a.*,u.full_name FROM announcements a JOIN users u ON a.rep_id=u.id ORDER BY a.created_at DESC LIMIT 20")->fetchAll();if(empty($ann)):?><tr><td colspan="2" style="color:var(--muted)">No announcements yet.</td></tr><?php else:foreach($ann as $a):?><tr><td><?=htmlspecialchars($a["message"])?></td><td style="color:var(--muted);font-size:.72rem;white-space:nowrap"><?=date("d M Y H:i",strtotime($a["created_at"]))?></td></tr><?php endforeach;endif;?></tbody></table></div></div>


          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-head"><div class="modal-title">ADD STUDENT</div><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
    <div class="modal-body"><form method="POST"><input type="hidden" name="action" value="add">
      <div class="form-row"><div class="form-field"><label>Full Name</label><input type="text" name="full_name" required placeholder="Surname, Firstname"></div><div class="form-field"><label>Index Number</label><input type="text" name="index_no" required placeholder="52430540000"></div></div>
      <div class="form-field"><label>Email (optional)</label><input type="email" name="email" placeholder="auto-generated if blank"></div>
      <button type="submit" class="btn btn-rep" style="width:100%">Add Student</button>
    </form></div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-head"><div class="modal-title">EDIT STUDENT</div><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
    <div class="modal-body"><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="e-id">
      <div class="form-row"><div class="form-field"><label>Full Name</label><input type="text" name="full_name" id="e-name" required></div><div class="form-field"><label>Index Number</label><input type="text" name="index_no" id="e-index" required></div></div>
      <div class="form-field"><label>Email</label><input type="email" name="email" id="e-email"></div>
      <button type="submit" class="btn btn-rep" style="width:100%">Save Changes</button>
    </form></div>
  </div>
</div>

<form method="POST" id="del-form"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="del-id"></form>

<!-- Selfie viewer overlay -->
<div id="selfie-overlay" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.92);align-items:center;justify-content:center;flex-direction:column;gap:1rem">
  <img id="selfie-big" src="" style="max-width:90%;max-height:70vh;border-radius:4px;border:2px solid var(--border)">
  <div id="selfie-name" style="color:var(--text);font-family:'Cinzel',serif;font-size:.9rem"></div>
  <button onclick="closeSelfie()" style="color:var(--text);background:none;border:1px solid var(--border);padding:.5rem 1.5rem;cursor:pointer;border-radius:2px;font-family:'DM Sans',sans-serif">Close</button>
</div>

<script>
function showSection(name,el){document.querySelectorAll('.page-section').forEach(s=>s.classList.remove('active'));document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));document.getElementById('sec-'+name).classList.add('active');document.getElementById('page-title').textContent=name.toUpperCase();if(el)el.classList.add('active');document.getElementById('sidebar').classList.remove('open')}
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>{o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open')})});
function openEdit(id,name,index,email){document.getElementById('e-id').value=id;document.getElementById('e-name').value=name;document.getElementById('e-index').value=index;document.getElementById('e-email').value=email;openModal('modal-edit')}
function confirmDel(id){if(confirm('Remove this student?')){document.getElementById('del-id').value=id;document.getElementById('del-form').submit()}}
function filterStudents(){const q=document.getElementById('s-search').value.toLowerCase();document.querySelectorAll('#s-table tbody tr').forEach(tr=>{tr.style.display=((tr.dataset.name||'').includes(q)||(tr.dataset.index||'').includes(q))?'':'none'})}
function fillName(){const sel=document.getElementById('course-sel');const opt=sel?.options[sel.selectedIndex];const f=document.getElementById('course-name');if(opt&&f)f.value=opt.dataset.name||''}
function viewSelfie(url,name){const ov=document.getElementById('selfie-overlay');document.getElementById('selfie-big').src=url;document.getElementById('selfie-name').textContent=name;ov.style.display='flex'}
function closeSelfie(){document.getElementById('selfie-overlay').style.display='none'}
if(window.innerWidth<=768)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=768?'block':'none'});

<?php if($activeSession): ?>
// ── COUNTDOWN RING ──
let timeLeft=<?= $timeRemaining ?>;
const ringFill=document.getElementById('ring-fill');
const ringNum=document.getElementById('ring-num');
function updateRing(){if(!ringFill)return;const offset=150.8*(1-timeLeft/120);ringFill.style.strokeDashoffset=offset;ringNum.textContent=timeLeft;ringFill.style.stroke=timeLeft<=20?'var(--danger)':timeLeft<=60?'var(--warning)':'var(--rep)'}
updateRing();
setInterval(()=>{timeLeft--;if(timeLeft<0)timeLeft=119;updateRing()},1000);

// Refresh code every 2 mins
setInterval(()=>{fetch('../../api/get_code.php?session_id=<?= $activeSession['id'] ?>').then(r=>r.json()).then(d=>{if(d.code){const el=document.getElementById('live-code');if(el)el.textContent=d.code.slice(0,3)+' '+d.code.slice(3)}})},120000);

// Poll live approved attendance
setInterval(()=>{fetch('../../api/live_attendance.php?session_id=<?= $activeSession['id'] ?>').then(r=>r.json()).then(data=>{if(!data.rows)return;const tbody=document.getElementById('live-tbody');const pill=document.getElementById('live-pill');const count=document.getElementById('live-count');if(count)count.textContent=data.total;if(pill)pill.textContent=data.total+' present';if(tbody&&data.rows.length>0){const empty=document.getElementById('empty-row');if(empty)empty.remove();tbody.innerHTML=data.rows.map(r=>`<tr><td>${r.full_name}</td><td style="color:var(--gold);font-size:.78rem">${r.index_no}</td><td><span class="pill pill-green">${r.status}</span></td><td style="color:var(--muted);font-size:.72rem">${r.time}</td></tr>`).join('')}})},10000);

// ── APPROVALS POLLING ──
function loadApprovals(){
  fetch('../../api/pending_approvals.php?session_id=<?= $activeSession['id'] ?>')
    .then(r=>r.json())
    .then(data=>{
      const tbody=document.getElementById('approvals-tbody');
      const badge=document.getElementById('pending-badge');
      const countBadge=document.getElementById('approvals-count-badge');
      const pendingCount=document.getElementById('pending-count');
      if(countBadge) countBadge.textContent=data.total+' pending';
      if(pendingCount) pendingCount.textContent=data.total;
      if(badge){badge.textContent=data.total;badge.style.display=data.total>0?'inline':'none'}
      if(!tbody) return;
      if(data.rows.length===0){tbody.innerHTML='<tr><td colspan="5" style="color:var(--muted);padding:1.5rem">No pending approvals. ✓</td></tr>';return}
      tbody.innerHTML=data.rows.map(r=>`
        <tr id="arow-${r.id}">
          <td style="font-weight:500">${r.full_name}</td>
          <td style="color:var(--gold);font-size:.78rem">${r.index_no}</td>
          <td style="display:flex;gap:.4rem">
            <img src="../../${r.selfie_url}" class="selfie-thumb" onclick="viewSelfie('../../${r.selfie_url}','${r.full_name} — Face')" title="Face photo">
            ${r.classroom_url ? `<img src="../../${r.classroom_url}" class="selfie-thumb" onclick="viewSelfie('../../${r.classroom_url}','${r.full_name} — Classroom')" title="Classroom photo" style="border-color:var(--steel)">` : '<span style="color:var(--muted);font-size:.7rem">No classroom</span>'}
          </td>
          <td style="color:var(--muted);font-size:.72rem">${r.time}</td>
          <td style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button class="btn btn-rep btn-sm" onclick="approveAtt(${r.id},'approve')">✓ Approve</button>
            <button class="btn btn-danger btn-sm" onclick="approveAtt(${r.id},'reject')">✕ Reject</button>
          </td>
        </tr>`).join('');
    });
}
loadApprovals();
setInterval(loadApprovals, 6000);

async function approveAtt(id,action){
  const row=document.getElementById('arow-'+id);
  if(row){row.style.opacity='0.4';row.style.pointerEvents='none'}
  try{
    const res=await fetch('../../api/approve_attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({attendance_id:id,action})});
    const data=await res.json();
    if(data.success) loadApprovals();
    else if(row){row.style.opacity='1';row.style.pointerEvents='auto'}
  }catch(e){if(row){row.style.opacity='1';row.style.pointerEvents='auto'}}
}
<?php endif; ?>
</script>
</body>
</html>
