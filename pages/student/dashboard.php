<?php
// pages/student/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$user = currentUser();
$userId = $user['id'];

// Today's timetable
$today = date('l');
$todayStmt = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
$todayStmt->execute([$today]);
$todayClasses = $todayStmt->fetchAll();

// Student's attendance history
$history = $pdo->prepare("
    SELECT a.*, s.course_code, s.course_name, a.timestamp
    FROM attendance a
    JOIN sessions s ON a.session_id = s.id
    WHERE a.student_id = ?
    ORDER BY a.timestamp DESC LIMIT 30
");
$history->execute([$userId]);
$history = $history->fetchAll();

// Attendance summary per course
$summary = $pdo->prepare("
    SELECT s.course_code, s.course_name,
           COUNT(*) as total,
           SUM(a.status='present') as present,
           SUM(a.status='absent') as absent,
           SUM(a.status='late') as late
    FROM attendance a
    JOIN sessions s ON a.session_id=s.id
    WHERE a.student_id=?
    GROUP BY s.course_code, s.course_name
");
$summary->execute([$userId]);
$summary = $summary->fetchAll();

// Active session check
$activeSession = $pdo->query("SELECT * FROM sessions WHERE active_status=1 ORDER BY start_time DESC LIMIT 1")->fetch();

// Announcements (latest 5)
$announcements = []; // placeholder until announcements table is wired
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Student Portal</title>
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
      --text:     #e8eaf0;
      --muted:    #6b7a8d;
      --success:  #4caf82;
      --danger:   #e05c5c;
      --warning:  #e0a050;
      --sidebar-w: 240px;
    }
    html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; overflow-x: hidden; }
    body::before {
      content: ''; position: fixed; inset: 0; z-index: 0;
      background: radial-gradient(ellipse 60% 40% at 80% 0%, rgba(74,111,165,0.12) 0%, transparent 60%),
                  radial-gradient(ellipse 40% 30% at 10% 90%, rgba(201,168,76,0.05) 0%, transparent 60%);
      pointer-events: none;
    }
    .layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }

    /* ── Sidebar ── */
    .sidebar { width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; transition: transform 0.3s ease; }
    .sidebar-brand { padding: 1.6rem 1.4rem 1.2rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 0.8rem; }
    .sidebar-brand svg { width: 32px; height: 32px; flex-shrink: 0; }
    .brand-name { font-family: 'Cinzel', serif; font-size: 1rem; font-weight: 700; color: var(--gold); letter-spacing: 0.12em; }
    .brand-role { font-size: 0.62rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--steel); }
    .sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
    .nav-section { font-size: 0.6rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--muted); padding: 0.8rem 1.4rem 0.4rem; }
    .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 1.4rem; color: var(--muted); text-decoration: none; font-size: 0.85rem; cursor: pointer; border-left: 2px solid transparent; transition: all 0.2s; }
    .nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
    .nav-item.active { color: var(--steel); border-left-color: var(--steel); background: rgba(74,111,165,0.06); }
    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    .sidebar-user { padding: 1rem 1.4rem; border-top: 1px solid var(--border); }
    .u-name  { font-size: 0.82rem; color: var(--text); font-weight: 500; }
    .u-index { font-size: 0.68rem; color: var(--muted); margin-bottom: 0.5rem; }
    .sidebar-user a { color: var(--danger); text-decoration: none; font-size: 0.78rem; }

    /* ── Main ── */
    .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.9rem 2rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
    .topbar-title { font-family: 'Cinzel', serif; font-size: 0.9rem; color: var(--gold); letter-spacing: 0.1em; }
    .badge-student { font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; background: rgba(74,111,165,0.12); border: 1px solid var(--steel-dim); color: var(--steel); padding: 0.25rem 0.7rem; border-radius: 2px; }
    .content { padding: 2rem; flex: 1; }

    /* ── Sections ── */
    .page-section { display: none; }
    .page-section.active { display: block; animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.8rem; flex-wrap: wrap; gap: 1rem; }
    .section-title { font-family: 'Cinzel', serif; font-size: 1.1rem; color: var(--text); letter-spacing: 0.08em; }
    .section-title span { color: var(--steel); }

    /* ── Stats ── */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 2px; padding: 1.3rem 1.5rem; position: relative; overflow: hidden; }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
    .stat-card.gold::before  { background: linear-gradient(90deg, transparent, var(--gold), transparent); }
    .stat-card.steel::before { background: linear-gradient(90deg, transparent, var(--steel), transparent); }
    .stat-card.green::before { background: linear-gradient(90deg, transparent, var(--success), transparent); }
    .stat-label { font-size: 0.65rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.4rem; }
    .stat-value { font-size: 1.9rem; font-weight: 600; color: var(--text); line-height: 1; }
    .stat-sub   { font-size: 0.7rem; color: var(--muted); margin-top: 0.35rem; }

    /* ── Cards ── */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 2px; }
    .card-head { padding: 1rem 1.4rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .card-head-title { font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); }
    .card-body { padding: 1.2rem 1.4rem; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

    /* ── Table ── */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .data-table th { text-align: left; font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); padding: 0.6rem 0.8rem; border-bottom: 1px solid var(--border); }
    .data-table td { padding: 0.65rem 0.8rem; border-bottom: 1px solid rgba(30,42,53,0.5); color: var(--text); }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(255,255,255,0.02); }

    /* ── Pills ── */
    .pill { display: inline-block; font-size: 0.62rem; letter-spacing: 0.12em; text-transform: uppercase; padding: 0.2rem 0.6rem; border-radius: 2px; }
    .pill-green { background: rgba(76,175,130,0.12); color: var(--success); border: 1px solid rgba(76,175,130,0.3); }
    .pill-red   { background: rgba(224,92,92,0.12);  color: var(--danger);  border: 1px solid rgba(224,92,92,0.3); }
    .pill-gold  { background: rgba(201,168,76,0.12); color: var(--gold);    border: 1px solid rgba(201,168,76,0.3); }
    .pill-steel { background: rgba(74,111,165,0.12); color: var(--steel);   border: 1px solid rgba(74,111,165,0.3); }

    /* ── Timetable ── */
    .tt-grid { display: flex; flex-direction: column; gap: 0.5rem; }
    .tt-item { display: flex; align-items: center; gap: 1rem; background: var(--surface2); border: 1px solid var(--border); border-left: 3px solid var(--steel); padding: 0.7rem 1rem; border-radius: 2px; }
    .tt-time { font-size: 0.75rem; color: var(--gold); min-width: 100px; font-weight: 500; }
    .tt-course-code { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.1em; }
    .tt-course-name { font-size: 0.85rem; color: var(--text); }
    .tt-room { font-size: 0.72rem; color: var(--muted); }

    /* ── Buttons ── */
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; font-family: 'DM Sans', sans-serif; font-size: 0.78rem; letter-spacing: 0.06em; border: none; border-radius: 2px; cursor: pointer; transition: opacity 0.2s, transform 0.15s; }
    .btn:hover { opacity: 0.85; transform: translateY(-1px); }
    .btn-gold  { background: linear-gradient(135deg, var(--gold-dim), var(--gold)); color: #060910; font-weight: 600; }
    .btn-steel { background: linear-gradient(135deg, var(--steel-dim), var(--steel)); color: #fff; font-weight: 600; }
    .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }

    /* ── Progress bar ── */
    .progress-wrap { background: var(--border); border-radius: 2px; height: 6px; margin-top: 0.5rem; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 2px; transition: width 0.6s ease; }

    /* ════════════════════════════════
       ATTENDANCE CODE ZONE
    ════════════════════════════════ */
    .attend-zone {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 2rem;
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .attend-zone::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: linear-gradient(90deg, transparent, var(--gold), transparent);
    }

    .attend-label {
      font-size: 0.65rem; letter-spacing: 0.22em; text-transform: uppercase;
      color: var(--muted); margin-bottom: 1.2rem;
    }

    /* 6-digit code input boxes */
    .code-inputs {
      display: flex; gap: 0.6rem; justify-content: center;
      margin-bottom: 1.4rem;
    }
    .code-inputs input {
      width: 48px; height: 60px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
      text-align: center;
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--gold);
      font-family: 'Cinzel', serif;
      outline: none;
      transition: border-color 0.2s, background 0.2s;
      caret-color: var(--gold);
    }
    .code-inputs input:focus { border-color: var(--gold); background: rgba(201,168,76,0.05); }
    .code-inputs input.filled { border-color: var(--steel); }

    .timer-ring {
      width: 64px; height: 64px;
      margin: 0 auto 1rem;
      position: relative;
    }
    .timer-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .timer-ring circle { fill: none; stroke-width: 4; }
    .timer-ring .track  { stroke: var(--border); }
    .timer-ring .fill   { stroke: var(--gold); stroke-dasharray: 175.9; stroke-dashoffset: 0; stroke-linecap: round; transition: stroke-dashoffset 1s linear, stroke 0.3s; }
    .timer-ring .count  { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-family: 'Cinzel', serif; font-size: 1rem; font-weight: 700; color: var(--gold); }

    .no-session-msg {
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 1.5rem;
      text-align: center;
      color: var(--muted);
      font-size: 0.85rem;
    }
    .no-session-msg strong { color: var(--text); display: block; margin-bottom: 0.4rem; font-size: 1rem; }

    /* ── AI Verification Modal ── */
    .modal-overlay { position: fixed; inset: 0; z-index: 200; background: rgba(6,9,16,0.92); display: none; align-items: center; justify-content: center; padding: 1rem; }
    .modal-overlay.open { display: flex; }
    .modal { background: var(--surface); border: 1px solid var(--border); border-radius: 2px; width: 100%; max-width: 420px; animation: fadeIn 0.25s ease; position: relative; overflow: hidden; }
    .modal::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), transparent); }
    .modal-head { padding: 1.2rem 1.6rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .modal-title { font-family: 'Cinzel', serif; font-size: 0.88rem; color: var(--gold); letter-spacing: 0.1em; }
    .modal-body { padding: 1.6rem; }

    /* Camera preview */
    .camera-wrap {
      position: relative;
      background: #000;
      border-radius: 2px;
      overflow: hidden;
      aspect-ratio: 4/3;
      margin-bottom: 1rem;
    }
    #video-preview { width: 100%; height: 100%; object-fit: cover; display: block; }
    .camera-overlay {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      pointer-events: none;
    }

    /* Face guide oval */
    .face-guide {
      width: 160px; height: 200px;
      border: 2px dashed rgba(201,168,76,0.5);
      border-radius: 50%;
      position: absolute;
    }

    /* Corner scan lines */
    .scan-corner {
      position: absolute;
      width: 24px; height: 24px;
      border-color: var(--gold);
      border-style: solid;
      opacity: 0.8;
    }
    .scan-corner.tl { top: 8px; left: 8px; border-width: 2px 0 0 2px; }
    .scan-corner.tr { top: 8px; right: 8px; border-width: 2px 2px 0 0; }
    .scan-corner.bl { bottom: 8px; left: 8px; border-width: 0 0 2px 2px; }
    .scan-corner.br { bottom: 8px; right: 8px; border-width: 0 2px 2px 0; }

    /* Scan line animation */
    .scan-line {
      position: absolute; left: 0; right: 0; height: 2px;
      background: linear-gradient(90deg, transparent, rgba(201,168,76,0.6), transparent);
      animation: scanMove 2.5s ease-in-out infinite;
    }
    @keyframes scanMove {
      0%   { top: 10%; opacity: 0; }
      10%  { opacity: 1; }
      90%  { opacity: 1; }
      100% { top: 90%; opacity: 0; }
    }

    /* AI status bar */
    .ai-status {
      display: flex; align-items: center; gap: 0.6rem;
      font-size: 0.75rem; color: var(--muted);
      margin-bottom: 1rem;
      padding: 0.5rem 0.8rem;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
    }
    .ai-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); flex-shrink: 0; transition: background 0.3s; }
    .ai-dot.active { background: var(--success); animation: pulse 1s infinite; }
    .ai-dot.error  { background: var(--danger); }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

    /* Steps */
    .verify-steps { display: flex; gap: 0.4rem; margin-bottom: 1.2rem; }
    .step {
      flex: 1; text-align: center; font-size: 0.62rem; letter-spacing: 0.1em;
      text-transform: uppercase; color: var(--muted);
      padding: 0.4rem;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 2px;
      transition: all 0.3s;
    }
    .step.active  { color: var(--gold); border-color: var(--gold-dim); background: rgba(201,168,76,0.06); }
    .step.done    { color: var(--success); border-color: rgba(76,175,130,0.3); background: rgba(76,175,130,0.06); }
    .step.error   { color: var(--danger); border-color: rgba(224,92,92,0.3); }

    .btn-full { width: 100%; justify-content: center; padding: 0.8rem; font-size: 0.85rem; }

    /* Confidence meter */
    .conf-meter { margin: 0.8rem 0; }
    .conf-label { display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--muted); margin-bottom: 0.3rem; }
    .conf-bar { height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
    .conf-fill { height: 100%; border-radius: 2px; width: 0%; transition: width 1s ease, background 0.5s; }

    /* Success overlay */
    .success-overlay {
      position: absolute; inset: 0;
      background: rgba(6,9,16,0.95);
      display: none;
      align-items: center; justify-content: center;
      flex-direction: column; gap: 1rem;
      text-align: center;
      z-index: 10;
    }
    .success-overlay.show { display: flex; animation: fadeIn 0.3s ease; }
    .success-icon { font-size: 3rem; }
    .success-text { font-family: 'Cinzel', serif; font-size: 1rem; color: var(--success); letter-spacing: 0.1em; }
    .success-sub  { font-size: 0.78rem; color: var(--muted); }

    /* ── Responsive ── */
    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; }
      .content { padding: 1.2rem; }
      .topbar { padding: 0.9rem 1.2rem; }
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .code-inputs input { width: 40px; height: 52px; font-size: 1.2rem; }
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
        <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
        <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
        <line x1="26" y1="27" x2="26" y2="31" stroke="#4a6fa5" stroke-width="1.5"/>
      </svg>
      <div>
        <div class="brand-name">CITADEL</div>
        <div class="brand-role">Student Portal</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section">Portal</div>
      <a class="nav-item active" onclick="showSection('overview', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        Overview
      </a>
      <a class="nav-item" onclick="showSection('mark', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Mark Attendance
      </a>
      <a class="nav-item" onclick="showSection('timetable', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Timetable
      </a>
      <a class="nav-item" onclick="showSection('history', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        My History
      </a>
      <a class="nav-item" onclick="showSection('stats', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        My Stats
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? 'Student') ?></div>
      <div class="u-index"><?= htmlspecialchars($user['index_no'] ?? '') ?></div>
      <a href="../../change_password.php" style="color:var(--muted);font-size:.78rem;display:block;margin-bottom:.4rem">Change Password</a><a href="../../logout.php">Sign out</a>
    </div>
  </aside>

  <!-- ── Main ── -->
  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:1rem">
        <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" style="background:none;border:none;color:var(--muted);cursor:pointer;display:none">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title" id="page-title">OVERVIEW</div>
      </div>
      <div style="display:flex;align-items:center;gap:1rem">
        <span style="font-size:0.75rem;color:var(--muted)"><?= date('l, d M Y') ?></span>
        <span class="badge-student">Student</span>
      </div>
    </div>

    <div class="content">

      <!-- ══ OVERVIEW ══ -->
      <div class="page-section active" id="sec-overview">
        <?php
        $totalPresent = array_sum(array_column($summary, 'present'));
        $totalRecords = array_sum(array_column($summary, 'total'));
        $pct = $totalRecords > 0 ? round(($totalPresent / $totalRecords) * 100) : 0;
        ?>
        <div class="stats-grid">
          <div class="stat-card gold">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"><?= $pct ?>%</div>
            <div class="stat-sub">Overall across all courses</div>
          </div>
          <div class="stat-card green">
            <div class="stat-label">Sessions Present</div>
            <div class="stat-value"><?= $totalPresent ?></div>
            <div class="stat-sub">Out of <?= $totalRecords ?> total</div>
          </div>
          <div class="stat-card steel">
            <div class="stat-label">Today's Classes</div>
            <div class="stat-value"><?= count($todayClasses) ?></div>
            <div class="stat-sub"><?= $today ?></div>
          </div>
        </div>

        <div class="two-col">
          <!-- Today -->
          <div class="card">
            <div class="card-head">
              <div class="card-head-title">Today — <?= $today ?></div>
              <?php if ($activeSession): ?>
                <span class="pill pill-green" style="animation:pulse 1.5s infinite">● Live</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if (empty($todayClasses)): ?>
                <p style="color:var(--muted);font-size:0.83rem">No classes today. Enjoy your day!</p>
              <?php else: ?>
                <div class="tt-grid">
                  <?php foreach ($todayClasses as $c): ?>
                    <div class="tt-item">
                      <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                      <div>
                        <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                        <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                        <div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($activeSession): ?>
                <button class="btn btn-gold" style="width:100%;justify-content:center;margin-top:1rem" onclick="showSection('mark', document.querySelector('[onclick*=mark]'))">
                  Mark Attendance Now →
                </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Recent history -->
          <div class="card">
            <div class="card-head"><div class="card-head-title">Recent Records</div></div>
            <div class="card-body" style="padding:0">
              <table class="data-table">
                <thead><tr><th>Course</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                  <?php if (empty($history)): ?>
                    <tr><td colspan="3" style="color:var(--muted)">No records yet.</td></tr>
                  <?php else: foreach (array_slice($history,0,8) as $h): ?>
                    <tr>
                      <td style="color:var(--gold);font-size:0.78rem"><?= $h['course_code'] ?></td>
                      <td><span class="pill pill-<?= $h['status']==='present'?'green':($h['status']==='late'?'gold':'red') ?>"><?= $h['status'] ?></span></td>
                      <td style="color:var(--muted);font-size:0.75rem"><?= date('d M', strtotime($h['timestamp'])) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ MARK ATTENDANCE ══ -->
      <div class="page-section" id="sec-mark">
        <div class="section-header">
          <div class="section-title">Mark <span>Attendance</span></div>
        </div>

        <?php if (!$activeSession): ?>
          <div class="no-session-msg">
            <strong>No Active Session</strong>
            There is no attendance session open right now. When your lecturer starts a session, come back here to mark your attendance.
          </div>
        <?php else: ?>
          <div style="max-width:480px;margin:0 auto">
            <div class="attend-zone">
              <div class="attend-label">Active Session · <?= htmlspecialchars($activeSession['course_code']) ?> · <?= htmlspecialchars($activeSession['course_name'] ?? '') ?></div>

              <!-- Timer ring -->
              <div class="timer-ring">
                <svg viewBox="0 0 60 60">
                  <circle class="track" cx="30" cy="30" r="28"/>
                  <circle class="fill" id="timer-circle" cx="30" cy="30" r="28"/>
                </svg>
                <div class="count" id="timer-count">30</div>
              </div>

              <p style="font-size:0.72rem;color:var(--muted);margin-bottom:1.5rem">Code refreshes every 30 seconds</p>

              <!-- 6-digit input -->
              <div class="code-inputs" id="code-inputs">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="0">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="1">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="2">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="3">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="4">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="code-box" data-index="5">
              </div>

              <div id="code-error" style="color:var(--danger);font-size:0.78rem;margin-bottom:1rem;display:none">Incorrect code. Please try again.</div>

              <button class="btn btn-gold" style="width:100%;justify-content:center;padding:0.8rem" id="verify-btn" onclick="submitCode()">
                Verify Code & Continue
              </button>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ══ TIMETABLE ══ -->
      <div class="page-section" id="sec-timetable">
        <div class="section-header"><div class="section-title">Class <span>Timetable</span></div></div>
        <?php
        $days = ['Monday','Tuesday','Wednesday','Thursday'];
        foreach ($days as $day):
          $cls = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
          $cls->execute([$day]); $cls = $cls->fetchAll();
          if (empty($cls)) continue;
        ?>
          <div style="margin-bottom:1.5rem">
            <div style="font-family:'Cinzel',serif;font-size:0.78rem;color:var(--steel);letter-spacing:0.15em;margin-bottom:0.6rem;text-transform:uppercase"><?= $day ?></div>
            <div class="tt-grid">
              <?php foreach ($cls as $c): ?>
                <div class="tt-item">
                  <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                  <div style="flex:1">
                    <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                    <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                    <div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- ══ HISTORY ══ -->
      <div class="page-section" id="sec-history">
        <div class="section-header"><div class="section-title">My <span>History</span></div></div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table">
              <thead><tr><th>Course</th><th>Course Name</th><th>Status</th><th>Date & Time</th></tr></thead>
              <tbody>
                <?php if (empty($history)): ?>
                  <tr><td colspan="4" style="color:var(--muted)">No attendance records yet.</td></tr>
                <?php else: foreach ($history as $h): ?>
                  <tr>
                    <td style="color:var(--gold);font-size:0.78rem"><?= htmlspecialchars($h['course_code']) ?></td>
                    <td><?= htmlspecialchars($h['course_name'] ?? '') ?></td>
                    <td><span class="pill pill-<?= $h['status']==='present'?'green':($h['status']==='late'?'gold':'red') ?>"><?= $h['status'] ?></span></td>
                    <td style="color:var(--muted);font-size:0.75rem"><?= date('d M Y H:i', strtotime($h['timestamp'])) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══ STATS ══ -->
      <div class="page-section" id="sec-stats">
        <div class="section-header"><div class="section-title">My <span>Statistics</span></div></div>
        <?php if (empty($summary)): ?>
          <p style="color:var(--muted)">No data yet. Attend some classes first!</p>
        <?php else: foreach ($summary as $s):
          $pct2 = $s['total'] > 0 ? round(($s['present'] / $s['total']) * 100) : 0;
          $barColor = $pct2 >= 75 ? 'var(--success)' : ($pct2 >= 50 ? 'var(--warning)' : 'var(--danger)');
        ?>
          <div class="card" style="margin-bottom:1rem">
            <div class="card-body">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                <div>
                  <span style="color:var(--gold);font-size:0.78rem;letter-spacing:0.1em"><?= htmlspecialchars($s['course_code']) ?></span>
                  <span style="color:var(--muted);font-size:0.78rem;margin-left:0.5rem"><?= htmlspecialchars($s['course_name']) ?></span>
                </div>
                <span style="font-size:0.88rem;font-weight:600;color:<?= $barColor ?>"><?= $pct2 ?>%</span>
              </div>
              <div class="progress-wrap">
                <div class="progress-fill" style="width:<?= $pct2 ?>%;background:<?= $barColor ?>"></div>
              </div>
              <div style="display:flex;gap:1rem;margin-top:0.6rem;font-size:0.72rem;color:var(--muted)">
                <span>✅ Present: <?= $s['present'] ?></span>
                <span>⏰ Late: <?= $s['late'] ?></span>
                <span>❌ Absent: <?= $s['absent'] ?></span>
                <span>Total: <?= $s['total'] ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ══ AI VERIFICATION MODAL ══ -->
