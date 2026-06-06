<?php
ob_start();
require_once '../../includes/security.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/guard.php';
require_once '../../includes/terminology.php';
$instType = $institution['inst_type'] ?? 'university';
requireRole('student', 'rep');

$user   = currentUser();
$userId = $_SESSION['user_id'];

// Active semester
$inst_id   = (int)($_SESSION['institution_id'] ?? 1);
$programId = (int)($_SESSION['user']['program_id'] ?? 0);
$__q = $pdo->prepare("SELECT * FROM semesters WHERE is_active=1 AND institution_id=? LIMIT 1"); $__q->execute([$inst_id]); $activeSem = $__q->fetch();
$semId     = $activeSem['id'] ?? null;
$activeSemId = (int)($activeSem['id'] ?? 0);

// Student's enrolled courses this semester
$enrolledCourses = [];
if ($semId) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name AS lecturer_name,
               COUNT(DISTINCT s.id) AS total_sessions,
               SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
        FROM course_enrollments ce
        JOIN courses c ON c.id = ce.course_id
        LEFT JOIN course_assignments ca ON ca.course_id = c.id AND ca.semester_id = c.semester_id
        LEFT JOIN users u ON u.id = ca.lecturer_id
        LEFT JOIN sessions s ON s.course_id = c.id
        LEFT JOIN attendance a ON a.session_id = s.id AND a.student_id = ?
        WHERE ce.student_id = ? AND ce.semester_id = ? AND ce.status = 'active'
        GROUP BY c.id ORDER BY c.code ASC
    ");
    $stmt->execute([$userId, $userId, $semId]);
    $enrolledCourses = $stmt->fetchAll();
}

// Overall stats scoped to enrolled courses
$totalSessions = 0; $myAttended = 0;
foreach ($enrolledCourses as $c) {
    $totalSessions += $c['total_sessions'];
    $myAttended    += $c['attended'];
}
$attendanceRate = $totalSessions > 0 ? round(($myAttended / $totalSessions) * 100) : 0;

// Today's classes (from timetable, filtered to enrolled courses)
$today = date('l');
$enrolledCourseIds = array_column($enrolledCourses, 'id');
$todayClasses = [];
if (!empty($enrolledCourseIds)) {
    $placeholders = implode(',', array_fill(0, count($enrolledCourseIds), '?'));
    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS course_name, c.code AS course_code, u.full_name AS lecturer_name
        FROM timetable t
        JOIN courses c ON c.id = t.course_id
        LEFT JOIN course_assignments ca ON ca.course_id = c.id AND ca.semester_id = ?
        LEFT JOIN users u ON u.id = ca.lecturer_id
        WHERE t.day_of_week = ? AND t.course_id IN ($placeholders)
        ORDER BY t.start_time
    ");
    $stmt->execute(array_merge([$semId, $today], $enrolledCourseIds));
    $todayClasses = $stmt->fetchAll();
}

// Fallback: if no course_id links, use old timetable
if (empty($todayClasses)) {
    $stmt = $pdo->prepare("SELECT t.*, u.full_name AS lecturer_name FROM timetable t JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? AND u.institution_id=? ORDER BY t.start_time");
    $stmt->execute([$today, $inst_id]); $todayClasses = $stmt->fetchAll();
}

