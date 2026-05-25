<?php
require_once __DIR__ . '/../../includes/guard.php';
guardSuperAdmin();

// Handle CSV exports
$type = $_GET['type'] ?? '';
if ($type) {
    header('Content-Type: text/csv');
    $fn = 'citadel_'.$type.'_'.date('Y-m-d').'.csv';
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $out = fopen('php://output', 'w');

    if ($type === 'institutions') {
        fputcsv($out, ['ID','Name','Code','Email','Phone','Plan','Active','Created']);
        $rows = $pdo->query("SELECT id,name,slug,email,phone,plan,is_active,created_at FROM institutions ORDER BY id")->fetchAll();
        foreach($rows as $r) fputcsv($out, [$r['id'],$r['name'],$r['slug'],$r['email'],$r['phone'],$r['plan'],$r['is_active']?'Yes':'No',$r['created_at']]);
    }
    elseif ($type === 'users') {
        fputcsv($out, ['ID','Full Name','Email','Index No','Role','Institution','Active','Created']);
        $rows = $pdo->query("SELECT u.id,u.full_name,u.email,u.index_no,u.role,i.name AS inst,u.is_active,u.created_at FROM users u LEFT JOIN institutions i ON i.id=u.institution_id ORDER BY u.id")->fetchAll();
        foreach($rows as $r) fputcsv($out, [$r['id'],$r['full_name'],$r['email'],$r['index_no'],$r['role'],$r['inst'],$r['is_active']?'Yes':'No',$r['created_at']]);
    }
    elseif ($type === 'attendance') {
        fputcsv($out, ['ID','Student','Index No','Course','Session','Status','Timestamp','Institution']);
        $rows = $pdo->query("SELECT a.id,u.full_name,u.index_no,c.code AS course,s.id AS session_id,a.status,a.timestamp,i.name AS inst FROM attendance a JOIN users u ON u.id=a.student_id LEFT JOIN sessions s ON s.id=a.session_id LEFT JOIN courses c ON c.id=s.course_id LEFT JOIN institutions i ON i.id=u.institution_id ORDER BY a.timestamp DESC LIMIT 50000")->fetchAll();
        foreach($rows as $r) fputcsv($out, [$r['id'],$r['full_name'],$r['index_no'],$r['course'],$r['session_id'],$r['status'],$r['timestamp'],$r['inst']]);
    }
    elseif ($type === 'sessions') {
        fputcsv($out, ['ID','Course','Lecturer','Institution','Started','Ended','Active','Attendance Count']);
        $rows = $pdo->query("SELECT s.id,c.code AS course,u.full_name AS lecturer,i.name AS inst,s.started_at,s.ended_at,s.active_status,(SELECT COUNT(*) FROM attendance a WHERE a.session_id=s.id) AS att_count FROM sessions s LEFT JOIN courses c ON c.id=s.course_id LEFT JOIN users u ON u.id=s.lecturer_id LEFT JOIN institutions i ON i.id=u.institution_id ORDER BY s.started_at DESC LIMIT 10000")->fetchAll();
        foreach($rows as $r) fputcsv($out, [$r['id'],$r['course'],$r['lecturer'],$r['inst'],$r['started_at'],$r['ended_at'],$r['active_status']?'Yes':'No',$r['att_count']]);
    }
    fclose($out);
    audit('EXPORT', 'platform', 0, "type=$type");
    exit;
}

