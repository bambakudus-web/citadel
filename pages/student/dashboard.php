<?php
// pages/student/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole('student', 'rep');

$user   = currentUser();
$userId = $user['id'];

// Stats
$totalSessions  = $pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
$myAttendance   = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id=? AND status IN ('present','late')");
$myAttendance->execute([$userId]); $myAttendance = $myAttendance->fetchColumn();
$attendanceRate = $totalSessions > 0 ? round(($myAttendance / $totalSessions) * 100) : 0;

// Today's classes
$today     = date('l');
$todayStmt = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? ORDER BY t.start_time");
$todayStmt->execute([$today]); $todayClasses = $todayStmt->fetchAll();

// Active session
$activeSession = $pdo->query("SELECT * FROM sessions WHERE active_status=1 ORDER BY start_time DESC LIMIT 1")->fetch();

// Check if student already submitted for active session
$myPending = null; $myRecord = null;
if ($activeSession) {
    $chk = $pdo->prepare("SELECT * FROM attendance WHERE session_id=? AND student_id=?");
    $chk->execute([$activeSession['id'], $userId]);
    $myRecord  = $chk->fetch();
    if ($myRecord && $myRecord['status'] === 'pending') $myPending = $myRecord;
}

// Recent attendance
$recentAtt = $pdo->prepare("SELECT a.*, s.course_code, s.course_name FROM attendance a JOIN sessions s ON a.session_id=s.id WHERE a.student_id=? ORDER BY a.timestamp DESC LIMIT 10");
$recentAtt->execute([$userId]); $recentAtt = $recentAtt->fetchAll();

// Per-course stats
$courseStats = $pdo->prepare("
    SELECT s.course_code, s.course_name,
           COUNT(*) as total,
           SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) as attended
    FROM attendance a JOIN sessions s ON a.session_id=s.id
    WHERE a.student_id=? GROUP BY s.course_code, s.course_name
");
$courseStats->execute([$userId]); $courseStats = $courseStats->fetchAll();