// Active session — only if student is enrolled in that course
$activeSession = null;
// Find active session for student's program
$sessionQuery = $pdo->prepare("
    SELECT s.* FROM sessions s 
    JOIN users u ON u.id=s.lecturer_id 
    WHERE s.active_status=1 AND u.institution_id=?
    AND (s.program_id=? OR s.program_id IS NULL)
    ORDER BY s.start_time DESC LIMIT 1
");
$sessionQuery->execute([$inst_id, $programId ?: null]);
$rawActive = $sessionQuery->fetch();
if ($rawActive) {
    if ($rawActive['course_id'] && !empty($enrolledCourseIds) && in_array($rawActive['course_id'], $enrolledCourseIds)) {
        $activeSession = $rawActive;
    } elseif (!$rawActive['course_id']) {
        $activeSession = $rawActive;
    }
}

// Check existing record
$myPending = null; $myRecord = null;
if ($activeSession) {
    $chk = $pdo->prepare("SELECT * FROM attendance WHERE session_id=? AND student_id=?");
    $chk->execute([$activeSession['id'], $userId]); $myRecord = $chk->fetch();
    if ($myRecord && $myRecord['status'] === 'pending') $myPending = $myRecord;
}

// Recent attendance
$recentAtt = $pdo->prepare("
    SELECT a.*, s.course_code, s.course_name
    FROM attendance a JOIN sessions s ON a.session_id=s.id
    WHERE a.student_id=? ORDER BY a.timestamp DESC LIMIT 10
");
$recentAtt->execute([$userId]); $recentAtt = $recentAtt->fetchAll();

// Announcements
$__q = $pdo->prepare("SELECT a.message, a.created_at, u.full_name FROM announcements a JOIN users u ON a.rep_id=u.id WHERE u.institution_id=? ORDER BY a.created_at DESC LIMIT 5"); $__q->execute([$inst_id]); $announcements = $__q->fetchAll();

// Full attendance history
$hist = $pdo->prepare("SELECT a.*, s.course_code, s.course_name FROM attendance a JOIN sessions s ON a.session_id=s.id WHERE a.student_id=? ORDER BY a.timestamp DESC");
$hist->execute([$userId]); $hist = $hist->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Student Portal</title>
<link rel="stylesheet" href="/assets/css/citadel.css">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050;--sidebar-w:240px}
body.light{--bg:#f0f2f5;--surface:#ffffff;--surface2:#f5f7fa;--border:#dde1e9;--text:#1a2035;--muted:#5a6a7d;--gold:#8a6520;--gold-dim:#c9a84c;--steel:#2a4f8a}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 60% 40% at 80% 0%,rgba(74,111,165,.12) 0%,transparent 60%);pointer-events:none}
.layout{display:flex;min-height:100vh;position:relative;z-index:1}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;position:fixed;top:0;left:0;bottom:0;z-index:500!important;transition:transform .3s}
.sidebar-brand{padding:1.6rem 1.4rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.8rem}
.sidebar-brand svg{width:32px;height:32px;flex-shrink:0}
.brand-name{font-family:'Cinzel',serif;font-size:1rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
.brand-role{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--steel)}
.sidebar-nav{flex:1;min-height:0;padding:1rem 0;overflow-y:auto;scrollbar-width:none}
.nav-section{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.4rem .4rem}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.4rem;color:var(--muted);text-decoration:none;font-size:.85rem;cursor:pointer;border-left:2px solid transparent;transition:all .2s}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-item.active{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.06)}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-user{padding:.5rem 1.4rem .8rem;border-top:1px solid var(--border)}
.u-name{font-size:.78rem;color:var(--text);font-weight:500;margin-bottom:.1rem}
.u-index{font-size:.62rem;color:var(--muted);margin-bottom:.2rem}
.sidebar-user a{color:var(--danger);text-decoration:none;font-size:.74rem;display:block;margin-top:.2rem}
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
.card-body{padding:1.2rem 1.4rem;overflow-x:auto;-webkit-overflow-scrolling:touch}.card{overflow:hidden}
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
.tt-time{font-size:.75rem;color:var(--gold);font-weight:500}
.tt-info .code{font-size:.7rem;color:var(--muted)}
.tt-info .name{font-size:.85rem;color:var(--text)}
.tt-info .room{font-size:.72rem;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;font-family:'DM Sans',sans-serif;font-size:.78rem;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s,transform .15s;text-decoration:none}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.btn-gold{background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-weight:600;font-family:'Cinzel',serif;letter-spacing:.1em}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
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
.timer-num{font-family:'Cinzel',serif;font-size:.85rem;color:var(--gold);text-align:right}
.pending-card{background:var(--surface);border:1px solid rgba(224,160,80,.3);border-radius:2px;padding:2rem;text-align:center}
.pending-icon{font-size:2.5rem;margin-bottom:1rem}
.pending-title{font-family:'Cinzel',serif;font-size:1rem;color:var(--warning);margin-bottom:.5rem}
.pending-sub{font-size:.82rem;color:var(--muted)}
.camera-section{margin-top:1.2rem}
.camera-wrap{position:relative;width:100%;max-width:320px;margin:0 auto;border-radius:2px;overflow:hidden;background:#000;aspect-ratio:4/3}
.camera-wrap video{width:100%;height:100%;object-fit:cover}
.face-guide{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
.face-oval{width:140px;height:180px;border:2px solid rgba(201,168,76,.6);border-radius:50%;animation:glowPulse 2s infinite}
@keyframes glowPulse{0%,100%{box-shadow:0 0 0 0 rgba(201,168,76,.3)}50%{box-shadow:0 0 0 8px rgba(201,168,76,0)}}
.selfie-preview{width:100%;max-width:320px;margin:1rem auto 0;display:block;border-radius:2px;border:1px solid var(--border)}
canvas{display:none}
.step-indicator{display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;font-size:.72rem;color:var(--muted)}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--border)}
.step-dot.active{background:var(--gold)}
.step-dot.done{background:var(--success)}
.course-bar{margin-bottom:1rem}
.course-bar-label{display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.3rem}
.bar-track{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;transition:width .8s ease}

/* Sidebar overlay background */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}

input,select,textarea{font-size:16px!important}

input,select,textarea{font-size:16px!important}

/*  MOBILE - CLEAN  */
@media(max-width:768px){
  .card{max-width:100%!important;overflow:hidden!important}
  .content{max-width:100vw!important;overflow-x:hidden!important}
  .page-section{max-width:100%!important}
  .layout{overflow-x:hidden!important}
  .sidebar{
    width:260px!important;
    position:fixed!important;top:0!important;left:0!important;
    height:100vh!important;height:100dvh!important;z-index:500!important;
    transform:translateX(-100%)!important;
    transition:transform .25s ease!important;
    box-shadow:4px 0 20px rgba(0,0,0,.8)!important;
    overflow:hidden!important
  }
  .sidebar.open{transform:translateX(0)!important}
  .main{margin-left:0!important}
  .content{padding:.7rem!important}
  .topbar{padding:.65rem .9rem!important;gap:.5rem;overflow:hidden!important}
  .topbar .flex-center{gap:.4rem!important;min-width:0!important;overflow:hidden!important}
  .topbar .badge-lec,.topbar .badge,.topbar .badge-admin{flex-shrink:0!important;font-size:.55rem!important;padding:.2rem .4rem!important;white-space:nowrap!important}
  .topbar .t-muted-75{display:none!important}
  #menu-btn{
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    width:36px!important;height:36px!important;!important;
    background:rgba(255,255,255,.08)!important;
    border:1px solid var(--border)!important;
    border-radius:4px!important;
    color:var(--text)!important;
    cursor:pointer!important;
    font-size:18px!important;
    flex-shrink:0!important;
    padding:0!important
  }
  .stats-grid{grid-template-columns:1fr 1fr!important;gap:.5rem!important}
  .stat-card{padding:.8rem!important}
  .stat-value{font-size:1.4rem!important}
  .two-col{grid-template-columns:1fr!important}
  .form-row{grid-template-columns:1fr!important}
  .card-body{overflow-x:auto!important}
  .data-table,.tbl{font-size:.72rem!important;min-width:0!important}
  .data-table th,.data-table td,.tbl th,.tbl td{padding:.38rem .45rem!important;white-space:nowrap!important}
  .section-header{flex-direction:column!important;gap:.4rem!important}
  .btn{padding:.55rem .8rem!important;font-size:.74rem!important}
  .modal{width:94vw!important;max-height:85vh!important;overflow-y:auto!important}
  .hide-mobile{display:none!important}
  .tt-item{flex-direction:column!important;gap:.2rem!important}
  .code-inputs input{width:38px!important;height:46px!important;font-size:1.1rem!important}
  .page-section.active{display:block!important}
}
@media(max-width:420px){
  .stats-grid{grid-template-columns:1fr!important}
}
@media(){
  #menu-btn{display:none!important}
}
input,select,textarea{font-size:16px!important}
@media(){input,select,textarea{font-size:unset!important}}

#sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.7);z-index:400;
  backdrop-filter:blur(2px)
}
#sidebar-overlay.show{display:block}

/* TABLE SCROLL FIX */
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}







/* === MOBILE TABLE FIX === */