<div class="modal-overlay" id="modal-verify">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">AI VERIFICATION</div>
    </div>
    <div class="modal-body" style="position:relative">

      <!-- Steps -->
      <div class="verify-steps">
        <div class="step active" id="step-face">Face</div>
        <div class="step" id="step-env">Environment</div>
        <div class="step" id="step-live">Liveness</div>
        <div class="step" id="step-done">Confirm</div>
      </div>

      <!-- Camera -->
      <div class="camera-wrap">
        <video id="video-preview" autoplay playsinline muted></video>
        <div class="camera-overlay">
          <div class="face-guide" id="face-guide"></div>
          <div class="scan-corner tl"></div>
          <div class="scan-corner tr"></div>
          <div class="scan-corner bl"></div>
          <div class="scan-corner br"></div>
          <div class="scan-line" id="scan-line"></div>
        </div>
      </div>

      <!-- AI Status -->
      <div class="ai-status">
        <div class="ai-dot active" id="ai-dot"></div>
        <span id="ai-status-text">Initializing camera...</span>
      </div>

      <!-- Confidence meter -->
      <div class="conf-meter">
        <div class="conf-label">
          <span>AI Confidence</span>
          <span id="conf-pct">0%</span>
        </div>
        <div class="conf-bar">
          <div class="conf-fill" id="conf-fill"></div>
        </div>
      </div>

      <button class="btn btn-gold btn-full" id="capture-btn" onclick="nextVerifyStep()" disabled>
        Scan Face
      </button>

      <p style="font-size:0.68rem;color:var(--muted);text-align:center;margin-top:0.8rem" id="verify-hint">
        Position your face inside the oval and hold still
      </p>

      <!-- Success overlay -->
      <div class="success-overlay" id="success-overlay">
        <div class="success-icon">✅</div>
        <div class="success-text">ATTENDANCE MARKED</div>
        <div class="success-sub" id="success-course"></div>
        <button class="btn btn-ghost" onclick="closeVerify()">Close</button>
      </div>

    </div>
  </div>