// Full timetable
$fullTT = $pdo->query("SELECT t.*, u.full_name as lecturer_name FROM timetable t LEFT JOIN users u ON t.lecturer_id=u.id ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday'), start_time")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Citadel — Student Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050;--sidebar-w:240px}
    html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
    body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 60% 40% at 80% 0%,rgba(74,111,165,.12) 0%,transparent 60%);pointer-events:none}
    .layout{display:flex;min-height:100vh;position:relative;z-index:1}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s}
    .sidebar-brand{padding:1.6rem 1.4rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.8rem}
    .sidebar-brand svg{width:32px;height:32px;flex-shrink:0}
    .brand-name{font-family:'Cinzel',serif;font-size:1rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
    .brand-role{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--steel)}
    .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto}
    .nav-section{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.4rem .4rem}
    .nav-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.4rem;color:var(--muted);text-decoration:none;font-size:.85rem;cursor:pointer;border-left:2px solid transparent;transition:all .2s}
    .nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
    .nav-item.active{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.06)}
    .nav-item svg{width:16px;height:16px;flex-shrink:0}
    .sidebar-user{padding:1rem 1.4rem;border-top:1px solid var(--border)}
    .u-name{font-size:.82rem;color:var(--text);font-weight:500}
    .u-index{font-size:.68rem;color:var(--muted);margin-bottom:.5rem}
    .sidebar-user a{color:var(--danger);text-decoration:none;font-size:.78rem}
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
    .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
    .topbar-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--gold);letter-spacing:.1em}
    .badge{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:rgba(74,111,165,.12);border:1px solid var(--steel-dim);color:var(--steel);padding:.25rem .7rem;border-radius:2px}
    .content{padding:2rem;flex:1}
    .page-section{display:none}
    .page-section.active{display:block;animation:fadeIn .3s ease}
    @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
    .section-title{font-family:'Cinzel',serif;font-size:1.1rem;color:var(--text);letter-spacing:.08em;margin-bottom:1.8rem}
    .section-title span{color:var(--gold)}
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:2rem}
    .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.3rem 1.5rem;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
    .stat-card.gold::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}
    .stat-card.steel::before{background:linear-gradient(90deg,transparent,var(--steel),transparent)}
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
    .pill{display:inline-block;font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;padding:.2rem .6rem;border-radius:2px}
    .pill-green{background:rgba(76,175,130,.12);color:var(--success);border:1px solid rgba(76,175,130,.3)}
    .pill-red{background:rgba(224,92,92,.12);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
    .pill-gold{background:rgba(201,168,76,.12);color:var(--gold);border:1px solid rgba(201,168,76,.3)}
    .pill-steel{background:rgba(74,111,165,.12);color:var(--steel);border:1px solid rgba(74,111,165,.3)}
    .pill-pending{background:rgba(224,160,80,.12);color:var(--warning);border:1px solid rgba(224,160,80,.3)}
    .tt-grid{display:flex;flex-direction:column;gap:.5rem}
    .tt-item{display:flex;align-items:center;gap:1rem;background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--steel);padding:.7rem 1rem;border-radius:2px}
    .tt-time{font-size:.75rem;color:var(--gold);min-width:100px;font-weight:500}
    .tt-info .code{font-size:.7rem;color:var(--muted)}
    .tt-info .name{font-size:.85rem;color:var(--text)}
    .tt-info .room{font-size:.72rem;color:var(--muted)}
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;font-family:'DM Sans',sans-serif;font-size:.78rem;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s}
    .btn:hover{opacity:.85;transform:translateY(-1px)}
    .btn-gold{background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-weight:600;font-family:'Cinzel',serif;letter-spacing:.1em}
    .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}

    /* ── MARK ATTENDANCE ── */
    .attend-zone{max-width:480px;margin:0 auto}
    .session-active-card{background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--success);border-radius:2px;padding:1.2rem 1.4rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem}
    .live-dot{width:8px;height:8px;border-radius:50%;background:var(--success);animation:pulse 1s infinite;flex-shrink:0}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
    .no-session-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2rem;text-align:center}
    .code-inputs{display:flex;gap:.6rem;justify-content:center;margin:1.5rem 0}
    .code-inputs input{width:46px;height:56px;background:var(--surface2);border:1px solid var(--border);border-radius:2px;color:var(--gold);font-family:'Cinzel',serif;font-size:1.4rem;font-weight:700;text-align:center;outline:none;transition:border-color .2s,box-shadow .2s}
    .code-inputs input:focus{border-color:var(--gold);box-shadow:0 0 0 2px rgba(201,168,76,.15)}
    .code-inputs input.filled{border-color:var(--steel)}
    .timer-strip{display:flex;align-items:center;gap:.8rem;background:var(--surface2);border:1px solid var(--border);border-radius:2px;padding:.6rem 1rem;margin-bottom:1.2rem}
    .timer-bar{flex:1;height:4px;background:var(--border);border-radius:2px;overflow:hidden}
    .timer-fill{height:100%;background:var(--gold);border-radius:2px;transition:width 1s linear,background .3s}
    .timer-num{font-family:'Cinzel',serif;font-size:.85rem;color:var(--gold);min-width:28px;text-align:right}

    /* Pending status */
    .pending-card{background:var(--surface);border:1px solid rgba(224,160,80,.3);border-radius:2px;padding:2rem;text-align:center}
    .pending-icon{font-size:2.5rem;margin-bottom:1rem}
    .pending-title{font-family:'Cinzel',serif;font-size:1rem;color:var(--warning);margin-bottom:.5rem}
    .pending-sub{font-size:.82rem;color:var(--muted)}

    /* Camera */
    .camera-section{margin-top:1.2rem}
    .camera-wrap{position:relative;width:100%;max-width:320px;margin:0 auto;border-radius:2px;overflow:hidden;background:#000;aspect-ratio:4/3}
    .camera-wrap video{width:100%;height:100%;object-fit:cover}
    .face-guide{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
    .face-oval{width:140px;height:180px;border:2px solid rgba(201,168,76,.6);border-radius:50%;animation:glowPulse 2s infinite}
    @keyframes glowPulse{0%,100%{box-shadow:0 0 0 0 rgba(201,168,76,.3)}50%{box-shadow:0 0 0 8px rgba(201,168,76,.0)}}
    .selfie-preview{width:100%;max-width:320px;margin:1rem auto 0;display:block;border-radius:2px;border:1px solid var(--border)}
    canvas{display:none}
    .step-indicator{display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;font-size:.72rem;color:var(--muted)}
    .step-dot{width:8px;height:8px;border-radius:50%;background:var(--border)}
    .step-dot.active{background:var(--gold)}
    .step-dot.done{background:var(--success)}

    /* Progress bar for courses */
    .course-bar{margin-bottom:1rem}
    .course-bar-label{display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.3rem}
    .bar-track{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
    .bar-fill{height:100%;border-radius:3px;transition:width .8s ease}

    @media(max-width:900px){.two-col{grid-template-columns:1fr}}
    @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.content{padding:1.2rem}.topbar{padding:.9rem 1.2rem}.stats-grid{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg viewBox="0 0 52 52" fill="none"><polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/><polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/><rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/><circle cx="26" cy="25" r="2" fill="#c9a84c"/><line x1="26" y1="27" x2="26" y2="31" stroke="#4a6fa5" stroke-width="1.5"/></svg>
      <div><div class="brand-name">CITADEL</div><div class="brand-role">Student Portal</div></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section">Portal</div>
      <a class="nav-item active" onclick="showSection('overview',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Overview
      </a>
      <a class="nav-item" id="mark-nav" onclick="showSection('mark',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Mark Attendance<?= $activeSession ? ' 🟢' : '' ?>
      </a>
      <a class="nav-item" onclick="showSection('timetable',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Timetable
      </a>
      <a class="nav-item" onclick="showSection('history',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>My History
      </a>
      <a class="nav-item" onclick="showSection('stats',this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>My Stats
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
      <div class="u-index"><?= htmlspecialchars($user['index_no'] ?? '') ?></div>
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
        <span class="badge">Student</span>
      </div>
    </div>

    <div class="content">

      <!-- OVERVIEW -->
      <div class="page-section active" id="sec-overview">
        <div class="stats-grid">
          <div class="stat-card gold"><div class="stat-label">Attendance Rate</div><div class="stat-value"><?= $attendanceRate ?>%</div><div class="stat-sub">Overall across all courses</div></div>
          <div class="stat-card steel"><div class="stat-label">Sessions Present</div><div class="stat-value"><?= $myAttendance ?></div><div class="stat-sub">Out of <?= $totalSessions ?> total</div></div>
          <div class="stat-card green"><div class="stat-label">Today's Classes</div><div class="stat-value"><?= count($todayClasses) ?></div><div class="stat-sub"><?= $today ?></div></div>
        </div>
        <div class="two-col">
          <div class="card">
            <div class="card-head"><div class="card-head-title">Today — <?= $today ?></div></div>
            <div class="card-body">
              <?php if (empty($todayClasses)): ?><p style="color:var(--muted);font-size:.83rem">No classes today.</p>
              <?php else: ?><div class="tt-grid"><?php foreach ($todayClasses as $c): ?>
                <div class="tt-item">
                  <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                  <div class="tt-info"><div class="code"><?= htmlspecialchars($c['course_code']) ?></div><div class="name"><?= htmlspecialchars($c['course_name']) ?></div><div class="room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div></div>
                </div>
              <?php endforeach; ?></div>
              <?php if ($activeSession): ?>
                <button class="btn btn-gold" style="width:100%;justify-content:center;margin-top:1rem" onclick="showSection('mark',document.getElementById('mark-nav'))">Mark Attendance Now →</button>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><div class="card-head-title">Recent Records</div></div>
            <div class="card-body" style="padding:0">
              <table class="data-table"><thead><tr><th>Course</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                <?php if (empty($recentAtt)): ?><tr><td colspan="3" style="color:var(--muted)">No records yet.</td></tr>
                <?php else: foreach ($recentAtt as $r): ?>
                  <tr><td><?= htmlspecialchars($r['course_code']) ?></td><td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'pending':'red')) ?>"><?= $r['status'] ?></span></td><td style="color:var(--muted);font-size:.72rem"><?= date('d M',strtotime($r['timestamp'])) ?></td></tr>
                <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>

      <!-- MARK ATTENDANCE -->
      <div class="page-section" id="sec-mark">
        <div class="attend-zone">

          <?php if ($myPending): ?>
            <!-- Already submitted, waiting for approval -->
            <div class="pending-card">
              <div class="pending-icon">⏳</div>
              <div class="pending-title">Awaiting Rep Approval</div>
              <div class="pending-sub">Your selfie has been submitted for <strong><?= htmlspecialchars($activeSession['course_code'] ?? '') ?></strong>.<br>The Course Rep will review and approve shortly.</div>
              <div style="margin-top:1.2rem">
                <?php if ($myPending['selfie_url']): ?>
                  <img src="../../<?= htmlspecialchars($myPending['selfie_url']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:50%;border:2px solid var(--warning);margin:0 auto;display:block">
                <?php endif; ?>
              </div>
            </div>

          <?php elseif ($myRecord && $myRecord['status'] !== 'pending'): ?>
            <!-- Already marked -->
            <div class="pending-card" style="border-color:rgba(76,175,130,.3)">
              <div class="pending-icon"><?= $myRecord['status']==='present'?'✅':'⏰' ?></div>
              <div class="pending-title" style="color:var(--success)">Marked <?= ucfirst($myRecord['status']) ?></div>
              <div class="pending-sub">You have been marked <strong><?= $myRecord['status'] ?></strong> for <?= htmlspecialchars($activeSession['course_code'] ?? '') ?>.</div>
            </div>

          <?php elseif ($activeSession): ?>
            <!-- Active session — show code input + camera -->
            <div class="session-active-card">
              <div class="live-dot"></div>
              <div>
                <div style="font-size:.72rem;color:var(--success);letter-spacing:.15em;text-transform:uppercase">Session Active</div>
                <div style="font-size:.9rem;color:var(--text);font-weight:500"><?= htmlspecialchars($activeSession['course_code']) ?> · <?= htmlspecialchars($activeSession['course_name'] ?? '') ?></div>
              </div>
            </div>

            <!-- Step indicator -->
        <div class="step-indicator">
	  <div class="step-dot active" id="dot-code"></div>
	  <div class="step-dot" id="dot-selfie" style="margin-left:.3rem"></div>
	  <div class="step-dot" id="dot-class" style="margin-left:.3rem"></div>
	  <span id="step-label" style="margin-left:.5rem">Step 1: Enter the 6-digit code</span>
	</div>			

            <!-- Step 1: Code entry -->
            <div id="step-code-section">
              <div class="timer-strip">
                <span style="font-size:.68rem;color:var(--muted);letter-spacing:.1em">CODE EXPIRES IN</span>
                <div class="timer-bar"><div class="timer-fill" id="timer-fill"></div></div>
                <div class="timer-num" id="timer-num">30</div>
              </div>
              <div class="code-inputs">
                <?php for($i=1;$i<=6;$i++): ?>
                  <input type="text" maxlength="1" id="ci<?=$i?>" oninput="codeInput(this,<?=$i?>)" onkeydown="codeBack(event,<?=$i?>)" inputmode="numeric">
                <?php endfor; ?>
              </div>
              <button class="btn btn-gold" id="verify-code-btn" onclick="verifyCode()" style="width:100%;justify-content:center;padding:.85rem" disabled>Verify Code</button>
              <div id="code-error" style="color:var(--danger);font-size:.78rem;margin-top:.6rem;text-align:center;display:none"></div>
            </div>

            <!-- Step 2: Selfie capture (hidden initially) -->
            <div id="step-selfie-section" style="display:none">
              <div style="text-align:center;margin-bottom:1rem">
                <div style="font-size:.72rem;color:var(--gold);letter-spacing:.15em;text-transform:uppercase">Step 2: Take Your Selfie</div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.3rem">Position your face clearly in the oval</div>
              </div>
              <div class="camera-wrap">
                <video id="video-preview" autoplay playsinline muted></video>
                <div class="face-guide"><div class="face-oval"></div></div>
              </div>
              <canvas id="capture-canvas"></canvas>
              <img id="selfie-preview" class="selfie-preview" style="display:none">
              <div style="display:flex;gap:.8rem;margin-top:1rem">
                <button class="btn btn-ghost" id="retake-btn" onclick="retakeSelfie()" style="flex:1;justify-content:center;display:none">Retake</button>
                <button class="btn btn-gold" id="capture-btn" onclick="captureSelfie()" style="flex:1;justify-content:center">📸 Capture Selfie</button>
                <button class="btn btn-gold" id="submit-btn" onclick="submitAttendance()" style="flex:1;justify-content:center;display:none">Submit →</button>
              </div>
              <div id="submit-error" style="color:var(--danger);font-size:.78rem;margin-top:.6rem;text-align:center;display:none"></div>
            </div>

          <?php else: ?>
            <!-- No active session -->
            <div class="no-session-card">
              <div style="font-size:2rem;margin-bottom:1rem">🔒</div>
              <div style="font-family:'Cinzel',serif;font-size:.9rem;color:var(--muted);letter-spacing:.1em">NO ACTIVE SESSION</div>
              <div style="font-size:.82rem;color:var(--muted);margin-top:.5rem">Wait for your lecturer or course rep to start an attendance session.</div>
            </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- TIMETABLE -->
      <div class="page-section" id="sec-timetable">
        <div class="section-title">Class <span>Timetable</span></div>
        <?php
        $days = ['Monday','Tuesday','Wednesday','Thursday'];
        foreach ($days as $day):
          $cls = array_filter($fullTT, fn($c) => $c['day_of_week'] === $day);
          if (empty($cls)) continue;
        ?>
          <div style="margin-bottom:1.5rem">
            <div style="font-family:'Cinzel',serif;font-size:.78rem;color:var(--gold);letter-spacing:.15em;margin-bottom:.6rem"><?= strtoupper($day) ?></div>
            <div class="tt-grid"><?php foreach ($cls as $c): ?>
              <div class="tt-item"><div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div><div class="tt-info"><div class="code"><?= htmlspecialchars($c['course_code']) ?></div><div class="name"><?= htmlspecialchars($c['course_name']) ?></div><div class="room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div></div></div>
            <?php endforeach; ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- HISTORY -->
      <div class="page-section" id="sec-history">
        <div class="section-title">My <span>History</span></div>
        <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
          <table class="data-table"><thead><tr><th>Course</th><th>Status</th><th>Date & Time</th></tr></thead>
          <tbody>
            <?php
            $hist = $pdo->prepare("SELECT a.*, s.course_code, s.course_name FROM attendance a JOIN sessions s ON a.session_id=s.id WHERE a.student_id=? ORDER BY a.timestamp DESC");
            $hist->execute([$userId]); $hist = $hist->fetchAll();
            if (empty($hist)): ?><tr><td colspan="3" style="color:var(--muted)">No records yet.</td></tr>
            <?php else: foreach ($hist as $r): ?>
              <tr><td><?= htmlspecialchars($r['course_code']) ?> <span style="color:var(--muted);font-size:.75rem">· <?= htmlspecialchars($r['course_name']) ?></span></td><td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'pending':'red')) ?>"><?= $r['status'] ?></span></td><td style="color:var(--muted);font-size:.75rem"><?= date('d M Y H:i',strtotime($r['timestamp'])) ?></td></tr>
            <?php endforeach; endif; ?>
          </tbody></table>
        </div></div>
      </div>

      <!-- STATS -->
      <div class="page-section" id="sec-stats">
        <div class="section-title">My <span>Stats</span></div>
        <?php if (empty($courseStats)): ?>
          <p style="color:var(--muted);font-size:.83rem">No attendance data yet.</p>
        <?php else: foreach ($courseStats as $cs):
          $pct = $cs['total'] > 0 ? round(($cs['attended']/$cs['total'])*100) : 0;
          $color = $pct >= 75 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
        ?>
          <div class="course-bar">
            <div class="course-bar-label">
              <span><?= htmlspecialchars($cs['course_code']) ?> · <?= htmlspecialchars($cs['course_name']) ?></span>
              <span style="color:<?= $color ?>;font-weight:600"><?= $pct ?>%</span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
            <div style="font-size:.68rem;color:var(--muted);margin-top:.2rem"><?= $cs['attended'] ?> / <?= $cs['total'] ?> sessions</div>
          </div>
        <?php endforeach; endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