#ca-summary-cards:empty,#rep-ca-cards:empty,#admin-ca-cards:empty{display:none!important;margin:0!important;padding:0!important}
</style>

<script>
const BASE_URL = window.location.origin;
const API = BASE_URL + '/api';


function openModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) {
      if (e.target === o) o.classList.remove('open');
    });
  });
});

function toggleSidebar(){
  var sb=document.getElementById('sidebar');
  var ov=document.getElementById('sidebar-overlay');
  if(!sb)return;
  var isOpen=sb.classList.toggle('open');
  if(ov)ov.style.display=isOpen?'block':'none';
}

function showSection(name,el){
  document.querySelectorAll('.page-section').forEach(function(s){s.classList.remove('active');});
  document.querySelectorAll('.nav-item').forEach(function(n){n.classList.remove('active');});
  var sec=document.getElementById('sec-'+name);
  if(sec)sec.classList.add('active');
  window.scrollTo({top:0,behavior:'smooth'});
  var title=document.getElementById('page-title');
  if(title)title.textContent=name.charAt(0).toUpperCase()+name.slice(1);
  if(el)el.classList.add('active');
  var sb=document.getElementById('sidebar');
  if(sb&&sb.classList.contains('open'))toggleSidebar();
  if(name==='ca' && typeof loadMyCA==='function') setTimeout(loadMyCA,80);
}

document.addEventListener('DOMContentLoaded',function(){
  if(localStorage.getItem('theme')==='light'){document.body.classList.add('light');document.querySelectorAll('.theme-btn').forEach(b=>b.innerHTML='&#9728;');}

  var btn=document.getElementById('menu-btn')||document.getElementById('menu-toggle');
  if(btn)btn.onclick=function(e){e.stopPropagation();toggleSidebar();};
  var ov=document.getElementById('sidebar-overlay');
  if(ov)ov.onclick=function(){toggleSidebar();};
});
function toggleTheme(){
  var isLight=document.body.classList.toggle('light');
  localStorage.setItem('theme',isLight?'light':'dark');
  document.querySelectorAll('.theme-btn').forEach(function(b){b.innerHTML=isLight?'&#9728;':'&#9790;';});
}
</script>

</head>