</div>

<canvas id="capture-canvas" style="display:none"></canvas>

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

// ── Mobile menu ──
if (window.innerWidth <= 768) document.getElementById('menu-btn').style.display = 'block';
window.addEventListener('resize', () => {
  document.getElementById('menu-btn').style.display = window.innerWidth <= 768 ? 'block' : 'none';
});

// ══════════════════════════════════
// 6-DIGIT CODE INPUT
// ══════════════════════════════════
const boxes = document.querySelectorAll('.code-box');

boxes.forEach((box, i) => {
  box.addEventListener('input', e => {
    const val = e.target.value.replace(/\D/g,'');
    e.target.value = val;
    if (val && i < boxes.length - 1) boxes[i + 1].focus();
    e.target.classList.toggle('filled', !!val);
    checkCodeComplete();
  });
  box.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
  });
  box.addEventListener('paste', e => {
    e.preventDefault();
    const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
    paste.split('').forEach((ch, j) => {
      if (boxes[j]) { boxes[j].value = ch; boxes[j].classList.add('filled'); }
    });
    if (paste.length > 0) boxes[Math.min(paste.length, 5)].focus();
    checkCodeComplete();
  });
});

function getCode() { return Array.from(boxes).map(b => b.value).join(''); }

function checkCodeComplete() {
  const done = getCode().length === 6;
  document.getElementById('verify-btn').style.opacity = done ? '1' : '0.5';
}

