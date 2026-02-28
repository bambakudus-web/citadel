<?php
require_once '../../includes/security.php';
// pages/lecturer/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole('lecturer');

$user   = currentUser();
$userId = $user['id'];

// Lecturer's timetable
$today = date('l');
$myClasses = $pdo->prepare("SELECT * FROM timetable WHERE lecturer_id=? ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'), start_time");
$myClasses->execute([$userId]);
$myClasses = $myClasses->fetchAll();

$todayClasses = $pdo->prepare("SELECT * FROM timetable WHERE lecturer_id=? AND day_of_week=? ORDER BY start_time");
$todayClasses->execute([$userId, $today]);
$todayClasses = $todayClasses->fetchAll();

// Active session for this lecturer
$activeSession = $pdo->prepare("SELECT * FROM sessions WHERE lecturer_id=? AND active_status=1 ORDER BY start_time DESC LIMIT 1");
$activeSession->execute([$userId]);
$activeSession = $activeSession->fetch();

// Past sessions
$pastSessions = $pdo->prepare("
    SELECT s.*, COUNT(a.id) as attended,
           (SELECT COUNT(*) FROM users WHERE role='student') as total_students
    FROM sessions s
    LEFT JOIN attendance a ON s.id=a.session_id
    WHERE s.lecturer_id=?
    GROUP BY s.id
    ORDER BY s.start_time DESC LIMIT 20
");
$pastSessions->execute([$userId]);
$pastSessions = $pastSessions->fetchAll();

// Handle start session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_session') {
    $courseCode = trim($_POST['course_code'] ?? '');
    $courseName = trim($_POST['course_name'] ?? '');
    $secretKey  = bin2hex(random_bytes(16)); // 32-char hex secret

    // Close any existing active sessions for this lecturer
    $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE lecturer_id=? AND active_status=1")->execute([$userId]);

    // Create new session
    $ins = $pdo->prepare("INSERT INTO sessions (course_code, course_name, lecturer_id, secret_key, start_time, active_status) VALUES (?,?,?,?,NOW(),1)");
    $ins->execute([$courseCode, $courseName, $userId, $secretKey]);
    $newId = $pdo->lastInsertId();

    $activeSession = $pdo->prepare("SELECT * FROM sessions WHERE id=?")->execute([$newId]);
    $activeSession = $pdo->query("SELECT * FROM sessions WHERE id=$newId")->fetch();
    header('Location: dashboard.php');
    exit;
}

// Handle end session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end_session') {
    $sid = (int)($_POST['session_id'] ?? 0);
    $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE id=? AND lecturer_id=?")->execute([$sid, $userId]);
    header('Location: dashboard.php');
    exit;
}