<body>
<div class="layout">
<div id="sidebar-overlay" class="overlay-sidebar"></div>
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
      Mark Attendance<?= $activeSession ? ' ' : '' ?>
    </a>
    <a class="nav-item" onclick="showSection('courses',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg><?= terms('courses', $instType) ?>
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
    <a class="nav-item" onclick="showSection('ca',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>My CA Scores
    </a>
  </nav>
  <div class="sidebar-user">
    <div class="u-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
    <div class="u-index"><?= htmlspecialchars($user['index_no'] ?? '') ?><?= $activeSem ? ' · '.htmlspecialchars($activeSem['name']) : '' ?></div>
    <div class="sidebar-user-actions"><a href="../../change_password.php" class="btn-pwd"> Password</a><a href="../../logout.php" class="btn-out">Sign Out</a></div>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div class="flex-center">
      <button id="menu-btn" aria-label="Menu" onclick="toggleSidebar()">&#9776;</button>
      <div class="topbar-title" id="page-title">OVERVIEW</div>
    </div>
    <div class="flex-center">
      <span class="t-muted-75"><?= date('l, d M Y') ?></span>
      <span class="badge">Student</span>
      <button id="theme-btn" onclick="toggleTheme()" class="theme-btn">&#9790;</button>
    </div>
  </div>

  <div class="content">

    <!-- OVERVIEW -->
    <div class="page-section active" id="sec-overview">
      <div class="stats-grid">
        <div class="stat-card gold"><div class="stat-label">Attendance Rate</div><div class="stat-value"><?= $attendanceRate ?>%</div><div class="stat-sub">Across enrolled courses</div></div>
        <div class="stat-card steel"><div class="stat-label">Sessions Present</div><div class="stat-value"><?= $myAttended ?></div><div class="stat-sub">Out of <?= $totalSessions ?> total</div></div>
        <div class="stat-card green"><div class="stat-label">Today's Classes</div><div class="stat-value"><?= count($todayClasses) ?></div><div class="stat-sub"><?= $today ?></div></div>
      </div>
      <div class="two-col">
        <div class="card">
          <div class="card-head"><div class="card-head-title">Today — <?= $today ?></div></div>
          <div class="card-body">
            <?php if(empty($todayClasses)): ?><p class="t-muted-83">No classes today.</p>
            <?php else: ?><div class="tt-grid"><?php foreach($todayClasses as $c): ?>
              <div class="tt-item">
                <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                <div class="tt-info"><div class="code"><?= htmlspecialchars($c['course_code']) ?></div><div class="name"><?= htmlspecialchars($c['course_name']) ?></div><div class="room"> <?= htmlspecialchars($c['room'] ?? '') ?> · <?= htmlspecialchars($c['lecturer_name'] ?? '') ?></div></div>
              </div>
            <?php endforeach; ?></div>
            <?php if($activeSession): ?><button class="btn btn-gold" class="btn-full-mt" onclick="showSection('mark',document.getElementById('mark-nav'))">Mark Attendance Now →</button><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-head-title">Recent Records</div></div>
          <div class="card-body" class="card-pad-0">
            <table class="data-table"><thead><tr><th><?= terms('course', $instType) ?></th><th>Status</th><th>Date</th></tr></thead><tbody>
            <?php if(empty($recentAtt)): ?><tr><td colspan="3" class="t-muted">No records yet.</td></tr>
            <?php else: foreach($recentAtt as $r): ?>
              <tr><td><?= htmlspecialchars($r['course_code']) ?></td><td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'pending':'red')) ?>"><?= $r['status'] ?></span></td><td class="t-muted-72"><?= date('d M',strtotime($r['timestamp'])) ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody></table>
          </div>
        </div>
      </div>
      <div class="card"><div class="card-head"><div class="card-head-title"> Announcements</div></div><div class="card-body" class="card-pad-0"><table class="data-table"><thead><tr><th>Message</th><th class="hide-mobile">From</th><th class="hide-mobile">Date</th></tr></thead><tbody id="ann-list">
      <?php if(empty($announcements)): ?><tr><td colspan="3" class="t-muted">No announcements yet.</td></tr>
      <?php else: foreach($announcements as $ann): ?><tr><td><?= htmlspecialchars($ann['message']) ?></td><td class="t-gold-75"><?= htmlspecialchars($ann['full_name']) ?></td><td class="t-muted-72"><?= date('d M H:i',strtotime($ann['created_at'])) ?></td></tr><?php endforeach; endif; ?>
      </tbody></table></div></div>
    </div>

    <!-- MARK ATTENDANCE -->
    <div class="page-section" id="sec-mark">
      <div class="attend-zone">
        <?php if ($myPending): ?>
          <div class="pending-card">
            <div class="pending-icon"></div>
            <div class="pending-title">Awaiting Approval</div>
            <div class="pending-sub">Your attendance for <strong><?= htmlspecialchars($activeSession['course_code'] ?? '') ?></strong> is pending.<br><?= $instType==='university' ? 'The Course Rep will review shortly.' : 'Your teacher will mark you shortly.' ?></div>
            <?php if($myPending['selfie_url']): ?><img src="<?= strpos($myPending['selfie_url'],'data:')===0 ? htmlspecialchars($myPending['selfie_url']) : '../../'.htmlspecialchars($myPending['selfie_url']) ?>" class="selfie-img"><?php endif; ?>
          </div>
        <?php elseif ($myRecord && $myRecord['status'] !== 'pending'): ?>
          <div class="pending-card" class="border-success">
            <div class="pending-icon"><?= $myRecord['status']==='present'?'':'' ?></div>
            <div class="pending-title" class="t-success">Marked <?= ucfirst($myRecord['status']) ?></div>
            <div class="pending-sub">You have been marked <strong><?= $myRecord['status'] ?></strong> for <?= htmlspecialchars($activeSession['course_code'] ?? '') ?>.</div>
          </div>
        <?php elseif ($activeSession): ?>
          <div class="session-active-card">
            <div class="live-dot"></div>
            <div>
              <div class="fs-72 t-success">Session Active</div>
              <div class="fw-500-text9"><?= htmlspecialchars($activeSession['course_code']) ?> · <?= htmlspecialchars($activeSession['course_name'] ?? '') ?></div>
            </div>
          </div>
          <?php if($activeSession['is_online'] ?? false): ?>
          <!-- ONLINE SESSION MODE -->
          <div class="online-banner">
            <div class="fs-72 mb-5"> Online Class</div>
            <div class="fs-85 mb-8">This is an online session. Enter the code your lecturer shares to mark your attendance.</div>
            <?php if(!empty($activeSession['meeting_link'])): ?>
            <a href="<?= htmlspecialchars($activeSession['meeting_link']) ?>" target="_blank" rel="noopener" class="btn btn-steel" class="flex-inline-mb10">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-4"><path d="M15 10l4.553-2.069A1 1 0 0 1 21 8.868v6.264a1 1 0 0 1-1.447.894L15 14"/><rect x="3" y="6" width="12" height="12" rx="2"/></svg>
              Join Online Class
            </a>
            <?php endif; ?>
          </div>
          <div class="mt-10">
            <div class="code-inputs">
              <?php for($i=1;$i<=6;$i++): ?><input type="text" maxlength="1" id="ci<?=$i?>" oninput="codeInput(this,<?=$i?>)" onkeydown="codeBack(event,<?=$i?>)" inputmode="numeric"><?php endfor; ?>
            </div>
            <button class="btn btn-gold" id="verify-code-btn" onclick="verifyCode()" class="btn btn-gold btn-full-center mt-10" disabled>Verify Code</button>
            <div id="code-error" class="err-inline"></div>
          </div>
          <?php elseif($instType !== 'university'): ?>
          <div class="pending-card" class="border-success-mt">
            <div class="pending-icon"></div>
            <div class="pending-title" class="t-success">Class in Progress</div>
            <div class="pending-sub">Your teacher is marking attendance. You will be marked automatically.</div>
          </div>
          <?php else: ?>
          <div class="step-indicator">
            <div class="step-dot active" id="dot-code"></div>
            <div class="step-dot" id="dot-selfie" class="ml-3"></div>
            <div class="step-dot" id="dot-class" class="ml-3"></div>
            <span id="step-label" class="ml-5">Step 1: Enter the 6-digit code</span>
          </div>
          <div id="step-code-section">
            <div class="timer-strip">
              <span class="t-muted-72">CODE EXPIRES IN</span>
              <div class="timer-bar"><div class="timer-fill" id="timer-fill"></div></div>
              <div class="timer-num" id="timer-num">120</div>
            </div>
            <div class="code-inputs">
              <?php for($i=1;$i<=6;$i++): ?><input type="text" maxlength="1" id="ci<?=$i?>" oninput="codeInput(this,<?=$i?>)" onkeydown="codeBack(event,<?=$i?>)" inputmode="numeric"><?php endfor; ?>
            </div>
            <button class="btn btn-gold" id="verify-code-btn" onclick="verifyCode()" class="btn btn-gold btn-full-center" disabled>Verify Code</button>
            <div id="code-error" class="err-inline"></div>
          </div>
          <div id="step-selfie-section" class="d-none">
            <div class="text-center-mb">
              <div id="step-main-heading" class="fs-72 t-gold">Step 2: Face Verification</div>
              <div id="step-main-sub" class="t-muted-78 mt-3">Position your face in the oval and click Capture</div>
            </div>

            <div class="camera-wrap"><video id="video-preview" autoplay playsinline muted></video><div class="face-guide"><div class="face-oval"></div></div></div>
            <canvas id="capture-canvas"></canvas>
            <img id="selfie-preview" class="selfie-preview" class="d-none">
            <div class="flex-gap8-mt10">
              <button class="btn btn-ghost" id="retake-btn" onclick="retakeSelfie()" class="flex-1-center-none">Retake</button>
              <button class="btn btn-gold" id="capture-btn" onclick="captureSelfie()" class="flex-1-center" disabled>Loading...</button>
              <button class="btn btn-gold" id="submit-btn" onclick="submitAttendance()" class="flex-1-center-none">Submit →</button>
            </div>
            <div id="submit-error" class="err-inline"></div>
          </div>
          <div id="step-class-section" class="d-none">
            <div class="text-center-mb">
              <div class="fs-72 t-gold">Step 3: Classroom Verification</div>
              <div id="step-class-sub" class="t-muted-78 mt-3">Flip your camera to show the classroom</div>
            </div>
            <div class="camera-wrap"><video id="class-video" autoplay playsinline muted style="display:none"></video></div>
            <canvas id="class-canvas"></canvas>
            <img id="class-preview" class="selfie-preview" style="display:none">
            <div class="flex-gap8-mt10">
              <button class="btn btn-ghost" id="retake-class-btn" onclick="retakeClass()" style="display:none">Retake</button>
              <button class="btn btn-gold" id="class-capture-btn" onclick="captureClassroom()" disabled>Starting camera...</button>
              <button class="btn btn-gold" id="class-submit-btn" onclick="submitAttendance()" style="display:none">Submit →</button>
            </div>
            <div id="class-error" class="err-inline"></div>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="no-session-card">
            <div class="large-icon"></div>
            <div class="cinzel-muted">NO ACTIVE SESSION</div>
            <div class="t-muted-78 mt-5">Wait for your lecturer or course rep to start an attendance session.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- MY COURSES -->
    <div class="page-section" id="sec-courses">
      <div class="section-title">My <span>Courses</span><?php if($activeSem): ?><span class="sem-label"><?= htmlspecialchars($activeSem['name']) ?></span><?php endif; ?></div>
      <?php if(empty($enrolledCourses)): ?>
        <div class="card"><div class="card-body" class="t-muted">No courses enrolled this semester. Contact admin.</div></div>
      <?php else: ?>
        <div class="card" style="max-width:100%;overflow:hidden"><div class="card-body" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
          <table class="data-table" style=""><thead><tr><th style="width:80px">Code</th><th>Course Name</th><th class="hide-mobile" style="width:100px">Lecturer</th><th style="width:80px">Attended</th><th style="width:60px">Rate</th></tr></thead><tbody>
          <?php foreach($enrolledCourses as $c):
            $pct=$c['total_sessions']>0?round(($c['attended']/$c['total_sessions'])*100):0;
            $color=$pct>=75?'var(--success)':($pct>=50?'var(--warning)':'var(--danger)'); ?>
            <tr>
              <td class="fw-600 t-gold"><?= htmlspecialchars($c['code']) ?></td>
              <td><?= htmlspecialchars($c['name']) ?></td>
              <td class="hide-mobile" class="t-muted-78"><?= htmlspecialchars($c['lecturer_name'] ?? '—') ?></td>
              <td><?= $c['attended'] ?> / <?= $c['total_sessions'] ?></td>
              <td><span style="color:<?= $color ?>;font-weight:600"><?= $pct ?>%</span><?php if($pct<75&&$c['total_sessions']>3): ?> <span class="t-danger"></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        </div></div>
      <?php endif; ?>
    </div>

    <!-- TIMETABLE -->
    <div class="page-section" id="sec-timetable">
      <div class="section-title">Class <span>Timetable</span></div>
      <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day):
        $cls=array_filter($todayClasses,fn($c)=>true); // show all enrolled courses' schedule
        // Re-query per day for enrolled courses
        $dayCls=[];
        if(!empty($enrolledCourseIds)){
          $ph=implode(',',array_fill(0,count($enrolledCourseIds),'?'));
          $dq=$pdo->prepare("SELECT t.*,c.name AS course_name,c.code AS course_code,u.full_name AS lecturer_name FROM timetable t JOIN courses c ON c.id=t.course_id LEFT JOIN course_assignments ca ON ca.course_id=c.id LEFT JOIN users u ON u.id=ca.lecturer_id WHERE t.day_of_week=? AND t.course_id IN ($ph) ORDER BY t.start_time");
          $dq->execute(array_merge([$day],$enrolledCourseIds));
          $dayCls=$dq->fetchAll();
        }
        if(empty($dayCls)) continue; ?>
        <div class="mb-15">
          <div class="cinzel-gold-tt"><?= strtoupper($day) ?></div>
          <div class="tt-grid"><?php foreach($dayCls as $c): ?>
            <div class="tt-item"><div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div><div class="tt-info"><div class="code"><?= htmlspecialchars($c['course_code']) ?></div><div class="name"><?= htmlspecialchars($c['course_name']) ?></div><div class="room"> <?= htmlspecialchars($c['room']??'') ?> · <?= htmlspecialchars($c['lecturer_name']??'') ?></div></div></div>
          <?php endforeach; ?></div>
        </div>
      <?php endforeach; ?>
      <?php if(empty($enrolledCourseIds)): ?><p class="t-muted-83">No enrolled courses found.</p><?php endif; ?>
    </div>

    <!-- HISTORY -->
    <div class="page-section" id="sec-history">
      <div class="section-title">My <span>History</span></div>
      <div class="card"><div class="card-body" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
        <table class="data-table"><thead><tr><th>Course</th><th>Status</th><th>Date & Time</th></tr></thead><tbody>
        <?php if(empty($hist)): ?><tr><td colspan="3" class="t-muted">No records yet.</td></tr>
        <?php else: foreach($hist as $r): ?>
          <tr><td><strong><?= htmlspecialchars($r['course_code']) ?></strong><br><span class="t-muted-72"><?= htmlspecialchars($r['course_name']) ?></span></td>
          <td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':($r['status']==='pending'?'pending':'red')) ?>"><?= $r['status'] ?></span></td>
          <td class="t-nowrap-72"><?= date('d M Y',strtotime($r['timestamp'])) ?><br><?= date('H:i',strtotime($r['timestamp'])) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- STATS -->
    <div class="page-section" id="sec-stats">
      <div class="section-header-flex">
        <div class="section-title" class="mb-0">My <span>Stats</span></div>
        <a href="../../api/attendance_certificate.php" class="btn btn-gold" class="fs-72"> Download Certificate</a>
      </div>
      <?php if(empty($enrolledCourses)): ?>
        <p class="t-muted-83">No attendance data yet.</p>
      <?php else: foreach($enrolledCourses as $c):
        $pct=$c['total_sessions']>0?round(($c['attended']/$c['total_sessions'])*100):0;
        $color=$pct>=75?'var(--success)':($pct>=50?'var(--warning)':'var(--danger)'); ?>
        <div class="course-bar">
          <div class="course-bar-label"><span><?= htmlspecialchars($c['code']) ?> · <?= htmlspecialchars($c['name']) ?></span><span style="color:<?= $color ?>;font-weight:600"><?= $pct ?>%</span></div>
          <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
          <div class="t-muted-72 mt-3"><?= $c['attended'] ?> / <?= $c['total_sessions'] ?> sessions</div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</div>