function showSection(name,el){document.querySelectorAll('.page-section').forEach(s=>s.classList.remove('active'));document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));document.getElementById('sec-'+name).classList.add('active');document.getElementById('page-title').textContent=name.toUpperCase();if(el)el.classList.add('active');document.getElementById('sidebar').classList.remove('open')}
if(window.innerWidth<=768)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=768?'block':'none'});

// ── CODE TIMER ──
<?php if ($activeSession): ?>
let timeLeft = <?= 120 - (time() % 120) ?>;
function updateTimer(){
  const fill = document.getElementById('timer-fill');
  const num  = document.getElementById('timer-num');
  if(!fill) return;
  fill.style.width = (timeLeft/120*100)+'%';
  fill.style.background = timeLeft<=10?'var(--danger)':timeLeft<=20?'var(--warning)':'var(--gold)';
  if(num) num.textContent = timeLeft;
}
updateTimer();
setInterval(()=>{
  timeLeft--; if(timeLeft<0){timeLeft=119; clearCodeInputs();}
  updateTimer();
},1000);
<?php endif; ?>

// ── CODE INPUT ──
function codeInput(el,idx){
  el.value=el.value.replace(/\D/,'');
  if(el.value) el.classList.add('filled');
  else el.classList.remove('filled');
  if(el.value && idx<6) document.getElementById('ci'+(idx+1)).focus();
  checkCodeReady();
}
function codeBack(e,idx){if(e.key==='Backspace'&&!document.getElementById('ci'+idx).value&&idx>1){document.getElementById('ci'+(idx-1)).focus()}}
function clearCodeInputs(){for(let i=1;i<=6;i++){const el=document.getElementById('ci'+i);if(el){el.value='';el.classList.remove('filled')}}document.getElementById('ci1').focus();checkCodeReady()}
function checkCodeReady(){
  let full=true;
  for(let i=1;i<=6;i++){if(!document.getElementById('ci'+i)?.value)full=false}
  const btn=document.getElementById('verify-code-btn');
  if(btn) btn.disabled=!full;
}