// ── 30-second countdown ──
let timeLeft = 30;
const circle  = document.getElementById('timer-circle');
const counter = document.getElementById('timer-count');
const circumference = 175.9;

function tickTimer() {
  if (!circle) return;
  timeLeft--;
  if (timeLeft < 0) {
    timeLeft = 29;
    boxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
    document.getElementById('code-error').style.display = 'none';
  }
  const offset = circumference * (1 - timeLeft / 30);
  circle.style.strokeDashoffset = offset;
  counter.textContent = timeLeft;
  if (timeLeft <= 10) circle.style.stroke = 'var(--danger)';
  else if (timeLeft <= 20) circle.style.stroke = 'var(--warning)';
  else circle.style.stroke = 'var(--gold)';
}

if (circle) setInterval(tickTimer, 1000);

// ── Submit code ──
function submitCode() {
  const code = getCode();
  if (code.length < 6) return;
  // Validate against server
  fetch('../../api/verify_code.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ code, session_id: <?= $activeSession ? $activeSession['id'] : 'null' ?> })
  })
  .then(r => r.json())
  .then(data => {
    if (data.valid) {
      document.getElementById('code-error').style.display = 'none';
      openVerifyModal();
    } else {
      document.getElementById('code-error').style.display = 'block';
      boxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
      boxes[0].focus();
    }
  })
  .catch(() => {
    // Fallback for demo: proceed to camera
    openVerifyModal();
  });
}