</div>

<script>

<?php if($activeSession): ?>
let timeLeft=<?= 120-(time()%120) ?>;
function updateTimer(){const fill=document.getElementById('timer-fill');const num=document.getElementById('timer-num');if(!fill)return;fill.style.width=(timeLeft/120*100)+'%';fill.style.background=timeLeft<=10?'var(--danger)':timeLeft<=20?'var(--warning)':'var(--gold)';if(num)num.textContent=timeLeft}
updateTimer();
setInterval(()=>{timeLeft--;if(timeLeft<0){timeLeft=119;clearCodeInputs();}updateTimer()},1000);
<?php endif; ?>

let lastStatus="<?= $myRecord?$myRecord['status']:'none' ?>";
setInterval(()=>{fetch(API + '/session_status.php').then(r=>r.json()).then(data=>{const hasActive=<?= $activeSession?'true':'false' ?>;if(data.active&&!hasActive){location.reload();return;}if(!data.active&&hasActive){location.reload();return;}const newStatus=data.my_status||'none';if(newStatus!==lastStatus){if(newStatus==='present'||newStatus==='late'){const t=document.createElement('div');t.style.cssText='position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);background:var(--success);color:#060910;padding:.8rem 1.5rem;border-radius:2px;font-family:Cinzel,serif;font-size:.82rem;font-weight:700;z-index:999';t.textContent=' Approved! Marked '+newStatus.toUpperCase();document.body.appendChild(t);setTimeout(()=>location.reload(),2000);}else if(lastStatus==='pending'&&newStatus==='none'){const t=document.createElement('div');t.style.cssText='position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);background:var(--danger);color:#fff;padding:.8rem 1.5rem;border-radius:2px;font-family:Cinzel,serif;font-size:.82rem;font-weight:700;z-index:999';t.textContent=' Attendance Rejected. Please try again.';document.body.appendChild(t);setTimeout(()=>location.reload(),2500);}lastStatus=newStatus;}}).catch(()=>{})},10000);