// Generate current code for display
function generateCode(string $secret, int $window): string {
    $hash   = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

$currentCode   = '';
$timeRemaining = 120 - (time() % 120);
if ($activeSession) {
    $window      = (int)floor(time() / 30);
    $currentCode = generateCode($activeSession['secret_key'], $window);
}

// Attendance for active session
$liveAttendance = [];
if ($activeSession) {
    $la = $pdo->prepare("
        SELECT u.full_name, u.index_no, a.status, a.timestamp, a.selfie_url
        FROM attendance a JOIN users u ON a.student_id=u.id
        WHERE a.session_id=?
        ORDER BY a.timestamp DESC
    ");
    $la->execute([$activeSession['id']]);
    $liveAttendance = $la->fetchAll();
}
$totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Lecturer</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #060910;
      --surface:  #0c1018;
      --surface2: #111722;
      --border:   #1a2535;
      --gold:     #c9a84c;
      --gold-dim: #7a5f28;
      --steel:    #4a6fa5;
      --steel-dim:#2a4060;
      --lec:      #8a6fd4;
      --lec-dim:  #3d2a6a;
      --text:     #e8eaf0;
      --muted:    #6b7a8d;
      --success:  #4caf82;
      --danger:   #e05c5c;
      --warning:  #e0a050;
      --sidebar-w:240px;
    }
    html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; overflow-x: hidden; }
    body::before { content: ''; position: fixed; inset: 0; z-index: 0; background: radial-gradient(ellipse 60% 40% at 50% 0%, rgba(138,111,212,0.1) 0%, transparent 60%); pointer-events: none; }
    .layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }

    /* ── Sidebar ── */
    .sidebar { width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; transition: transform 0.3s; }
    .sidebar-brand { padding: 1.6rem 1.4rem 1.2rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 0.8rem; }
    .sidebar-brand svg { width: 32px; height: 32px; flex-shrink: 0; }
    .brand-name { font-family: 'Cinzel', serif; font-size: 1rem; font-weight: 700; color: var(--gold); letter-spacing: 0.12em; }
    .brand-role { font-size: 0.62rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--lec); }
    .sidebar-nav { flex: 1; padding: 1rem 0; }
    .nav-section { font-size: 0.6rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--muted); padding: 0.8rem 1.4rem 0.4rem; }
    .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 1.4rem; color: var(--muted); text-decoration: none; font-size: 0.85rem; cursor: pointer; border-left: 2px solid transparent; transition: all 0.2s; }
    .nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
    .nav-item.active { color: var(--lec); border-left-color: var(--lec); background: rgba(138,111,212,0.06); }
    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    .sidebar-user { padding: 1rem 1.4rem; border-top: 1px solid var(--border); }
    .u-name { font-size: 0.82rem; color: var(--text); font-weight: 500; }
    .u-role { font-size: 0.68rem; color: var(--muted); margin-bottom: 0.5rem; }
    .sidebar-user a { color: var(--danger); text-decoration: none; font-size: 0.78rem; }

    /* ── Main ── */
    .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; }
    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.9rem 2rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
    .topbar-title { font-family: 'Cinzel', serif; font-size: 0.9rem; color: var(--gold); letter-spacing: 0.1em; }
    .badge-lec { font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; background: rgba(138,111,212,0.12); border: 1px solid var(--lec-dim); color: var(--lec); padding: 0.25rem 0.7rem; border-radius: 2px; }
    .content { padding: 2rem; flex: 1; }

    /* ── Sections ── */
    .page-section { display: none; }
    .page-section.active { display: block; animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.8rem; flex-wrap: wrap; gap: 1rem; }
    .section-title { font-family: 'Cinzel', serif; font-size: 1.1rem; color: var(--text); letter-spacing: 0.08em; }
    .section-title span { color: var(--lec); }

    /* ── Stats ── */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 2px; padding: 1.3rem 1.5rem; position: relative; overflow: hidden; }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
    .stat-card.lec::before   { background: linear-gradient(90deg, transparent, var(--lec), transparent); }
    .stat-card.gold::before  { background: linear-gradient(90deg, transparent, var(--gold), transparent); }
    .stat-card.green::before { background: linear-gradient(90deg, transparent, var(--success), transparent); }
    .stat-label { font-size: 0.65rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.4rem; }
    .stat-value { font-size: 1.9rem; font-weight: 600; color: var(--text); line-height: 1; }
    .stat-sub   { font-size: 0.7rem; color: var(--muted); margin-top: 0.35rem; }

    /* ── Card ── */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 2px; }
    .card-head { padding: 1rem 1.4rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .card-head-title { font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); }
    .card-body { padding: 1.2rem 1.4rem; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

    /* ── Table ── */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .data-table th { text-align: left; font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); padding: 0.6rem 0.8rem; border-bottom: 1px solid var(--border); }
    .data-table td { padding: 0.65rem 0.8rem; border-bottom: 1px solid rgba(30,42,53,0.5); color: var(--text); vertical-align: middle; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(255,255,255,0.02); }

    /* ── Pills ── */
    .pill { display: inline-block; font-size: 0.62rem; letter-spacing: 0.12em; text-transform: uppercase; padding: 0.2rem 0.6rem; border-radius: 2px; }
    .pill-green { background: rgba(76,175,130,0.12); color: var(--success); border: 1px solid rgba(76,175,130,0.3); }
    .pill-red   { background: rgba(224,92,92,0.12);  color: var(--danger);  border: 1px solid rgba(224,92,92,0.3); }
    .pill-gold  { background: rgba(201,168,76,0.12); color: var(--gold);    border: 1px solid rgba(201,168,76,0.3); }
    .pill-lec   { background: rgba(138,111,212,0.12);color: var(--lec);     border: 1px solid rgba(138,111,212,0.3); }

    /* ── Buttons ── */
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; font-family: 'DM Sans', sans-serif; font-size: 0.78rem; border: none; border-radius: 2px; cursor: pointer; transition: opacity 0.2s, transform 0.15s; }
    .btn:hover { opacity: 0.85; transform: translateY(-1px); }
    .btn-lec    { background: linear-gradient(135deg, var(--lec-dim), var(--lec)); color: #fff; font-weight: 600; }
    .btn-gold   { background: linear-gradient(135deg, var(--gold-dim), var(--gold)); color: #060910; font-weight: 600; }
    .btn-danger { background: rgba(224,92,92,0.15); color: var(--danger); border: 1px solid rgba(224,92,92,0.3); }
    .btn-ghost  { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .btn-sm     { padding: 0.3rem 0.7rem; font-size: 0.72rem; }

    /* ── Timetable ── */
    .tt-item { display: flex; align-items: center; gap: 1rem; background: var(--surface2); border: 1px solid var(--border); border-left: 3px solid var(--lec); padding: 0.7rem 1rem; border-radius: 2px; margin-bottom: 0.5rem; }
    .tt-time { font-size: 0.75rem; color: var(--gold); min-width: 100px; font-weight: 500; }
    .tt-course-code { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.1em; }
    .tt-course-name { font-size: 0.85rem; color: var(--text); }
    .tt-room { font-size: 0.72rem; color: var(--muted); }
    .tt-day  { font-family: 'Cinzel', serif; font-size: 0.78rem; color: var(--lec); letter-spacing: 0.15em; margin: 1.2rem 0 0.5rem; text-transform: uppercase; }

    /* ══ LIVE CODE DISPLAY ══ */
    .code-display-zone {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 2.5rem 2rem;
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .code-display-zone::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: linear-gradient(90deg, transparent, var(--lec), transparent);
    }

    .live-badge {
      display: inline-flex; align-items: center; gap: 0.4rem;
      font-size: 0.65rem; letter-spacing: 0.2em; text-transform: uppercase;
      color: var(--success);
      background: rgba(76,175,130,0.08);
      border: 1px solid rgba(76,175,130,0.25);
      padding: 0.25rem 0.8rem; border-radius: 2px;
      margin-bottom: 1.5rem;
    }
    .live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); animation: pulse 1s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

    .code-number {
      font-family: 'Cinzel', serif;
      font-size: 4rem;
      font-weight: 700;
      letter-spacing: 0.5em;
      color: var(--gold);
      text-shadow: 0 0 40px rgba(201,168,76,0.3);
      line-height: 1;
      margin-bottom: 0.5rem;
      padding-left: 0.5em; /* offset for letter-spacing */
    }

    .code-course {
      font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase;
      color: var(--muted); margin-bottom: 1.5rem;
    }

    /* Countdown ring */
    .code-timer { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: 1.8rem; }
    .ring-wrap { position: relative; width: 56px; height: 56px; }
    .ring-wrap svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .ring-wrap circle { fill: none; stroke-width: 3; }
    .ring-track { stroke: var(--border); }
    .ring-fill  { stroke: var(--lec); stroke-dasharray: 150.8; stroke-linecap: round; transition: stroke-dashoffset 1s linear, stroke 0.3s; }
    .ring-num   { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-family: 'Cinzel', serif; font-size: 0.95rem; font-weight: 700; color: var(--lec); }
    .timer-text { font-size: 0.72rem; color: var(--muted); }

    /* Attendance counter inside session */
    .attend-counter {
      display: flex; align-items: center; justify-content: center; gap: 2rem;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
    }
    .counter-item { text-align: center; }
    .counter-value { font-size: 1.6rem; font-weight: 600; color: var(--text); line-height: 1; }
    .counter-label { font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); margin-top: 0.2rem; }

    /* ── Start session form ── */
    .start-form {
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 1.6rem;
      max-width: 500px;
    }
    .form-field { margin-bottom: 1rem; }
    .form-field label { display: block; font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.4rem; }
    .form-field input, .form-field select { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 0.65rem 0.9rem; font-family: 'DM Sans', sans-serif; font-size: 0.88rem; border-radius: 2px; outline: none; transition: border-color 0.2s; }
    .form-field input:focus, .form-field select:focus { border-color: var(--lec); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

    /* ── Responsive ── */
    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

    @media (min-width: 769px) { #menu-btn { display: none; } }
    @media (min-width: 769px) { #menu-btn { display: none; } }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; }
      .content { padding: 1rem; }
      .topbar { padding: 0.8rem 1rem; }
      .code-number { font-size: 2rem; }
      .two-col { grid-template-columns: 1fr; }
      .data-table { font-size: .75rem; }
      .data-table th, .data-table td { padding: .5rem; }
      .tt-item { flex-direction: column; gap: .3rem; }
      .stat-value { font-size: 1.5rem; }
      .topbar-title { font-size: .78rem; }
      #menu-btn { display: block; }
    }







  </style>
</head>
<body>
<div class="layout">

  <!-- ── Sidebar ── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg viewBox="0 0 52 52" fill="none">
        <polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/>
        <polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/>
        <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#8a6fd4" stroke-width="1.5"/>
        <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
        <line x1="26" y1="27" x2="26" y2="31" stroke="#8a6fd4" stroke-width="1.5"/>
      </svg>
      <div>
        <div class="brand-name">CITADEL</div>
        <div class="brand-role">Lecturer</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section">Sessions</div>
      <a class="nav-item active" onclick="showSection('session', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Live Session
      </a>
      <a class="nav-item" onclick="showSection('history', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Past Sessions
      </a>
      <div class="nav-section">Schedule</div>
      <a class="nav-item" onclick="showSection('timetable', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        My Timetable
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? 'Lecturer') ?></div>
      <div class="u-role">Lecturer</div>
      <a href="../../change_password.php" style="color:var(--muted);font-size:.78rem;display:block;margin-bottom:.4rem">Change Password</a><a href="../../logout.php">Sign out</a>
    </div>
  </aside>

  <!-- ── Main ── -->
  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:1rem">
        <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" style="background:none;border:none;color:var(--muted);cursor:pointer;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title" id="page-title">LIVE SESSION</div>
      </div>
      <div style="display:flex;align-items:center;gap:1rem">
        <span style="font-size:0.75rem;color:var(--muted)"><?= date('l, d M Y') ?></span>
        <span class="badge-lec">Lecturer</span>
        <button id="theme-btn" onclick="toggleTheme()" style="background:none;border:1px solid var(--border);color:var(--muted);cursor:pointer;padding:.25rem .6rem;border-radius:2px;font-size:.75rem">🌙</button>
      </div>
    </div>

    <div class="content">

      <!-- ══ LIVE SESSION ══ -->
      <div class="page-section active" id="sec-session">

        <?php if ($activeSession): ?>
          <!-- ── ACTIVE SESSION VIEW ── -->
          <div class="section-header">
            <div class="section-title">Live: <span><?= htmlspecialchars($activeSession['course_code']) ?></span></div>
            <form method="POST">
              <input type="hidden" name="action" value="end_session">
              <input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>">
              <button type="submit" class="btn btn-danger" onclick="return confirm('End this attendance session?')">End Session</button>
            </form>
          </div>

          <div class="two-col">
            <!-- Code display -->
            <div>
              <div class="code-display-zone">
                <div class="live-badge"><div class="live-dot"></div>Session Active</div>

                <div class="code-course"><?= htmlspecialchars($activeSession['course_code']) ?> · <?= htmlspecialchars($activeSession['course_name'] ?? '') ?></div>

                <div class="code-number" id="live-code">
                  <?= chunk_split($currentCode, 3, ' ') ?>
                </div>

                <div class="code-timer">
                  <div class="ring-wrap">
                    <svg viewBox="0 0 54 54">
                      <circle class="ring-track" cx="27" cy="27" r="24"/>
                      <circle class="ring-fill" id="ring-fill" cx="27" cy="27" r="24" stroke-dashoffset="0"/>
                    </svg>
                    <div class="ring-num" id="ring-num"><?= $timeRemaining ?></div>
                  </div>
                  <div class="timer-text">seconds until<br>code refreshes</div>
                </div>

                <p style="font-size:0.72rem;color:var(--muted)">Show this code to the class. It refreshes every 30 seconds.</p>
              </div>

              <!-- Attendance count -->
              <div class="attend-counter" style="margin-top:1rem">
                <div class="counter-item">
                  <div class="counter-value" id="live-count"><?= count($liveAttendance) ?></div>
                  <div class="counter-label">Marked</div>
                </div>
                <div class="counter-item">
                  <div class="counter-value"><?= $totalStudents ?></div>
                  <div class="counter-label">Total</div>
                </div>
                <div class="counter-item">
                  <div class="counter-value" style="color:var(--danger)"><?= $totalStudents - count($liveAttendance) ?></div>
                  <div class="counter-label">Absent</div>
                </div>
              </div>
            </div>

            <!-- Live attendance list -->
            <div class="card">
              <div class="card-head">
                <div class="card-head-title">Live Attendance</div>
                <span class="pill pill-green" id="live-pill"><?= count($liveAttendance) ?> present</span>
              </div>
              <div class="card-body" style="padding:0;max-height:420px;overflow-y:auto" id="live-list">
                <table class="data-table">
                  <thead><tr><th>Student</th><th>Index</th><th>Status</th><th>Time</th></tr></thead>
                  <tbody id="live-tbody">
                    <?php if (empty($liveAttendance)): ?>
                      <tr id="empty-row"><td colspan="4" style="color:var(--muted)">Waiting for students...</td></tr>
                    <?php else: foreach ($liveAttendance as $a): ?>
                      <tr>
                        <td><?= htmlspecialchars($a['full_name']) ?></td>
                        <td style="color:var(--gold);font-size:0.78rem"><?= $a['index_no'] ?></td>
                        <td><span class="pill pill-<?= $a['status']==='present'?'green':'gold' ?>"><?= $a['status'] ?></span></td>
                        <td style="color:var(--muted);font-size:0.72rem"><?= date('H:i:s', strtotime($a['timestamp'])) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        <?php else: ?>
          <!-- ── START SESSION VIEW ── -->
          <div class="section-header">
            <div class="section-title">Start <span>Session</span></div>
          </div>

          <?php if (!empty($todayClasses)): ?>
            <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1.2rem">Quick start from today's schedule:</p>
            <div style="display:flex;flex-wrap:wrap;gap:0.7rem;margin-bottom:2rem">
              <?php foreach ($todayClasses as $tc): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="start_session">
                  <input type="hidden" name="course_code" value="<?= htmlspecialchars($tc['course_code']) ?>">
                  <input type="hidden" name="course_name" value="<?= htmlspecialchars($tc['course_name']) ?>">
                  <button type="submit" class="btn btn-lec">
                    ▶ <?= htmlspecialchars($tc['course_code']) ?> · <?= substr($tc['start_time'],0,5) ?>–<?= substr($tc['end_time'],0,5) ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="start-form">
            <p style="font-size:0.78rem;color:var(--muted);margin-bottom:1.2rem">Or start a manual session:</p>
            <form method="POST">
              <input type="hidden" name="action" value="start_session">
              <div class="form-row">
                <div class="form-field">
                  <label>Course Code</label>
                  <select name="course_code" id="course-sel" onchange="fillCourseName()">
                    <?php foreach ($myClasses as $mc): ?>
                      <option value="<?= htmlspecialchars($mc['course_code']) ?>" data-name="<?= htmlspecialchars($mc['course_name']) ?>">
                        <?= htmlspecialchars($mc['course_code']) ?>
                      </option>
                    <?php endforeach; ?>
                    <option value="">Other...</option>
                  </select>
                </div>
                <div class="form-field">
                  <label>Course Name</label>
                  <input type="text" name="course_name" id="course-name" value="<?= htmlspecialchars($myClasses[0]['course_name'] ?? '') ?>">
                </div>
              </div>
              <button type="submit" class="btn btn-lec" style="width:100%;justify-content:center;padding:0.8rem">
                Start Attendance Session
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <!-- ══ PAST SESSIONS ══ -->
      <div class="page-section" id="sec-history">
        <div class="section-header"><div class="section-title">Past <span>Sessions</span></div></div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table">
              <thead><tr><th>Course</th><th>Date</th><th>Duration</th><th>Attended</th><th>Rate</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (empty($pastSessions)): ?>
                  <tr><td colspan="6" style="color:var(--muted)">No sessions yet.</td></tr>
                <?php else: foreach ($pastSessions as $ps):
                  $dur = '—';
                  if ($ps['end_time']) {
                    $mins = (strtotime($ps['end_time']) - strtotime($ps['start_time'])) / 60;
                    $dur  = round($mins) . ' min';
                  }
                  $rate = $ps['total_students'] > 0 ? round(($ps['attended'] / $ps['total_students']) * 100) : 0;
                ?>
                  <tr>
                    <td style="color:var(--gold);font-size:0.78rem"><?= htmlspecialchars($ps['course_code']) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem"><?= date('d M Y H:i', strtotime($ps['start_time'])) ?></td>
                    <td><?= $dur ?></td>
                    <td><?= $ps['attended'] ?> / <?= $ps['total_students'] ?></td>
                    <td>
                      <span style="color:<?= $rate>=75?'var(--success)':($rate>=50?'var(--warning)':'var(--danger)') ?>;font-weight:600"><?= $rate ?>%</span>
                    </td>
                    <td><span class="pill pill-<?= $ps['active_status']?'green':'lec' ?>"><?= $ps['active_status']?'Active':'Closed' ?></span></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══ TIMETABLE ══ -->
      <div class="page-section" id="sec-timetable">
        <div class="section-header"><div class="section-title">My <span>Timetable</span></div></div>
        <?php
        $days = ['Monday','Tuesday','Wednesday','Thursday'];
        foreach ($days as $day):
          $dayCls = array_filter($myClasses, fn($c) => $c['day_of_week'] === $day);
          if (empty($dayCls)) continue;
        ?>
          <div class="tt-day"><?= $day ?></div>
          <?php foreach ($dayCls as $c): ?>
            <div class="tt-item">
              <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
              <div style="flex:1">
                <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                <div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?></div>
              </div>
              <form method="POST">
                <input type="hidden" name="action" value="start_session">
                <input type="hidden" name="course_code" value="<?= htmlspecialchars($c['course_code']) ?>">
                <input type="hidden" name="course_name" value="<?= htmlspecialchars($c['course_name']) ?>">
                <button type="submit" class="btn btn-lec btn-sm">Start</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>

<script>
// ── Navigation ──
function showSection(name, el) {
  document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  document.getElementById('page-title').textContent = name.replace('-',' ').toUpperCase();
  if (el) el.classList.add('active');
  document.getElementById('sidebar').classList.remove('open');
}

// ── Course name autofill ──
function fillCourseName() {
  const sel = document.getElementById('course-sel');
  const opt = sel?.options[sel.selectedIndex];
  const nameField = document.getElementById('course-name');
  if (opt && nameField) nameField.value = opt.dataset.name || '';
}

// ── Mobile ──
if (window.innerWidth <= 768) document.getElementById('menu-btn').style.display = 'block';
window.addEventListener('resize', () => {
  document.getElementById('menu-btn').style.display = window.innerWidth <= 768 ? 'block' : 'none';
});

// ══ LIVE CODE COUNTDOWN ══
<?php if ($activeSession): ?>
let timeLeft = <?= $timeRemaining ?>;
const circumference = 150.8;
const ringFill = document.getElementById('ring-fill');
const ringNum  = document.getElementById('ring-num');
const liveCode = document.getElementById('live-code');
const sessionId = <?= $activeSession['id'] ?>;
const secretKey = '<?= $activeSession['secret_key'] ?>';

function updateRing() {
  if (!ringFill) return;
  const offset = circumference * (1 - timeLeft / 120);
  ringFill.style.strokeDashoffset = offset;
  ringNum.textContent = timeLeft;
  if (timeLeft <= 10) ringFill.style.stroke = 'var(--danger)';
  else if (timeLeft <= 20) ringFill.style.stroke = 'var(--warning)';
  else ringFill.style.stroke = 'var(--lec)';
}

updateRing();

setInterval(() => {
  timeLeft--;
  if (timeLeft < 0) timeLeft = 119;
  updateRing();
}, 1000);

// Fetch fresh code from server every 30s
setInterval(() => {
  fetch('../../api/get_code.php?session_id=' + sessionId)
    .then(r => r.json())
    .then(d => {
      if (d.code && liveCode) {
        liveCode.textContent = d.code.slice(0,3) + ' ' + d.code.slice(3);
      }
    });
}, 30000);

// Poll live attendance every 10s
setInterval(() => {
  fetch('../../api/live_attendance.php?session_id=' + sessionId)
    .then(r => r.json())
    .then(data => {
      if (!data.rows) return;
      const tbody = document.getElementById('live-tbody');
      const pill  = document.getElementById('live-pill');
      const count = document.getElementById('live-count');
      if (count) count.textContent = data.total;
      if (pill)  pill.textContent  = data.total + ' present';
      if (tbody && data.rows.length > 0) {
        const empty = document.getElementById('empty-row');
        if (empty) empty.remove();
        tbody.innerHTML = data.rows.map(r => `
          <tr>
            <td>${r.full_name}</td>
            <td style="color:var(--gold);font-size:0.78rem">${r.index_no}</td>
            <td><span class="pill pill-${r.status==='present'?'green':'gold'}">${r.status}</span></td>
            <td style="color:var(--muted);font-size:0.72rem">${r.time}</td>
          </tr>
        `).join('');
      }
    });
}, 10000);

<?php endif; ?>
// Theme toggle
function toggleTheme(){const body=document.body;const btn=document.getElementById("theme-btn");if(body.classList.contains("light")){body.classList.remove("light");localStorage.setItem("theme","dark");if(btn)btn.textContent="🌙";}else{body.classList.add("light");localStorage.setItem("theme","light");if(btn)btn.textContent="☀️";}}
(function(){if(localStorage.getItem("theme")==="light"){document.body.classList.add("light");const btn=document.getElementById("theme-btn");if(btn)btn.textContent="☀️";}})();
</script>
<script>
// Auto-inject CSRF token into all forms
const csrfToken = "<?= csrfToken() ?>";
document.querySelectorAll('form').forEach(form => {
    if (!form.querySelector('[name="csrf_token"]')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = csrfToken;
        form.appendChild(input);
    }
});
// Add CSRF to all fetch requests
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
    options.headers = options.headers || {};
    options.headers['X-CSRF-Token'] = csrfToken;
    return originalFetch(url, options);
};
</script>
</body>
</html>