// Handle paste
document.addEventListener('DOMContentLoaded',()=>{
  const ci1=document.getElementById('ci1');
  if(ci1) ci1.addEventListener('paste',e=>{
    e.preventDefault();
    const text=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
    for(let i=0;i<text.length;i++){const el=document.getElementById('ci'+(i+1));if(el){el.value=text[i];el.classList.add('filled')}}
    checkCodeReady();
  });
});

// ── VERIFY CODE ──
async function verifyCode(){
  const btn=document.getElementById('verify-code-btn');
  const errEl=document.getElementById('code-error');
  btn.disabled=true; btn.textContent='Verifying...';
  errEl.style.display='none';
  let code=''; for(let i=1;i<=6;i++) code+=document.getElementById('ci'+i)?.value||'';
  try{
    const res=await fetch('../../api/verify_code.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:<?= $activeSession ? $activeSession['id'] : 'null' ?>,code})});
    const data=await res.json();
    if(data.success){
      // Move to selfie step
      document.getElementById('dot-code').className='step-dot done';
      document.getElementById('dot-selfie').className='step-dot active';
      document.getElementById('step-label').textContent='Step 2: Take your selfie';
      document.getElementById('step-code-section').style.display='none';
      document.getElementById('step-selfie-section').style.display='block';
      startCamera();
    } else {
      errEl.textContent=data.message||'Invalid code. Try again.';
      errEl.style.display='block';
      btn.disabled=false; btn.textContent='Verify Code';
    }
  }catch(e){errEl.textContent='Connection error. Try again.';errEl.style.display='block';btn.disabled=false;btn.textContent='Verify Code'}
}

// ── CAMERA ──
let stream=null;
let capturedSelfie=null;
let capturedClassroom=null;
let cameraStep='selfie';
function startCamera(){
  navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}}}).then(s=>{
    stream=s;
    const v=document.getElementById('video-preview');
    v.srcObject=s;
  }).catch(()=>{
    document.getElementById('submit-error').textContent='Camera access denied. Please allow camera.';
    document.getElementById('submit-error').style.display='block';
  });
}
function stopCamera(){if(stream){stream.getTracks().forEach(t=>t.stop());stream=null}}