function codeInput(el,idx){el.value=el.value.replace(/\D/,'');if(el.value)el.classList.add('filled');else el.classList.remove('filled');if(el.value&&idx<6)document.getElementById('ci'+(idx+1)).focus();checkCodeReady()}
function codeBack(e,idx){if(e.key==='Backspace'&&!document.getElementById('ci'+idx).value&&idx>1)document.getElementById('ci'+(idx-1)).focus()}
function clearCodeInputs(){for(let i=1;i<=6;i++){const el=document.getElementById('ci'+i);if(el){el.value='';el.classList.remove('filled')}}document.getElementById('ci1').focus();checkCodeReady()}
function checkCodeReady(){let full=true;for(let i=1;i<=6;i++){if(!document.getElementById('ci'+i)?.value)full=false}const btn=document.getElementById('verify-code-btn');if(btn)btn.disabled=!full}
document.addEventListener('DOMContentLoaded',()=>{const ci1=document.getElementById('ci1');if(ci1)ci1.addEventListener('paste',e=>{e.preventDefault();const text=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);for(let i=0;i<text.length;i++){const el=document.getElementById('ci'+(i+1));if(el){el.value=text[i];el.classList.add('filled')}}checkCodeReady()})});

async function verifyCode(){const btn=document.getElementById('verify-code-btn');const errEl=document.getElementById('code-error');btn.disabled=true;btn.textContent='Verifying...';errEl.style.display='none';let code='';for(let i=1;i<=6;i++)code+=document.getElementById('ci'+i)?.value||'';try{const res=await fetch(API + '/verify_code.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:<?= $activeSession?$activeSession['id']:'null' ?>,code})});const data=await res.json();if(data.success){document.getElementById('dot-code').className='step-dot done';document.getElementById('dot-selfie').className='step-dot active';document.getElementById('step-label').textContent='Step 2: Take your selfie';document.getElementById('step-code-section').style.display='none';document.getElementById('step-selfie-section').style.display='block';startCamera();}else{errEl.textContent=data.message||'Invalid code. Try again.';errEl.style.display='block';btn.disabled=false;btn.textContent='Verify Code'}}catch(e){errEl.textContent='Connection error. Try again.';errEl.style.display='block';btn.disabled=false;btn.textContent='Verify Code'}}

// ── FACE VERIFICATION ATTENDANCE ──
const SESSION_ID = <?= $activeSession ? $activeSession["id"] : "null" ?>;
let stream         = null;
let faceMatchScore = 0;
let livenessOk     = false;
let isEnrolling    = false;
let capturedSelfie = null;

async function startCamera() {
  const sub    = document.getElementById('step-main-sub');
  const capBtn = document.getElementById('capture-btn');
  sub.textContent    = 'Position your face in the oval';
  capBtn.disabled    = true;
  capBtn.textContent = 'Starting camera...';
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 320, height: 240 } });
    const video = document.getElementById('video-preview');
    video.srcObject = stream;
    await new Promise(r => video.onloadedmetadata = r);
    video.play();
    capBtn.disabled    = false;
    capBtn.textContent = 'Capture Selfie';
    sub.textContent    = 'Look at the camera and click Capture';
  } catch(e) {
    sub.textContent = 'Camera access denied. Please allow camera.';
  }
}

function stopCamera() {
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
}