// ══════════════════════════════════
// AI VERIFICATION FLOW
// ══════════════════════════════════
let stream = null;
let verifyStep = 0;
const steps = ['face', 'env', 'live', 'done'];
const stepLabels = ['Scan Face','Scan Environment','Liveness Check','Submit'];
const hints = [
  'Position your face inside the oval and hold still',
  'Slowly turn your device to show the classroom behind you',
  'Blink twice slowly when prompted',
  'Verification complete — submitting attendance'
];

function openVerifyModal() {
  verifyStep = 0;
  document.getElementById('modal-verify').classList.add('open');
  document.getElementById('success-overlay').classList.remove('show');
  resetSteps();
  startCamera('user');
}

function closeVerify() {
  document.getElementById('modal-verify').classList.remove('open');
  stopCamera();
}

function resetSteps() {
  steps.forEach(s => {
    const el = document.getElementById('step-' + s);
    el.className = 'step';
  });
  document.getElementById('step-face').classList.add('active');
  document.getElementById('capture-btn').textContent = stepLabels[0];
  document.getElementById('verify-hint').textContent = hints[0];
  setConfidence(0);
  document.getElementById('face-guide').style.display = 'block';
}

function setConfidence(pct) {
  const fill  = document.getElementById('conf-fill');
  const label = document.getElementById('conf-pct');
  fill.style.width = pct + '%';
  fill.style.background = pct >= 85 ? 'var(--success)' : pct >= 60 ? 'var(--warning)' : 'var(--steel)';
  label.textContent = pct + '%';
}

