<?php
// pages/admin/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole('admin');

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "reset_system") {
        $pdo->exec("DELETE FROM attendance");
        $pdo->exec("DELETE FROM sessions");
        $pdo->exec("DELETE FROM announcements");
        header("Location: dashboard.php?reset=1");
        exit;
    }
}
$user = currentUser();

// Stats
$totalStudents  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$totalLecturers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='lecturer'")->fetchColumn();
$totalSessions  = $pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
$todayAttendance= $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(timestamp)=CURDATE()")->fetchColumn();

// Today's timetable
$today = date('l'); // e.g. Monday
$todayClasses = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
$todayClasses->execute([$today]);
$todayClasses = $todayClasses->fetchAll();

// Recent attendance activity
$recentActivity = $pdo->query("
    SELECT a.timestamp, u.full_name, u.index_no, s.course_code, a.status
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    JOIN sessions s ON a.session_id = s.id
    ORDER BY a.timestamp DESC LIMIT 10
")->fetchAll();

// All students
$students = $pdo->query("SELECT * FROM users WHERE role='student' ORDER BY full_name")->fetchAll();

// All sessions
$sessions = $pdo->query("
    SELECT s.*, u.full_name as lecturer_name,
           COUNT(a.id) as attendance_count
    FROM sessions s
    LEFT JOIN users u ON s.lecturer_id = u.id
    LEFT JOIN attendance a ON s.id = a.session_id
    GROUP BY s.id ORDER BY s.created_at DESC LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Boss Dashboard</title>
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

    /* ── Background ── */
    body::before {
      content: '';
      position: fixed; inset: 0; z-index: 0;
      background:
        radial-gradient(ellipse 60% 40% at 20% 0%, rgba(74,111,165,0.12) 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 90% 90%, rgba(201,168,76,0.06) 0%, transparent 60%);
      pointer-events: none;
    }

    /* ── Layout ── */
    .layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }

    /* ── Sidebar ── */
    .sidebar {
      width: var(--sidebar-w);
      background: var(--surface);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0; left: 0; bottom: 0;
      z-index: 100;
      transition: transform 0.3s ease;
    }

    .sidebar-brand {
      padding: 1.6rem 1.4rem 1.2rem;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 0.8rem;
    }

    .sidebar-brand svg { width: 32px; height: 32px; flex-shrink: 0; }

    .sidebar-brand-text .name {
      font-family: 'Cinzel', serif;
      font-size: 1rem;
      font-weight: 700;
      color: var(--gold);
      letter-spacing: 0.12em;
    }

    .sidebar-brand-text .role {
      font-size: 0.62rem;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }

    .nav-section {
      font-size: 0.6rem;
      letter-spacing: 0.2em;
      text-transform: uppercase;
      color: var(--muted);
      padding: 0.8rem 1.4rem 0.4rem;
    }

    .nav-item {
      display: flex; align-items: center; gap: 0.75rem;
      padding: 0.65rem 1.4rem;
      color: var(--muted);
      text-decoration: none;
      font-size: 0.85rem;
      cursor: pointer;
      border-left: 2px solid transparent;
      transition: all 0.2s;
    }

    .nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
    .nav-item.active { color: var(--gold); border-left-color: var(--gold); background: rgba(201,168,76,0.06); }
    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }

    .sidebar-footer {
      padding: 1rem 1.4rem;
      border-top: 1px solid var(--border);
      font-size: 0.75rem;
      color: var(--muted);
    }

    .sidebar-footer a { color: var(--danger); text-decoration: none; font-size: 0.8rem; }

    /* ── Main ── */
    .main {
      margin-left: var(--sidebar-w);
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ── Topbar ── */
    .topbar {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 0.9rem 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }

    .topbar-title { font-family: 'Cinzel', serif; font-size: 0.9rem; color: var(--gold); letter-spacing: 0.1em; }
    .topbar-right { display: flex; align-items: center; gap: 1rem; }

    .badge-admin {
      font-size: 0.62rem;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      background: rgba(201,168,76,0.12);
      border: 1px solid var(--gold-dim);
      color: var(--gold);
      padding: 0.25rem 0.7rem;
      border-radius: 2px;
    }

    /* ── Content ── */
    .content { padding: 2rem; flex: 1; }

    /* ── Page sections ── */
    .page-section { display: none; }
    .page-section.active { display: block; animation: fadeIn 0.3s ease; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    /* ── Section header ── */
    .section-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 1.8rem; flex-wrap: wrap; gap: 1rem;
    }

    .section-title {
      font-family: 'Cinzel', serif;
      font-size: 1.1rem;
      color: var(--text);
      letter-spacing: 0.08em;
    }

    .section-title span { color: var(--gold); }

    /* ── Stats Grid ── */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 2px;
      padding: 1.4rem 1.6rem;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 2px;
    }

    .stat-card.gold::before  { background: linear-gradient(90deg, transparent, var(--gold), transparent); }
    .stat-card.steel::before { background: linear-gradient(90deg, transparent, var(--steel), transparent); }
    .stat-card.green::before { background: linear-gradient(90deg, transparent, var(--success), transparent); }
    .stat-card.red::before   { background: linear-gradient(90deg, transparent, var(--danger), transparent); }

    .stat-label { font-size: 0.65rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.5rem; }
    .stat-value { font-size: 2rem; font-weight: 600; color: var(--text); line-height: 1; }
    .stat-sub   { font-size: 0.72rem; color: var(--muted); margin-top: 0.4rem; }

    /* ── Two col ── */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

    /* ── Card ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 2px;
    }

    .card-head {
      padding: 1rem 1.4rem;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }

    .card-head-title { font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); }
    .card-body { padding: 1.2rem 1.4rem; }

    /* ── Table ── */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .data-table th {
      text-align: left; font-size: 0.62rem; letter-spacing: 0.15em;
      text-transform: uppercase; color: var(--muted);
      padding: 0.6rem 0.8rem; border-bottom: 1px solid var(--border);
    }
    .data-table td { padding: 0.65rem 0.8rem; border-bottom: 1px solid rgba(30,42,53,0.5); color: var(--text); }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(255,255,255,0.02); }

    /* ── Status pills ── */
    .pill {
      display: inline-block;
      font-size: 0.62rem; letter-spacing: 0.12em;
      text-transform: uppercase;
      padding: 0.2rem 0.6rem;
      border-radius: 2px;
    }
    .pill-green  { background: rgba(76,175,130,0.12); color: var(--success); border: 1px solid rgba(76,175,130,0.3); }
    .pill-red    { background: rgba(224,92,92,0.12);  color: var(--danger);  border: 1px solid rgba(224,92,92,0.3); }
    .pill-gold   { background: rgba(201,168,76,0.12); color: var(--gold);    border: 1px solid rgba(201,168,76,0.3); }
    .pill-steel  { background: rgba(74,111,165,0.12); color: var(--steel);   border: 1px solid rgba(74,111,165,0.3); }

    /* ── Timetable grid ── */
    .tt-grid { display: flex; flex-direction: column; gap: 0.5rem; }
    .tt-item {
      display: flex; align-items: center; gap: 1rem;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-left: 3px solid var(--steel);
      padding: 0.7rem 1rem;
      border-radius: 2px;
    }
    .tt-time { font-size: 0.75rem; color: var(--gold); min-width: 100px; font-weight: 500; }
    .tt-course { flex: 1; }
    .tt-course-code { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.1em; }
    .tt-course-name { font-size: 0.85rem; color: var(--text); }
    .tt-room { font-size: 0.72rem; color: var(--muted); }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 0.4rem;
      padding: 0.5rem 1rem;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.78rem;
      letter-spacing: 0.08em;
      border: none; border-radius: 2px;
      cursor: pointer; text-decoration: none;
      transition: opacity 0.2s, transform 0.15s;
    }
    .btn:hover { opacity: 0.85; transform: translateY(-1px); }
    .btn-gold  { background: linear-gradient(135deg, var(--gold-dim), var(--gold)); color: #060910; font-weight: 600; }
    .btn-steel { background: var(--steel-dim); color: var(--steel); border: 1px solid var(--steel-dim); }
    .btn-danger{ background: rgba(224,92,92,0.15); color: var(--danger); border: 1px solid rgba(224,92,92,0.3); }
    .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.72rem; }

    /* ── Search/Filter bar ── */
    .filter-bar {
      display: flex; gap: 0.8rem; margin-bottom: 1.2rem; flex-wrap: wrap;
    }
    .filter-bar input, .filter-bar select {
      background: var(--surface2);
      border: 1px solid var(--border);
      color: var(--text);
      padding: 0.5rem 0.9rem;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.83rem;
      border-radius: 2px;
      outline: none;
      flex: 1; min-width: 160px;
    }
    .filter-bar input:focus, .filter-bar select:focus { border-color: var(--steel); }
    .filter-bar input::placeholder { color: var(--muted); }

    /* ── Modal ── */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 200;
      background: rgba(6,9,16,0.85);
      display: none; align-items: center; justify-content: center;
      padding: 1rem;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 2px;
      width: 100%; max-width: 480px;
      max-height: 90vh;
      overflow-y: auto;
      animation: fadeIn 0.25s ease;
    }
    .modal-head {
      padding: 1.2rem 1.6rem;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .modal-title { font-family: 'Cinzel', serif; font-size: 0.9rem; color: var(--gold); letter-spacing: 0.1em; }
    .modal-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 1.2rem; line-height: 1; }
    .modal-body { padding: 1.6rem; }

    .form-field { margin-bottom: 1.1rem; }
    .form-field label { display: block; font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.4rem; }
    .form-field input, .form-field select {
      width: 100%;
      background: var(--bg);
      border: 1px solid var(--border);
      color: var(--text);
      padding: 0.65rem 0.9rem;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.88rem;
      border-radius: 2px; outline: none;
      transition: border-color 0.2s;
    }
    .form-field input:focus, .form-field select:focus { border-color: var(--steel); }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

    /* ── Override banner ── */
    .override-banner {
      background: rgba(224,92,92,0.08);
      border: 1px solid rgba(224,92,92,0.2);
      border-left: 3px solid var(--danger);
      padding: 0.8rem 1.2rem;
      border-radius: 2px;
      margin-bottom: 1.5rem;
      font-size: 0.8rem;
      color: var(--muted);
    }
    .override-banner strong { color: var(--danger); }

    /* ── Audit log ── */
    .audit-item {
      display: flex; gap: 1rem; align-items: flex-start;
      padding: 0.7rem 0;
      border-bottom: 1px solid rgba(30,42,53,0.5);
      font-size: 0.8rem;
    }
    .audit-item:last-child { border-bottom: none; }
    .audit-time { color: var(--muted); min-width: 130px; font-size: 0.72rem; }
    .audit-text { color: var(--text); }
    .audit-text em { color: var(--gold); font-style: normal; }

    /* ── Responsive ── */
    @media (max-width: 900px) {
      .two-col { grid-template-columns: 1fr; }
    }

    @media (min-width: 769px) { #menu-btn { display: none; } }
    @media (min-width: 769px) { #menu-btn { display: none; } }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; }
      .content { padding: 1rem; }
      .topbar { padding: 0.8rem 1rem; }
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .two-col { grid-template-columns: 1fr; }
      .data-table { font-size: .75rem; }
      .data-table th, .data-table td { padding: .5rem; }
      .form-row { grid-template-columns: 1fr; }
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
        <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
        <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
        <line x1="26" y1="27" x2="26" y2="31" stroke="#c9a84c" stroke-width="1.5"/>
      </svg>
      <div class="sidebar-brand-text">
        <div class="name">CITADEL</div>
        <div class="role">Command Center</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section">Overview</div>
      <a class="nav-item active" onclick="showSection('overview')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        Overview
      </a>
      <a class="nav-item" onclick="showSection('timetable')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Timetable
      </a>

      <div class="nav-section">Management</div>
      <a class="nav-item" onclick="showSection('students')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Students
      </a>
      <a class="nav-item" onclick="showSection('sessions')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Sessions
      </a>
      <a class="nav-item" onclick="showSection('attendance')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Attendance
      </a>
      <a class="nav-item" onclick="showSection('override')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Override
      </a>

      <div class="nav-section">System</div>
      <a class="nav-item" onclick="showSection('audit')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Audit Log
      </a>
      <a class="nav-item" onclick="showSection('devices')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
        Device Control
      </a>
    </nav>

    <div class="sidebar-footer">
      Logged in as <strong style="color:var(--text)"><?= htmlspecialchars($user['full_name'] ?? 'Admin') ?></strong><br>
      <a href="../../change_password.php" style="color:var(--muted);font-size:.78rem;display:block;margin-bottom:.4rem">Change Password</a><a href="../../logout.php">Sign out</a>
    </div>
  </aside>

  <!-- ── Main ── -->
  <div class="main">

    <!-- Topbar -->
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:1rem;">
        <button onclick="document.getElementById('sidebar').classList.toggle('open')" style="background:none;border:none;color:var(--muted);cursor:pointer;" id="menu-btn">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title" id="page-title">OVERVIEW</div>
      </div>
      <div class="topbar-right">
        <span style="font-size:0.75rem;color:var(--muted)"><?= date('l, d M Y') ?></span>
        <span class="badge-admin">Boss</span>
      </div>
    </div>

    <!-- Content -->
    <div class="content">

      <!-- ══ OVERVIEW ══ -->
      <div class="page-section active" id="sec-overview">
        <div class="stats-grid">
          <div class="stat-card gold">
            <div class="stat-label">Total Students</div>
            <div class="stat-value"><?= $totalStudents ?></div>
            <div class="stat-sub">HND Computer Science Yr 2</div>
          </div>
          <div class="stat-card steel">
            <div class="stat-label">Lecturers</div>
            <div class="stat-value"><?= $totalLecturers ?></div>
            <div class="stat-sub">Active faculty</div>
          </div>
          <div class="stat-card green">
            <div class="stat-label">Total Sessions</div>
            <div class="stat-value"><?= $totalSessions ?></div>
            <div class="stat-sub">All time</div>
          </div>
          <div class="stat-card red">
            <div class="stat-label">Today's Records</div>
            <div class="stat-value"><?= $todayAttendance ?></div>
            <div class="stat-sub"><?= date('d M Y') ?></div>
          </div>
        </div>

        <div class="two-col">
          <!-- Today's timetable -->
          <div class="card">
            <div class="card-head">
              <div class="card-head-title">Today — <?= $today ?></div>
              <span class="pill pill-steel"><?= count($todayClasses) ?> classes</span>
            </div>
            <div class="card-body">
              <?php if (empty($todayClasses)): ?>
                <p style="color:var(--muted);font-size:0.83rem;">No classes scheduled today.</p>
              <?php else: ?>
                <div class="tt-grid">
                  <?php foreach ($todayClasses as $c): ?>
                    <div class="tt-item">
                      <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                      <div class="tt-course">
                        <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                        <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                        <div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Recent activity -->
          <div class="card">
            <div class="card-head">
              <div class="card-head-title">Recent Activity</div>
            </div>
            <div class="card-body" style="padding:0">
              <table class="data-table">
                <thead><tr><th>Student</th><th>Course</th><th>Status</th><th>Time</th></tr></thead>
                <tbody>
                  <?php if (empty($recentActivity)): ?>
                    <tr><td colspan="4" style="color:var(--muted)">No activity yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($recentActivity as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r['full_name']) ?><br><small style="color:var(--muted)"><?= $r['index_no'] ?></small></td>
                        <td><?= htmlspecialchars($r['course_code']) ?></td>
                        <td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':'red') ?>"><?= $r['status'] ?></span></td>
                        <td style="color:var(--muted);font-size:0.75rem"><?= date('H:i', strtotime($r['timestamp'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ TIMETABLE ══ -->
      <div class="page-section" id="sec-timetable">
        <div class="section-header">
          <div class="section-title">Class <span>Timetable</span></div>
        </div>
        <?php
        $days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        foreach ($days as $day):
          $classes = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
          $classes->execute([$day]);
          $classes = $classes->fetchAll();
          if (empty($classes)) continue;
        ?>
          <div style="margin-bottom:1.5rem">
            <div style="font-family:'Cinzel',serif;font-size:0.8rem;color:var(--gold);letter-spacing:0.15em;margin-bottom:0.6rem;text-transform:uppercase"><?= $day ?></div>
            <div class="tt-grid">
              <?php foreach ($classes as $c): ?>
                <div class="tt-item">
                  <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                  <div class="tt-course" style="flex:1">
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

      <!-- ══ STUDENTS ══ -->
      <div class="page-section" id="sec-students">
        <div class="section-header">
          <div class="section-title">Student <span>Registry</span></div>
          <button class="btn btn-gold" onclick="openModal('modal-add-student')">+ Add Student</button>
        </div>
        <div class="filter-bar">
          <input type="text" id="student-search" placeholder="Search name or index number..." oninput="filterStudents()">
        </div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table" id="student-table">
              <thead><tr><th>#</th><th>Index No.</th><th>Full Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($students as $i => $s): ?>
                  <tr data-name="<?= strtolower($s['full_name']) ?>" data-index="<?= $s['index_no'] ?>">
                    <td style="color:var(--muted)"><?= $i+1 ?></td>
                    <td style="color:var(--gold);font-size:0.8rem"><?= $s['index_no'] ?></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem"><?= $s['email'] ?></td>
                    <td><span class="pill pill-<?= $s['role']==='rep'?'gold':'steel' ?>"><?= $s['role'] ?></span></td>
                    <td>
                      <button class="btn btn-ghost btn-sm" onclick="editStudent(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>', '<?= $s['index_no'] ?>', '<?= $s['email'] ?>', '<?= $s['role'] ?>')">Edit</button>
                      <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $s['id'] ?>, 'student')">Remove</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══ SESSIONS ══ -->
      <div class="page-section" id="sec-sessions">
        <div class="section-header">
          <div class="section-title">Attendance <span>Sessions</span></div>
        </div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table">
              <thead><tr><th>Course</th><th>Lecturer</th><th>Started</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if (empty($sessions)): ?>
                  <tr><td colspan="6" style="color:var(--muted)">No sessions yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($sessions as $s): ?>
                    <tr>
                      <td>
                        <span style="color:var(--gold);font-size:0.78rem"><?= htmlspecialchars($s['course_code']) ?></span>
                      </td>
                      <td><?= htmlspecialchars($s['lecturer_name']) ?></td>
                      <td style="color:var(--muted);font-size:0.78rem"><?= date('d M, H:i', strtotime($s['start_time'])) ?></td>
                      <td><span class="pill pill-<?= $s['active_status']?'green':'red' ?>"><?= $s['active_status']?'Active':'Closed' ?></span></td>
                      <td><?= $s['attendance_count'] ?> students</td>
                      <td>
                        <?php if ($s['active_status']): ?>
                          <button class="btn btn-danger btn-sm" onclick="closeSession(<?= $s['id'] ?>)">Close</button>
                        <?php endif; ?>
                        <button class="btn btn-ghost btn-sm">View</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══ ATTENDANCE ══ -->
      <div class="page-section" id="sec-attendance">
        <div class="section-header">
          <div class="section-title">Attendance <span>Records</span></div>
          <button class="btn btn-ghost" onclick="exportCSV()">Export CSV</button>
        </div>
        <div class="filter-bar">
          <input type="text" placeholder="Search student...">
          <select>
            <option value="">All Courses</option>
            <option>CSH221</option><option>CSH201</option><option>CSH245</option>
            <option>CSH237</option><option>CSH261</option><option>CSH231</option><option>CSH251</option>
          </select>
          <select>
            <option value="">All Status</option>
            <option>present</option><option>absent</option><option>late</option>
          </select>
        </div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table">
              <thead><tr><th>Student</th><th>Index No.</th><th>Course</th><th>Status</th><th>Timestamp</th><th>Selfie</th></tr></thead>
              <tbody>
                <?php
                $records = $pdo->query("
                    SELECT a.*, u.full_name, u.index_no, s.course_code
                    FROM attendance a
                    JOIN users u ON a.student_id=u.id
                    JOIN sessions s ON a.session_id=s.id
                    ORDER BY a.timestamp DESC LIMIT 50
                ")->fetchAll();
                if (empty($records)):
                ?>
                  <tr><td colspan="6" style="color:var(--muted)">No attendance records yet.</td></tr>
                <?php else: foreach ($records as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['full_name']) ?></td>
                    <td style="color:var(--gold);font-size:0.78rem"><?= $r['index_no'] ?></td>
                    <td><?= $r['course_code'] ?></td>
                    <td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':'red') ?>"><?= $r['status'] ?></span></td>
                    <td style="color:var(--muted);font-size:0.75rem"><?= date('d M Y H:i', strtotime($r['timestamp'])) ?></td>
                    <td><?= $r['selfie_url'] ? '<span class="pill pill-green">✓</span>' : '<span class="pill pill-red">—</span>' ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══ OVERRIDE ══ -->
      <div class="page-section" id="sec-override">
        <div class="section-header">
          <div class="section-title">Attendance <span>Override</span></div>
        </div>
        <div class="override-banner">
          <strong>⚠ Boss Override Zone.</strong> Changes made here are logged and irreversible without a second override. Use with caution.
        <div class="card" style="margin-bottom:1.5rem;border-color:rgba(224,92,92,.3)"><div class="card-head" style="border-color:rgba(224,92,92,.2)"><div class="card-head-title" style="color:var(--danger)">🔴 SYSTEM RESET</div></div><div class="card-body"><p style="font-size:.83rem;color:var(--muted);margin-bottom:1.2rem">Clears ALL attendance records and sessions. Students and lecturers remain. Use before the semester starts fresh.</p><form method="POST" onsubmit="return confirm('RESET ALL ATTENDANCE DATA? This cannot be undone!')"><input type="hidden" name="action" value="reset_system"><button type="submit" class="btn" style="background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3);padding:.7rem 1.5rem">🗑 Reset All Attendance & Sessions</button></form></div></div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-head-title">Manual Attendance Adjustment</div></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-field">
                <label>Student Index No.</label>
                <input type="text" placeholder="e.g. 52430540001">
              </div>
              <div class="form-field">
                <label>Course Code</label>
                <select>
                  <option>CSH221</option><option>CSH201</option><option>CSH245</option>
                  <option>CSH237</option><option>CSH261</option><option>CSH231</option><option>CSH251</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-field">
                <label>Set Status</label>
                <select>
                  <option value="present">Present</option>
                  <option value="absent">Absent</option>
                  <option value="late">Late</option>
                </select>
              </div>
              <div class="form-field">
                <label>Reason / Note</label>
                <input type="text" placeholder="Reason for override">
              </div>
            </div>
            <button class="btn btn-gold">Apply Override</button>
          </div>
        </div>

        <div class="card" style="margin-top:1.5rem">
          <div class="card-head"><div class="card-head-title">Device Fingerprint Ban</div></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-field">
                <label>Student Index No.</label>
                <input type="text" placeholder="e.g. 52430540001">
              </div>
              <div class="form-field" style="display:flex;align-items:flex-end;gap:0.6rem">
                <button class="btn btn-danger" style="width:100%">Ban Device</button>
                <button class="btn btn-ghost" style="width:100%">Unban</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ AUDIT LOG ══ -->
      <div class="page-section" id="sec-audit">
        <div class="section-header">
          <div class="section-title">System <span>Audit Log</span></div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="audit-item"><div class="audit-time"><?= date('d M Y H:i') ?></div><div class="audit-text">System initialized. Database seeded with <em>71 students</em> and <em>13 timetable entries</em>.</div></div>
            <div class="audit-item"><div class="audit-time">—</div><div class="audit-text" style="color:var(--muted)">Audit entries will appear here as actions are performed.</div></div>
          </div>
        </div>
      </div>

      <!-- ══ DEVICES ══ -->
      <div class="page-section" id="sec-devices">
        <div class="section-header">
          <div class="section-title">Device <span>Control</span></div>
        </div>
        <div class="card">
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table">
              <thead><tr><th>Student</th><th>Index No.</th><th>Fingerprint</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php
                $devs = $pdo->query("SELECT id, full_name, index_no, device_fingerprint FROM users WHERE role='student' ORDER BY full_name")->fetchAll();
                foreach ($devs as $d): ?>
                <tr>
                  <td><?= htmlspecialchars($d['full_name']) ?></td>
                  <td style="color:var(--gold);font-size:0.78rem"><?= $d['index_no'] ?></td>
                  <td style="color:var(--muted);font-size:0.72rem;max-width:160px;overflow:hidden;text-overflow:ellipsis"><?= $d['device_fingerprint'] ?: '— not registered —' ?></td>
                  <td><span class="pill pill-green">Active</span></td>
                  <td><button class="btn btn-danger btn-sm">Ban</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ══ Add Student Modal ══ -->
<div class="modal-overlay" id="modal-add-student">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">ADD STUDENT</div>
      <button class="modal-close" onclick="closeModal('modal-add-student')">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="../../api/add_student.php">
        <div class="form-row">
          <div class="form-field">
            <label>Full Name</label>
            <input type="text" name="full_name" required placeholder="Surname, Firstname">
          </div>
          <div class="form-field">
            <label>Index Number</label>
            <input type="text" name="index_no" required placeholder="52430540000">
          </div>
        </div>
        <div class="form-field">
          <label>Email</label>
          <input type="email" name="email" placeholder="optional">
        </div>
        <div class="form-field">
          <label>Role</label>
          <select name="role">
            <option value="student">Student</option>
            <option value="rep">Course Rep</option>
          </select>
        </div>
        <button type="submit" class="btn btn-gold" style="width:100%">Add to Citadel</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ Edit Student Modal ══ -->
<div class="modal-overlay" id="modal-edit-student">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">EDIT STUDENT</div>
      <button class="modal-close" onclick="closeModal('modal-edit-student')">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="../../api/edit_student.php">
        <input type="hidden" name="id" id="edit-id">
        <div class="form-row">
          <div class="form-field">
            <label>Full Name</label>
            <input type="text" name="full_name" id="edit-name" required>
          </div>
          <div class="form-field">
            <label>Index Number</label>
            <input type="text" name="index_no" id="edit-index" required>
          </div>
        </div>
        <div class="form-field">
          <label>Email</label>
          <input type="email" name="email" id="edit-email">
        </div>
        <div class="form-field">
          <label>Role</label>
          <select name="role" id="edit-role">
            <option value="student">Student</option>
            <option value="rep">Course Rep</option>
          </select>
        </div>
        <button type="submit" class="btn btn-gold" style="width:100%">Save Changes</button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Navigation ──
function showSection(name) {
  document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  document.getElementById('page-title').textContent = name.toUpperCase();
  event.currentTarget.classList.add('active');
  document.getElementById('sidebar').classList.remove('open');
}

// ── Modals ──
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// ── Edit Student ──
function editStudent(id, name, index, email, role) {
  document.getElementById('edit-id').value    = id;
  document.getElementById('edit-name').value  = name;
  document.getElementById('edit-index').value = index;
  document.getElementById('edit-email').value = email;
  document.getElementById('edit-role').value  = role;
  openModal('modal-edit-student');
}

// ── Delete confirm ──
function confirmDelete(id, type) {
  if (confirm('Are you sure you want to remove this ' + type + '? This cannot be undone.')) {
    window.location.href = '../../api/delete_user.php?id=' + id;
  }
}

// ── Search ──
function filterStudents() {
  const q = document.getElementById('student-search').value.toLowerCase();
  document.querySelectorAll('#student-table tbody tr').forEach(tr => {
    const name  = tr.dataset.name  || '';
    const index = tr.dataset.index || '';
    tr.style.display = (name.includes(q) || index.includes(q)) ? '' : 'none';
  });
}

// ── Close Session ──
function closeSession(id) {
  if (confirm('Close this session? Students will no longer be able to mark attendance.')) {
    fetch('../../api/close_session.php?id=' + id).then(() => location.reload());
  }
}

// ── Export CSV (placeholder) ──
function exportCSV() { alert('Export feature coming soon.'); }

// ── Mobile menu ──
const menuBtn = document.getElementById('menu-btn');
if (window.innerWidth <= 768) menuBtn.style.display = 'block';
window.addEventListener('resize', () => {
  menuBtn.style.display = window.innerWidth <= 768 ? 'block' : 'none';
});
</script>
</body>
</html>