async function captureSelfie() {
  const video  = document.getElementById('video-preview');
  const canvas = document.getElementById('capture-canvas');
  const capBtn = document.getElementById('capture-btn');
  const sub    = document.getElementById('step-main-sub');
  const errEl  = document.getElementById('submit-error');

  canvas.width  = video.videoWidth  || 320;
  canvas.height = video.videoHeight || 240;
  canvas.getContext('2d').drawImage(video, 0, 0);
  capturedSelfie = canvas.toDataURL('image/jpeg', 0.8);

  capBtn.disabled    = true;
  capBtn.textContent = 'Verifying...';
  sub.textContent    = 'Checking face with AI...';
  errEl.style.display = 'none';

  // AI face check via Haiku
  try {
    const aiRes  = await fetch('/api/ai_verify.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type: 'face', image: capturedSelfie })
    });
    const aiData = await aiRes.json();
    if (!aiData.success) {
      errEl.textContent   = aiData.message || 'Face not detected. Try again.';
      errEl.style.display = 'block';
      capBtn.disabled     = false;
      capBtn.textContent  = 'Try Again';
      capturedSelfie      = null;
      return;
    }
  } catch(e) {
    errEl.textContent   = 'Verification error. Try again.';
    errEl.style.display = 'block';
    capBtn.disabled     = false;
    capBtn.textContent  = 'Try Again';
    capturedSelfie      = null;
    return;
  }

  // Face passed AI check — set scores for submission
  faceMatchScore = 88;  // AI verified face
  livenessOk     = true;
  isEnrolling    = false;

  // Show preview
  const preview         = document.getElementById('selfie-preview');
  preview.src           = capturedSelfie;
  preview.style.display = 'block';
  document.getElementById('video-preview').style.display = 'none';
  document.getElementById('retake-btn').style.display    = 'flex';
  capBtn.style.display  = 'none';
  sub.textContent       = 'Face verified! Showing classroom step...';
  sub.style.color = 'var(--success)';
  stopCamera();
  // Move to step 3
  setTimeout(() => startClassroomStep(), 600);
}

function retakeSelfie() {
  capturedSelfie = null;
  document.getElementById('selfie-preview').style.display  = 'none';
  document.getElementById('video-preview').style.display   = 'block';
  document.getElementById('capture-btn').style.display     = 'flex';
  document.getElementById('capture-btn').textContent       = 'Capture Selfie';
  document.getElementById('capture-btn').disabled          = false;
  document.getElementById('retake-btn').style.display      = 'none';
  document.getElementById('submit-btn').style.display      = 'none';
  document.getElementById('step-main-sub').style.color     = '';
  document.getElementById('step-main-sub').textContent     = 'Look at the camera and click Capture';
  document.getElementById('submit-error').style.display    = 'none';
  startCamera();
}

let classStream = null;
let capturedClassroom = null;

function startClassroomStep() {
  document.getElementById('step-selfie-section').style.display = 'none';
  document.getElementById('step-class-section').style.display = 'block';
  document.getElementById('dot-selfie').className = 'step-dot done';
  document.getElementById('dot-class').className = 'step-dot active';
  document.getElementById('step-label').textContent = 'Step 3: Show your classroom';
  startClassCamera();
}

async function startClassCamera() {
  const sub = document.getElementById('step-class-sub');
  const btn = document.getElementById('class-capture-btn');
  sub.textContent = 'Starting rear camera...';
  btn.disabled = true;
  try {
    // Try rear camera first, fallback to any
    try {
      classStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: 'environment' }, width: 320, height: 240 } });
    } catch(e) {
      classStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: 320, height: 240 } });
    }
    const video = document.getElementById('class-video');
    video.srcObject = classStream;
    await new Promise(r => video.onloadedmetadata = r);
    video.style.display = 'block';
    video.play();
    btn.disabled = false;
    btn.textContent = 'Capture Classroom';
    sub.textContent = 'Point camera at the classroom and capture';
  } catch(e) {
    sub.textContent = 'Camera error. Try again.';
    btn.disabled = false;
    btn.textContent = 'Try Again';
    btn.onclick = startClassCamera;
  }
}

function stopClassCamera() {
  if (classStream) { classStream.getTracks().forEach(t => t.stop()); classStream = null; }
}

async function captureClassroom() {
  const video  = document.getElementById('class-video');
  const canvas = document.getElementById('class-canvas');
  const btn    = document.getElementById('class-capture-btn');
  const sub    = document.getElementById('step-class-sub');
  const errEl  = document.getElementById('class-error');

  canvas.width  = video.videoWidth  || 320;
  canvas.height = video.videoHeight || 240;
  canvas.getContext('2d').drawImage(video, 0, 0);
  capturedClassroom = canvas.toDataURL('image/jpeg', 0.8);

  btn.disabled = true;
  btn.textContent = 'Verifying classroom...';
  errEl.style.display = 'none';
  sub.textContent = 'Checking classroom with AI...';

  try {
    const aiRes  = await fetch('/api/ai_verify.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type: 'environment', image: capturedClassroom })
    });
    const aiData = await aiRes.json();

    if (!aiData.success) {
      // Show preview and let rep decide
      showClassPreview();
      faceMatchScore = 60; // Classroom failed — send to rep
      sub.textContent = 'Warning: ' + (aiData.message || 'Could not verify classroom — rep will review.');
      sub.style.color = 'var(--warning)';
      document.getElementById('class-submit-btn').style.display = 'flex';
      document.getElementById('retake-class-btn').style.display = 'flex';
      btn.style.display = 'none';
      stopClassCamera();
      return;
    }

    // Classroom verified — keep high score, auto-approve
    faceMatchScore = 88;
    livenessOk     = true;
    showClassPreview();
    sub.textContent = 'Classroom verified!';
    sub.style.color = 'var(--success)';
    document.getElementById('class-submit-btn').style.display = 'flex';
    document.getElementById('retake-class-btn').style.display = 'flex';
    btn.style.display = 'none';
    stopClassCamera();
  } catch(e) {
    errEl.textContent = 'Verification error. Try again.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Try Again';
    capturedClassroom = null;
  }
}

function showClassPreview() {
  const preview = document.getElementById('class-preview');
  preview.src = capturedClassroom;
  preview.style.display = 'block';
  document.getElementById('class-video').style.display = 'none';
}

function retakeClass() {
  capturedClassroom = null;
  document.getElementById('class-preview').style.display = 'none';
  document.getElementById('class-video').style.display = 'block';
  document.getElementById('class-capture-btn').style.display = 'flex';
  document.getElementById('class-capture-btn').disabled = false;
  document.getElementById('class-capture-btn').textContent = 'Capture Classroom';
  document.getElementById('class-capture-btn').onclick = captureClassroom;
  document.getElementById('retake-class-btn').style.display = 'none';
  document.getElementById('class-submit-btn').style.display = 'none';
  document.getElementById('class-error').style.display = 'none';
  document.getElementById('step-class-sub').style.color = '';
  document.getElementById('step-class-sub').textContent = 'Point camera at the classroom and capture';
  startClassCamera();
}