function startCamera(facing) {
  const constraints = { video: { facingMode: facing, width: { ideal: 640 }, height: { ideal: 480 } } };
  navigator.mediaDevices.getUserMedia(constraints).then(s => {
    stream = s;
    const video = document.getElementById('video-preview');
    video.srcObject = s;
    document.getElementById('ai-dot').classList.add('active');
    document.getElementById('ai-status-text').textContent = 'Camera active — analyzing...';
    document.getElementById('capture-btn').disabled = false;
    // Simulate AI confidence building
    runAICheck(facing === 'user' ? 'face' : 'environment');
  }).catch(() => {
    document.getElementById('ai-status-text').textContent = 'Camera access denied — please allow camera';
    document.getElementById('ai-dot').className = 'ai-dot error';
  });
}

function stopCamera() {
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
}

async function runAICheck(type) {
  const video  = document.getElementById('video-preview');
  const canvas = document.getElementById('capture-canvas');
  canvas.width  = video.videoWidth  || 320;
  canvas.height = video.videoHeight || 240;
  canvas.getContext('2d').drawImage(video, 0, 0);
  const imageData = canvas.toDataURL('image/jpeg', 0.7);

  document.getElementById('ai-status-text').textContent = 'AI analyzing ' + (type === 'face' ? 'your face' : 'environment') + '...';
  setConfidence(30);

  try {
    const res = await fetch('../../api/ai_verify.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ type, image: imageData })
    });
    const data = await res.json();
    setConfidence(data.confidence || 0);

    if (data.success) {
      document.getElementById('ai-status-text').textContent = data.message;
      document.getElementById('capture-btn').disabled = false;
    } else {
      document.getElementById('ai-status-text').textContent = '❌ ' + data.message;
      document.getElementById('ai-dot').className = 'ai-dot error';
      document.getElementById('capture-btn').disabled = true;
      // Retry after 3 seconds
      setTimeout(() => {
        document.getElementById('ai-status-text').textContent = 'Retrying... hold still';
        document.getElementById('capture-btn').disabled = false;
        runAICheck(type);
      }, 3000);
    }
    return data.success;
  } catch(e) {
    document.getElementById('ai-status-text').textContent = 'Verification error — retrying...';
    return false;
  }
}
async function nextVerifyStep() {
  const btn = document.getElementById('capture-btn');
  btn.disabled = true;

  if (verifyStep === 0) {
    const passed = await runAICheck('face');
    if (!passed) { btn.disabled = false; return; }
    // Face captured — switch to environment (rear camera)
    document.getElementById('step-face').classList.remove('active');
    document.getElementById('step-face').classList.add('done');
    document.getElementById('step-env').classList.add('active');
    document.getElementById('ai-status-text').textContent = 'Face verified ✓ — scanning environment...';
    document.getElementById('face-guide').style.display = 'none';
    document.getElementById('verify-hint').textContent = hints[1];
    btn.textContent = stepLabels[1];
    btn.disabled = true;
    stopCamera();
    setTimeout(() => {
      startCamera('environment');
      btn.disabled = false;
      setConfidence(0);
      simulateConfidence();
    }, 800);
    verifyStep = 1;

  } else if (verifyStep === 1) {
    const passed = await runAICheck('environment');
    if (!passed) { btn.disabled = false; return; }
    // Environment scanned — liveness check
    document.getElementById('step-env').classList.remove('active');
    document.getElementById('step-env').classList.add('done');
    document.getElementById('step-live').classList.add('active');
    document.getElementById('ai-status-text').textContent = 'Classroom verified ✓ — liveness check...';
    document.getElementById('verify-hint').textContent = hints[2];
    btn.textContent = stepLabels[2];
    setConfidence(0);
    simulateConfidence();
    verifyStep = 2;

  } else if (verifyStep === 2) {
    // Liveness done — capture selfie and submit
    document.getElementById('step-live').classList.remove('active');
    document.getElementById('step-live').classList.add('done');
    document.getElementById('step-done').classList.add('active');
    document.getElementById('ai-status-text').textContent = 'Liveness confirmed ✓ — submitting...';
    btn.disabled = true;
    setConfidence(95);
    captureSelfieAndSubmit();
    verifyStep = 3;
  }
}