// Stats for display
$stats = [
    'institutions' => (int)$pdo->query("SELECT COUNT(*) FROM institutions")->fetchColumn(),
    'users'        => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'super_admin'")->fetchColumn(),
    'attendance'   => (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn(),
    'sessions'     => (int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Export Data</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100%}
a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
.sidebar{width:230px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50}
.sb-brand{display:flex;align-items:center;gap:.75rem;padding:1.5rem 1.3rem 1.1rem;border-bottom:1px solid var(--border)}
.sb-logo{font-family:'Cinzel',serif;font-size:.95rem;font-weight:700;color:var(--gold);letter-spacing:.12em}.sb-role{font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
.sb-nav{flex:1;padding:1rem 0}.sb-sec{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.3rem .3rem}
.sb-a{display:flex;align-items:center;gap:.7rem;padding:.6rem 1.3rem;color:var(--muted);font-size:.84rem;border-left:2px solid transparent;transition:all .15s}
.sb-a:hover{color:var(--text);background:rgba(255,255,255,.03)}.sb-a.on{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.05)}
.sb-foot{padding:1rem 1.3rem;border-top:1px solid var(--border)}.sb-av{width:32px;height:32px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;color:#060910}
.sb-out{display:block;margin-top:.7rem;font-size:.74rem;color:var(--muted)}.sb-out:hover{color:var(--danger)}
.main{margin-left:230px;flex:1;padding:2rem 2.2rem}
.pt{font-family:'Cinzel',serif;font-size:1.4rem;letter-spacing:.08em}.ps{font-size:.8rem;color:var(--muted);margin-top:.2rem}
.export-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2rem;margin-top:1.5rem}
.ecard{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.5rem;position:relative;overflow:hidden;transition:border-color .2s}
.ecard:hover{border-color:var(--gold)}
.ecard::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.ecard-icon{font-size:1.8rem;margin-bottom:.8rem}
.ecard-title{font-family:'Cinzel',serif;font-size:.85rem;color:var(--text);letter-spacing:.08em;margin-bottom:.3rem}
.ecard-count{font-size:1.6rem;font-weight:600;color:var(--gold);margin-bottom:.3rem}
.ecard-sub{font-size:.72rem;color:var(--muted);margin-bottom:1.2rem}
.ecard a{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.7rem;font-weight:700;letter-spacing:.1em;border-radius:2px;text-decoration:none}
.ecard a:hover{opacity:.88}
.note{background:rgba(74,111,165,.08);border:1px solid rgba(74,111,165,.2);border-radius:2px;padding:1rem 1.2rem;margin-top:1.5rem;font-size:.8rem;color:var(--muted)}
.menu-toggle{display:none}
@media(max-width:768px){
  #menu-toggle{display:flex!important;align-items:center;justify-content:center;width:36px;height:36px;background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:4px;color:var(--text);cursor:pointer;font-size:18px;margin-bottom:1rem}
  .sidebar{width:260px!important;position:fixed!important;top:0!important;left:0!important;height:100dvh!important;z-index:500!important;transform:translateX(-100%)!important;transition:transform .25s ease!important;box-shadow:4px 0 20px rgba(0,0,0,.8)!important;overflow:hidden!important;display:flex!important;flex-direction:column!important}
  .sidebar.open{transform:translateX(0)!important}
  .sb-nav{flex:1 1 0!important;overflow-y:auto!important;min-height:0!important}
  .sb-foot{flex-shrink:0!important}
  .main{margin-left:0!important;padding:1rem!important}
  .export-grid{grid-template-columns:1fr 1fr!important}
}
#sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:499;backdrop-filter:blur(2px)}
#sidebar-overlay.show{display:block}
</style>
<script>function toggleSidebar(){var sb=document.getElementById('sidebar');var ov=document.getElementById('sidebar-overlay');if(!sb)return;var open=sb.classList.toggle('open');if(ov)ov.style.display=open?'block':'none';}</script>
</head><body>
<div class="layout">
<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-brand"><div><div class="sb-logo">CITADEL</div><div class="sb-role">Super Admin</div></div></div>
  <nav class="sb-nav">
    <div class="sb-sec">Platform</div>
    <a href="dashboard.php" class="sb-a">📊 Overview</a>
    <a href="schools.php" class="sb-a">🏫 Schools</a>
    <a href="users.php" class="sb-a">👥 All Users</a>
    <a href="activity.php" class="sb-a">📋 Activity Log</a>
    <div class="sb-sec">Tools</div>
    <a href="announcements.php" class="sb-a">📣 Announcements</a>
    <a href="export.php" class="sb-a on">💾 Export Data</a>
    <a href="../../onboard.php" class="sb-a">➕ Add School</a>
  </nav>
  <div class="sb-foot">
    <div style="display:flex;align-items:center;gap:.6rem">
      <div class="sb-av"><?php echo strtoupper(substr($me['full_name'],0,2)); ?></div>
      <div><div style="font-size:.82rem"><?php echo htmlspecialchars(explode(' ',$me['full_name'])[0]); ?></div><div style="font-size:.65rem;color:var(--gold)">SUPER ADMIN</div></div>
    </div>
    <a href="../../logout.php" class="sb-out">← Sign out</a>
  </div>
</aside>
<main class="main">
  <button class="menu-toggle" id="menu-toggle" onclick="toggleSidebar()">☰</button>
  <div style="margin-bottom:1.5rem">
    <div class="pt">Export Data</div>
    <div class="ps">Download platform data as CSV files</div>
  </div>
  <div class="export-grid">
    <div class="ecard">
      <div class="ecard-icon">🏫</div>
      <div class="ecard-title">Institutions</div>
      <div class="ecard-count"><?php echo number_format($stats['institutions']); ?></div>
      <div class="ecard-sub">All registered schools</div>
      <a href="export.php?type=institutions">↓ Download CSV</a>
    </div>
    <div class="ecard">
      <div class="ecard-icon">👥</div>
      <div class="ecard-title">Users</div>
      <div class="ecard-count"><?php echo number_format($stats['users']); ?></div>
      <div class="ecard-sub">All students, lecturers & admins</div>
      <a href="export.php?type=users">↓ Download CSV</a>
    </div>
    <div class="ecard">
      <div class="ecard-icon">✅</div>
      <div class="ecard-title">Attendance</div>
      <div class="ecard-count"><?php echo number_format($stats['attendance']); ?></div>
      <div class="ecard-sub">All attendance records (max 50k)</div>
      <a href="export.php?type=attendance">↓ Download CSV</a>
    </div>
    <div class="ecard">
      <div class="ecard-icon">🕐</div>
      <div class="ecard-title">Sessions</div>
      <div class="ecard-count"><?php echo number_format($stats['sessions']); ?></div>
      <div class="ecard-sub">All attendance sessions</div>
      <a href="export.php?type=sessions">↓ Download CSV</a>
    </div>
  </div>
  <div class="note">💡 Exports are generated in real-time. Large datasets may take a few seconds to download. Attendance export is capped at 50,000 records.</div>
</main>
</div>
</body></html>