let capturedSelfie = null;
let capturedClassroom = null;
let cameraStep = 'selfie'; // 'selfie' or 'classroom'

function captureSelfie(){
  const video  = document.getElementById('video-preview');
  const canvas = document.getElementById('capture-canvas');
  canvas.width = video.videoWidth||320; canvas.height = video.videoHeight||240;
  canvas.getContext('2d').drawImage(video,0,0);

  if(cameraStep === 'selfie'){
    capturedSelfie = canvas.toDataURL('image/jpeg',0.8);
    // Show selfie preview and switch to rear camera
    document.getElementById('selfie-preview').src = capturedSelfie;
    document.getElementById('selfie-preview').style.display = 'block';
    document.getElementById('capture-btn').textContent = '📸 Capture Classroom';
    document.getElementById('step-label').textContent = 'Step 3: Show your classroom with the rear camera';
    document.getElementById('retake-btn').style.display = 'flex';
    // Switch to rear camera
    stopCamera();
    cameraStep = 'classroom';
    navigator.mediaDevices.getUserMedia({video:{facingMode:{exact:'environment'},width:{ideal:640},height:{ideal:480}}})
      .then(s=>{stream=s;document.getElementById('video-preview').srcObject=s;})
      .catch(()=>{
        // Rear camera not available, use front
        navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}}).then(s=>{stream=s;document.getElementById('video-preview').srcObject=s;});
      });
  } else {
    capturedClassroom = canvas.toDataURL('image/jpeg',0.8);
    const preview = document.getElementById('selfie-preview');
    preview.src = capturedClassroom;
    document.getElementById('capture-btn').style.display = 'none';
    document.getElementById('submit-btn').style.display = 'flex';
    document.getElementById('step-label').textContent = 'Step 3: Submit for Rep approval';
    stopCamera();
  }
}