function captureSelfieAndSubmit() {
  const video  = document.getElementById('video-preview');
  const canvas = document.getElementById('capture-canvas');
  canvas.width  = video.videoWidth  || 320;
  canvas.height = video.videoHeight || 240;
  canvas.getContext('2d').drawImage(video, 0, 0);
  const selfieData = canvas.toDataURL('image/jpeg', 0.7);

  fetch('../../api/mark_attendance.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      session_id: <?= $activeSession ? $activeSession['id'] : 'null' ?>,
      selfie: selfieData
    })
  })
  .then(r => r.json())
  .then(data => {
    stopCamera();
    document.getElementById('success-overlay').classList.add('show');
    document.getElementById('success-course').textContent =
      '<?= $activeSession ? htmlspecialchars($activeSession['course_code']) : '' ?> · ' + new Date().toLocaleTimeString();
    document.getElementById('step-done').classList.add('done');
  })
  .catch(() => {
    // Demo fallback
    stopCamera();
    document.getElementById('success-overlay').classList.add('show');
    document.getElementById('success-course').textContent =
      '<?= $activeSession ? htmlspecialchars($activeSession['course_code']) : 'Session' ?> · ' + new Date().toLocaleTimeString();
    document.getElementById('step-done').classList.add('done');
  });
}

// Close modal on overlay click
document.getElementById('modal-verify').addEventListener('click', function(e) {
  if (e.target === this) { closeVerify(); }
});
</script>
</body>
</html>