async function submitAttendance(fromClassStep = false) {
  const btn   = fromClassStep ? document.getElementById('class-submit-btn') : document.getElementById('submit-btn');
  const errEl = fromClassStep ? document.getElementById('class-error') : document.getElementById('submit-error');
  if (!capturedSelfie) { if(errEl){errEl.textContent='Please take a selfie first.';errEl.style.display='block';} return; }
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
  errEl.style.display = 'none';
  try {
    const res  = await fetch('/api/mark_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
          session_id:       SESSION_ID,
          selfie:           capturedSelfie,
          classroom:        capturedClassroom,
          face_match_score: faceMatchScore,
          ai_confidence:    faceMatchScore,
          liveness_pass:    livenessOk,
        })
    });
    const data = await res.json();
    if (data.success) {
      const displayEl = document.getElementById('step-class-section') || document.getElementById('step-selfie-section');
      displayEl.innerHTML = `
        <div class="pending-card" style="border-color:${data.auto_approved ? 'rgba(76,175,130,.3)' : 'rgba(201,168,76,.3)'}">
          <div class="pending-icon">${data.auto_approved ? '✅' : '⏳'}</div>
          <div class="pending-title" style="color:${data.auto_approved ? 'var(--success)' : 'var(--gold)'}">
            ${data.auto_approved ? 'Verified & Marked ' + (data.status === 'late' ? 'Late' : 'Present') + '!' : 'Submitted — Awaiting Review'}
          </div>
          <div class="pending-sub">
            ${data.auto_approved ? 'Face verified by AI. No rep approval needed.' : 'Your selfie has been submitted for rep review.'}
          </div>
        </div>`;
    } else {
      errEl.textContent = data.message || 'Submission failed.';
      errEl.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Submit →';
    }
  } catch(e) {
    errEl.textContent = 'Connection error. Try again.';
    errEl.style.display = 'block';
    btn.disabled = false; btn.textContent = 'Submit →';
  }
}

// Mobile handled in head script

</script>

<script>
// ── STUDENT CA SCORES ──
const CA_API_STU = (window.location.origin) + '/api/ca_scores.php';

async function loadMyCA() {
  const semId = document.getElementById('ca-sem-filter')?.value || '';
  const url   = CA_API_STU + '?type=student' + (semId ? '&semester_id=' + semId : '');
  const r     = await fetch(url);
  const d     = await r.json();
  const tbody = document.getElementById('ca-scores-body');
  const cards = document.getElementById('ca-summary-cards');

  if (!d.success || !d.scores.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="tbl-empty">No CA scores uploaded yet. Your lecturer will upload scores after assessments.</td></tr>';
    if (cards) cards.innerHTML = '';
    return;
  }

  // Build table
  tbody.innerHTML = d.scores.map(s => {
    const pct  = Math.round(s.score / s.max_score * 100);
    const pill = pct >= 50 ? 'pill-green' : 'pill-red';
    return `<tr>
      <td><div class="fw-500">${s.course_code}</div><div class="t-muted-75">${s.course_name}</div></td>
      <td><span class="pill pill-steel">${s.ca_type}</span></td>
      <td class="fw-600 t-gold">${s.score}</td>
      <td class="t-muted">${s.max_score}</td>
      <td><span class="pill ${pill}">${pct}%</span></td>
      <td class="t-muted-78">${s.remarks || '—'}</td>
      <td class="t-muted-75">${s.uploaded_at?.substring(0,10) || '—'}</td>
    </tr>`;
  }).join('');

  // Summary cards — group by course
  if (cards) {
    const courses = {};
    d.scores.forEach(s => {
      if (!courses[s.course_code]) courses[s.course_code] = { name: s.course_name, total: 0, max: 0, count: 0 };
      courses[s.course_code].total += parseFloat(s.score);
      courses[s.course_code].max   += parseFloat(s.max_score);
      courses[s.course_code].count++;
    });
    cards.innerHTML = Object.entries(courses).map(([code, c]) => {
      const avg = Math.round(c.total / c.max * 100);
      const col = avg >= 75 ? 'var(--success)' : avg >= 50 ? 'var(--gold)' : 'var(--danger)';
      return `<div class="card card-dynamic-top">
        <div class="label-muted-upper">${code}</div>
        <div class="fw-700 t-gold">${avg}%</div>
        <div class="t-muted-72">${c.count} assessment${c.count>1?'s':''} · ${c.total}/${c.max}</div>
      </div>`;
    }).join('');
  }
}

// Auto-load when CA section is opened
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.nav-item').forEach(function(n){
    if(n.getAttribute('onclick') && n.getAttribute('onclick').indexOf("'ca'") !== -1){
      n.addEventListener('click', function(){ setTimeout(loadMyCA, 150); });
    }
  });
});
</script>

<!-- MY CA SCORES -->
    <div class="page-section" id="sec-ca">
      <div class="section-title">My <span>CA Scores</span></div>
      <div class="grid-auto-140" id="ca-summary-cards"></div>
      <div class="card">
        <div class="card-head">
          <div class="card-head-title">Assessment Scores</div>
          <button class="btn btn-ghost btn-sm" onclick="loadMyCA()">Refresh</button>
        </div>
        <div class="card-body" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
          <table class="data-table"><thead><tr><th>Course</th><th>Type</th><th>Score</th><th>Max</th><th>%</th><th class="hide-mobile">Remarks</th><th class="hide-mobile">Date</th></tr></thead>
          <tbody id="ca-scores-body"><tr><td colspan="7" class="tbl-empty">No CA scores uploaded yet. Your lecturer will upload scores after assessments.</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>


    <!-- MY CA SCORES -->

<?php require_once '../../includes/toast.php'; ?>

<style>
.ca-cards-wrap:empty{display:none!important}
#ca-summary-cards:empty,#rep-ca-cards,#admin-ca-cards{min-height:0!important}
#ca-summary-cards:empty,#rep-ca-cards:empty,#admin-ca-cards:empty{display:none!important}
</style>
</body>
</html>