function retakeSelfie(){
  capturedSelfie = null; capturedClassroom = null; cameraStep = 'selfie';
  document.getElementById('selfie-preview').style.display = 'none';
  document.getElementById('capture-btn').style.display = 'flex';
  document.getElementById('capture-btn').textContent = '📸 Capture Selfie';
  document.getElementById('retake-btn').style.display = 'none';
  document.getElementById('submit-btn').style.display = 'none';
  document.getElementById('step-label').textContent = 'Step 2: Take your selfie';
  startCamera();
}

// ── SUBMIT ──
async function submitAttendance(){
  const btn=document.getElementById('submit-btn');
  const errEl=document.getElementById('submit-error');
  if(!capturedSelfie){errEl.textContent='Please take a selfie first.';errEl.style.display='block';return}
  btn.disabled=true; btn.textContent='Submitting...'; errEl.style.display='none';
  try{
    const res=await fetch('../../api/mark_attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:<?= $activeSession ? $activeSession['id'] : 'null' ?>,selfie:capturedSelfie,classroom:capturedClassroom})});
    const data=await res.json();
    if(data.success){
      // Show pending confirmation
      document.getElementById('step-selfie-section').innerHTML=`
        <div class="pending-card" style="margin-top:1rem">
          <div class="pending-icon">⏳</div>
          <div class="pending-title">Submitted!</div>
          <div class="pending-sub">Your selfie is pending approval from the Course Rep.<br>You'll be marked present once approved.</div>
        </div>`;
    } else {
      errEl.textContent=data.message||'Submission failed. Try again.';
      errEl.style.display='block';
      btn.disabled=false; btn.textContent='Submit →';
    }
  }catch(e){errEl.textContent='Connection error. Try again.';errEl.style.display='block';btn.disabled=false;btn.textContent='Submit →'}
}
</script>
</body>
</html>
