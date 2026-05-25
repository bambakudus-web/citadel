<?php
require_once '../../includes/security.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/guard.php';
requireRole('admin');
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verifyCsrf();
    $action = $_POST["action"] ?? "";
    if ($action === "reset_system") {
        $pdo->exec("DELETE FROM attendance");
        $pdo->exec("DELETE FROM sessions");
        $pdo->exec("DELETE FROM announcements");
        header("Location: dashboard.php?reset=1"); exit;
    }
    if ($action === "reset_device") {
        $uid = (int)($_POST["user_id"] ?? 0);
        if ($uid) $pdo->prepare("UPDATE users SET device_fingerprint=NULL WHERE id=?")->execute([$uid]);
        header("Location: dashboard.php"); exit;
    }
    if ($action === "ban_device" || $action === "unban_device") {
        $index = trim($_POST["index_no"] ?? "");
        if ($index) {
            $u = $pdo->prepare("SELECT id FROM users WHERE index_no=?");
            $u->execute([$index]); $u = $u->fetch();
            if ($u) {
                if ($action === "ban_device") {
                    $pdo->prepare("UPDATE users SET device_fingerprint='BANNED' WHERE id=? AND institution_id=?")->execute([$u["id"], $inst_id]);
                    audit('BAN_DEVICE','user',$u["id"]);
                } else {
                    $pdo->prepare("UPDATE users SET device_fingerprint=NULL WHERE id=? AND institution_id=?")->execute([$u["id"], $inst_id]);
                    audit('UNBAN_DEVICE','user',$u["id"]);
                }
            }
        }
        header("Location: dashboard.php"); exit;
    }
    if ($action === "unlock_account") {
        $uid = (int)($_POST["user_id"] ?? 0);
        if ($uid) {
            $pdo->prepare("UPDATE users SET is_locked=0, login_attempts=0 WHERE id=? AND institution_id=?")->execute([$uid, $inst_id]);
            audit('UNLOCK_ACCOUNT', 'user', $uid);
        }
        header("Location: dashboard.php"); exit;
    }
}

$user = currentUser();

// ── Stats ──
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

$totalStudents   = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND institution_id=$inst_id")->fetchColumn();
$totalLecturers  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='lecturer' AND institution_id=$inst_id")->fetchColumn();
$totalSessions   = $pdo->query("SELECT COUNT(*) FROM sessions s JOIN users u ON u.id=s.lecturer_id WHERE u.institution_id=$inst_id")->fetchColumn();
$todayAttendance = $pdo->query("SELECT COUNT(*) FROM attendance a JOIN sessions s ON s.id=a.session_id JOIN users u ON u.id=s.lecturer_id WHERE DATE(a.timestamp)=CURDATE() AND u.institution_id=$inst_id")->fetchColumn();

// ── Today's timetable ──
$today = date('l');
$activeSemId = $activeSemester["id"] ?? 0;
$todayClasses = $pdo->prepare("SELECT t.*, u.full_name as lecturer_name FROM timetable t JOIN users u ON t.lecturer_id=u.id WHERE t.day_of_week=? AND u.institution_id=$inst_id AND (t.semester_id=? OR t.semester_id IS NULL) ORDER BY t.start_time");
$todayClasses->execute([$today, $activeSemId]);
$todayClasses = $todayClasses->fetchAll();

// ── Recent activity ──
$recentActivity = $pdo->query("
    SELECT a.timestamp, u.full_name, u.index_no, s.course_code, a.status
    FROM attendance a JOIN users u ON a.student_id=u.id JOIN sessions s ON a.session_id=s.id WHERE u.institution_id=$inst_id
    ORDER BY a.timestamp DESC LIMIT 10
")->fetchAll();

// ── Students ──
$students = $pdo->query("
    SELECT u.*, COUNT(DISTINCT s.id) as total_sessions,
    SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) as attended,
    ROUND(SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT s.id),0) * 100) as attendance_pct
    FROM users u LEFT JOIN attendance a ON u.id=a.student_id LEFT JOIN sessions s ON a.session_id=s.id WHERE u.institution_id=$inst_id AND u.role='student' AND u.institution_id=$inst_id GROUP BY u.id ORDER BY attendance_pct ASC, u.full_name
")->fetchAll();

// ── Sessions ──
$sessions = $pdo->query("
    SELECT s.*, u.full_name as lecturer_name, COUNT(a.id) as attendance_count
    FROM sessions s JOIN users u ON s.lecturer_id=u.id LEFT JOIN attendance a ON s.id=a.session_id
    WHERE u.institution_id=$inst_id
    GROUP BY s.id ORDER BY s.created_at DESC LIMIT 20
")->fetchAll();

$activeSession = $pdo->query("SELECT s.* FROM sessions s JOIN users u ON u.id=s.lecturer_id WHERE s.active_status=1 AND u.institution_id=$inst_id ORDER BY s.start_time DESC LIMIT 1")->fetch();
$pendingCount = 0;
if ($activeSession) {
    $pc = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE session_id=? AND status='pending'");
    $pc->execute([$activeSession['id']]); $pendingCount = $pc->fetchColumn();
}

$sessionHistory = $pdo->query("
    SELECT s.*, COUNT(DISTINCT CASE WHEN a.status IN ('present','late') THEN a.student_id END) as present_count,
    COUNT(DISTINCT CASE WHEN a.status='absent' THEN a.student_id END) as absent_count,
    COUNT(DISTINCT CASE WHEN a.status='late' THEN a.student_id END) as late_count
    FROM sessions s
    JOIN users u2 ON u2.id=s.lecturer_id
    LEFT JOIN attendance a ON s.id=a.session_id
    WHERE s.active_status=0 AND u2.institution_id=$inst_id GROUP BY s.id ORDER BY s.start_time DESC LIMIT 30
")->fetchAll();

$announcements = $pdo->query("SELECT a.message, a.created_at, u.full_name FROM announcements a JOIN users u ON u.id=a.rep_id WHERE u.institution_id=$inst_id ORDER BY a.created_at DESC LIMIT 20")->fetchAll();

$liveAttendance = [];
if ($activeSession) {
    $la = $pdo->prepare("SELECT u.full_name, u.index_no, a.status, a.minutes_late, a.timestamp FROM attendance a JOIN users u ON a.student_id=u.id WHERE a.session_id=? AND a.status IN ('present','late') ORDER BY a.timestamp DESC");
    $la->execute([$activeSession['id']]); $liveAttendance = $la->fetchAll();
}

// ── NEW: Semester & Course data ──
$activeSemester = $pdo->query("SELECT * FROM semesters WHERE is_active=1 AND institution_id=$inst_id LIMIT 1")->fetch();
$activeSemId    = $activeSemester['id'] ?? null;

$allSemesters = $pdo->query("
    SELECT s.*, COUNT(DISTINCT c.id) AS course_count
    FROM semesters s LEFT JOIN courses c ON c.semester_id=s.id WHERE s.institution_id=$inst_id
    GROUP BY s.id ORDER BY s.academic_year DESC, s.semester_no DESC
")->fetchAll();

$activeCourses = [];
if ($activeSemId) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name AS lecturer_name, u.id AS lecturer_id,
               COUNT(DISTINCT ce.student_id) AS enrolled_count
        FROM courses c
        LEFT JOIN course_assignments ca ON ca.course_id=c.id AND ca.semester_id=c.semester_id
        LEFT JOIN users u ON u.id=ca.lecturer_id
        LEFT JOIN course_enrollments ce ON ce.course_id=c.id AND ce.status='active'
        WHERE c.semester_id=? GROUP BY c.id ORDER BY c.code ASC
    ");
    $stmt->execute([$activeSemId]);
    $activeCourses = $stmt->fetchAll();
}

$allLecturers = $pdo->query("
    SELECT u.*, d.name AS department_name, COUNT(DISTINCT ca.course_id) AS assigned_courses
    FROM users u LEFT JOIN departments d ON d.id=u.department_id
    LEFT JOIN course_assignments ca ON ca.lecturer_id=u.id
    WHERE u.role='lecturer' AND u.institution_id=$inst_id GROUP BY u.id ORDER BY u.full_name ASC
")->fetchAll();

$allPrograms    = $pdo->query("SELECT p.* FROM programs p JOIN departments d ON d.id=p.department_id WHERE d.institution_id=$inst_id ORDER BY p.name")->fetchAll();
$allDepartments = $pdo->query("SELECT * FROM departments WHERE institution_id=$inst_id ORDER BY name")->fetchAll();

$auditLog = $pdo->query("
    SELECT al.*, u.full_name AS actor_name
    FROM audit_log al JOIN users u ON u.id=al.actor_id
    WHERE u.institution_id=$inst_id
    ORDER BY al.created_at DESC LIMIT 50
")->fetchAll();

$devs = $pdo->query("SELECT id, full_name, index_no, device_fingerprint, is_locked, login_attempts FROM users WHERE role IN ('student','rep','lecturer') AND institution_id=$inst_id ORDER BY is_locked DESC, full_name")->fetchAll();
$lockedUsers = $pdo->query("SELECT id, full_name, index_no, role, login_attempts FROM users WHERE is_locked=1 AND institution_id=$inst_id ORDER BY full_name")->fetchAll();

// ── Code gen ──
function generateCode(string $secret, int $window): string {
    $hash = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}
$currentCode   = $activeSession ? generateCode($activeSession['secret_key'], (int)floor(time() / 120)) : '';
$timeRemaining = 120 - (time() % 120);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Boss Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050;--sidebar-w:240px}
body.light{--bg:#f0f2f5;--surface:#ffffff;--surface2:#f5f7fa;--border:#dde1e9;--text:#1a2035;--muted:#5a6a7d;--gold:#8a6520;--gold-dim:#c9a84c;--steel:#2a4f8a}
body.light::before{display:none}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 60% 40% at 20% 0%,rgba(74,111,165,.12) 0%,transparent 60%),radial-gradient(ellipse 40% 30% at 90% 90%,rgba(201,168,76,.06) 0%,transparent 60%);pointer-events:none}
.layout{display:flex;min-height:100vh;position:relative;z-index:1}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;position:fixed;top:0;left:0;bottom:0;z-index:500!important;transition:transform .3s ease}
.sidebar-brand{padding:1.6rem 1.4rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.8rem}
.sidebar-brand svg{width:32px;height:32px;flex-shrink:0}
.sidebar-brand-text .name{font-family:'Cinzel',serif;font-size:1rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
.sidebar-brand-text .role{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
.sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;min-height:0}
.nav-section{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.4rem .4rem}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.4rem;color:var(--muted);text-decoration:none;font-size:.85rem;cursor:pointer;border-left:2px solid transparent;transition:all .2s}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-item.active{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.06)}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-footer{padding:0.75rem 1rem max(1rem,env(safe-area-inset-bottom));border-top:1px solid var(--border);font-size:.75rem;color:var(--muted);margin:0 0.6rem 0.6rem;background:var(--surface2);border-radius:6px;border:1px solid var(--border);display:flex;flex-direction:column;gap:0.5rem}
.sidebar-footer a{color:var(--danger);text-decoration:none;font-size:.8rem}
.sidebar-footer strong{color:var(--text);font-size:.78rem}
@media(max-width:768px){.charts-grid{grid-template-columns:1fr!important}}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--gold);letter-spacing:.1em}
.topbar-right{display:flex;align-items:center;gap:1rem}
.badge-admin{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:rgba(201,168,76,.12);border:1px solid var(--gold-dim);color:var(--gold);padding:.25rem .7rem;border-radius:2px}
.content{padding:2rem;flex:1}
.page-section{display:none}
.page-section.active{display:block;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.8rem;flex-wrap:wrap;gap:1rem}
.section-title{font-family:'Cinzel',serif;font-size:1.1rem;color:var(--text);letter-spacing:.08em}
.section-title span{color:var(--gold)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.4rem 1.6rem;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.gold::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.stat-card.steel::before{background:linear-gradient(90deg,transparent,var(--steel),transparent)}
.stat-card.green::before{background:linear-gradient(90deg,transparent,var(--success),transparent)}
.stat-card.red::before{background:linear-gradient(90deg,transparent,var(--danger),transparent)}
.stat-label{font-size:.65rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
.stat-value{font-size:2rem;font-weight:600;color:var(--text);line-height:1}
.stat-sub{font-size:.72rem;color:var(--muted);margin-top:.4rem}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.card-head{padding:1rem 1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-head-title{font-size:.75rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
.card-body{padding:1.2rem 1.4rem}
.data-table{width:100%;border-collapse:collapse;font-size:.83rem}
.data-table th{text-align:left;font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);padding:.6rem .8rem;border-bottom:1px solid var(--border)}
.data-table td{padding:.65rem .8rem;border-bottom:1px solid rgba(30,42,53,.5);color:var(--text)}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:rgba(255,255,255,.02)}
.pill{display:inline-block;font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;padding:.2rem .6rem;border-radius:2px}
.pill-green{background:rgba(76,175,130,.12);color:var(--success);border:1px solid rgba(76,175,130,.3)}
.pill-red{background:rgba(224,92,92,.12);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
.pill-gold{background:rgba(201,168,76,.12);color:var(--gold);border:1px solid rgba(201,168,76,.3)}
.pill-steel{background:rgba(74,111,165,.12);color:var(--steel);border:1px solid rgba(74,111,165,.3)}
.tt-grid{display:flex;flex-direction:column;gap:.5rem}
.tt-item{display:flex;align-items:center;gap:1rem;background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--steel);padding:.7rem 1rem;border-radius:2px}
.tt-time{font-size:.75rem;color:var(--gold);min-width:100px;font-weight:500}
.tt-course{flex:1}
.tt-course-code{font-size:.7rem;color:var(--muted);letter-spacing:.1em}
.tt-course-name{font-size:.85rem;color:var(--text)}
.tt-room{font-size:.72rem;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;font-family:'DM Sans',sans-serif;font-size:.78rem;letter-spacing:.08em;border:none;border-radius:2px;cursor:pointer;text-decoration:none;transition:opacity .2s,transform .15s}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.btn-gold{background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-weight:600}
.btn-steel{background:var(--steel-dim);color:var(--steel);border:1px solid var(--steel-dim)}
.btn-danger{background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-sm{padding:.3rem .7rem;font-size:.72rem}
.filter-bar{display:flex;gap:.8rem;margin-bottom:1.2rem;flex-wrap:wrap}
.filter-bar input,.filter-bar select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:.5rem .9rem;font-family:'DM Sans',sans-serif;font-size:.83rem;border-radius:2px;outline:none;flex:1;min-width:160px}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--steel)}
.filter-bar input::placeholder{color:var(--muted)}
.modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(6,9,16,.85);display:none;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:2px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;animation:fadeIn .25s ease}
.modal-head{padding:1.2rem 1.6rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-family:'Cinzel',serif;font-size:.9rem;color:var(--gold);letter-spacing:.1em}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem;line-height:1}
.modal-body{padding:1.6rem}
.form-field{margin-bottom:1.1rem}
.form-field label{display:block;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.form-field input,.form-field select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.65rem .9rem;font-family:'DM Sans',sans-serif;font-size:.88rem;border-radius:2px;outline:none;transition:border-color .2s}
.form-field input:focus,.form-field select:focus{border-color:var(--steel)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.override-banner{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.2);border-left:3px solid var(--danger);padding:.8rem 1.2rem;border-radius:2px;margin-bottom:1.5rem;font-size:.8rem;color:var(--muted)}
.override-banner strong{color:var(--danger)}
.audit-item{display:flex;gap:1rem;align-items:flex-start;padding:.7rem 0;border-bottom:1px solid rgba(30,42,53,.5);font-size:.8rem}
.audit-item:last-child{border-bottom:none}
.audit-time{color:var(--muted);min-width:130px;font-size:.72rem}
.audit-text{color:var(--text)}
.audit-text em{color:var(--gold);font-style:normal}

/* Sidebar overlay background */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}

input,select,textarea{font-size:16px!important}

input,select,textarea{font-size:16px!important}

/* ═══ MOBILE - CLEAN ═══ */
@media(max-width:768px){
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
  .topbar{padding:.65rem .9rem!important;gap:.5rem}
  .hide-mobile{display:none!important}
  .topbar-right{gap:.5rem!important}
  #menu-btn{
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    width:36px!important;height:36px!important;
    min-width:36px!important;
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
@media(min-width:769px){
  #menu-btn{display:none!important}
}
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:unset!important}}

#sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.7);z-index:400;
  backdrop-filter:blur(2px)
}
#sidebar-overlay.show{display:block}

/* TABLE SCROLL FIX */
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
@media(max-width:768px){
  .data-table,.tbl{font-size:.7rem!important;min-width:0!important;width:100%}
  .data-table th,.data-table td,.tbl th,.tbl td{
    padding:.35rem .4rem!important;
    white-space:nowrap!important;
    font-size:.7rem!important
  }
  /* Hide less important columns on mobile */
  .hide-col-mobile{display:none!important}
  /* Wrap all card tables */
  .card-body{overflow-x:auto!important;-webkit-overflow-scrolling:touch!important}
  /* Prevent body scroll caused by tables */
  body{overflow-x:hidden!important}
  .content{overflow-x:hidden!important}
  /* Stats grid */
  .stats-grid{grid-template-columns:repeat(2,1fr)!important;gap:.5rem!important}
  .stat-card{padding:.75rem!important}
  .stat-value,.stat-num{font-size:1.3rem!important}
  .stat-label,.stat-sub{font-size:.62rem!important}
  /* Two col */
  .two-col,.wrap2{grid-template-columns:1fr!important;gap:.6rem!important}
  /* Forms */
  .form-row{grid-template-columns:1fr!important}
  /* Topbar */
  .topbar{padding:.6rem .8rem!important}
  .topbar-title{font-size:.8rem!important}
  /* Modals */
  .modal,.modal-box{width:95vw!important;max-height:88vh!important;overflow-y:auto!important}
  /* Pills */
  .pill,.badge{font-size:.6rem!important;padding:.1rem .35rem!important}
  /* Section header */
  .section-header{flex-wrap:wrap!important;gap:.4rem!important}
  .section-title{font-size:.88rem!important}
  /* Buttons */
  .btn-sm{font-size:.68rem!important;padding:.3rem .5rem!important}
}






</style>
<script src="/assets/chart.min.js">
// Mobile handled in head script

function showSection(name,el){
  document.querySelectorAll('.page-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  var sec=document.getElementById('sec-'+name);
  if(sec)sec.classList.add('active');
  var title=document.getElementById('page-title');
  if(title)title.textContent=name.charAt(0).toUpperCase()+name.slice(1);
  if(el)el.classList.add('active');
  // Close sidebar on mobile
  var sb=document.getElementById('sidebar');
  if(sb)sb.classList.remove('open');
  var ov=document.getElementById('sidebar-overlay');
  if(ov)ov.style.display='none';
  toggleSidebar && document.getElementById("sidebar").classList.contains("open") && toggleSidebar();
}
</script>
<script>

}

</script>

<script>
const BASE_URL = window.location.origin;
const API = BASE_URL + '/api';

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
  var title=document.getElementById('page-title');
  if(title)title.textContent=name.charAt(0).toUpperCase()+name.slice(1);
  if(el)el.classList.add('active');
  var sb=document.getElementById('sidebar');
  if(sb&&sb.classList.contains('open'))toggleSidebar();
}

document.addEventListener('DOMContentLoaded',function(){
  var btn=document.getElementById('menu-btn')||document.getElementById('menu-toggle');
  if(btn)btn.onclick=function(e){e.stopPropagation();toggleSidebar();};
  var ov=document.getElementById('sidebar-overlay');
  if(ov)ov.onclick=function(){toggleSidebar();};
});
</script>

    <style>
        .sidebar-user {
            padding: 0.75rem 1rem max(1rem, env(safe-area-inset-bottom)) !important;
            margin: 0 0.6rem 0 !important;
            background: var(--surface2) !important;
            border: 1px solid var(--border) !important;
            border-radius: 6px !important;
            margin-bottom: 0.6rem !important;
        }
        .u-name {
            font-size: 0.78rem !important;
            font-weight: 600 !important;
            color: var(--text) !important;
            margin-bottom: 0.15rem !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .u-index {
            font-size: 0.6rem !important;
            color: var(--muted) !important;
            letter-spacing: 0.05em !important;
            margin-bottom: 0.6rem !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .sidebar-user-actions {
            display: flex !important;
            gap: 0.4rem !important;
            margin-top: 0.1rem !important;
        }
        .sidebar-user-actions a {
            flex: 1 !important;
            text-align: center !important;
            font-size: 0.65rem !important;
            letter-spacing: 0.06em !important;
            text-transform: uppercase !important;
            padding: 0.35rem 0.4rem !important;
            border-radius: 4px !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            display: block !important;
        }
        .sidebar-user-actions .btn-pwd {
            background: rgba(255,255,255,0.05) !important;
            border: 1px solid var(--border) !important;
            color: var(--muted) !important;
        }
        .sidebar-user-actions .btn-out {
            background: rgba(224,92,92,0.12) !important;
            border: 1px solid rgba(224,92,92,0.3) !important;
            color: var(--danger) !important;
        }
@media(max-width:768px){.sidebar{overflow:hidden!important;display:flex!important;flex-direction:column!important}.sidebar-nav,.sb-nav{flex:1 1 0!important;overflow-y:auto!important;overflow-x:hidden!important;min-height:0!important}.sidebar-user,.sidebar-footer,.sb-foot{flex-shrink:0!important;overflow:visible!important}}
</style>
</head>

<body>
<div class="layout">

<!-- ── Sidebar ── -->
<div id="sidebar-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:400;backdrop-filter:blur(2px)"></div>
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
    <a class="nav-item active" onclick="showSection('overview',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Overview
    </a>
    <a class="nav-item" onclick="showSection('timetable',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Timetable
    </a>
    <div class="nav-section">Management</div>
    <a class="nav-item" onclick="showSection('semesters',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="4" x2="9" y2="9"/><line x1="15" y1="4" x2="15" y2="9"/></svg>Semesters
    </a>
    <a class="nav-item" onclick="showSection('programs',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>Programs
    </a>
    <a class="nav-item" onclick="showSection('courses',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>Courses
    </a>
    <a class="nav-item" onclick="showSection('lecturers',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Lecturers
    </a>
    <a class="nav-item" onclick="showSection('students',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Students
    </a>
    <a class="nav-item" onclick="showSection('sessions',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Sessions
    </a>
    <a class="nav-item" onclick="showSection('attendance',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Attendance
    </a>
    <a class="nav-item" onclick="showSection('override',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Override
    </a>
    <div class="nav-section">System</div>
    <a class="nav-item" onclick="showSection('audit',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Audit Log
    </a>
    <a class="nav-item" onclick="showSection('approvals',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Approvals<span id="pending-badge" style="background:var(--warning);color:#060910;font-size:.6rem;font-weight:700;padding:.15rem .45rem;border-radius:2px;margin-left:.4rem;display:none">0</span>
    </a>
    <a class="nav-item" onclick="showSection('history',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/></svg>Session History
    </a>
    <a class="nav-item" onclick="showSection('announce',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Announcements
    </a>
    <a class="nav-item" onclick="showSection('live',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <?= $activeSession ? '🟢 Live Session' : 'Live Session' ?>
    </a>
    <a class="nav-item" onclick="showSection('locked',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Locked Accounts<?php if(!empty($lockedUsers)): ?> <span style="background:var(--danger);color:#fff;font-size:.6rem;padding:.1rem .35rem;border-radius:2px;margin-left:auto"><?= count($lockedUsers) ?></span><?php endif ?>
    </a>
    <a class="nav-item" onclick="showSection('devices',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>Device Control
    </a>
  </nav>
  <div class="sidebar-footer">
    Logged in as <strong style="color:var(--text)"><?= htmlspecialchars($user['full_name'] ?? 'Admin') ?></strong><br>
    <div class="sidebar-user-actions"><a href="../../change_password.php" class="btn-pwd">🔑 Password</a><a href="../../logout.php" class="btn-out">Sign Out</a></div>
  </div>
</aside>

<!-- ── Main ── -->
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:1rem">
      <button onclick="document.getElementById('sidebar').classList.toggle('open');
    var ov=document.getElementById('sidebar-overlay');
    if(ov)ov.classList.toggle('open',document.getElementById('sidebar').classList.contains('open'))" style="background:none;border:none;color:var(--muted);cursor:pointer" id="menu-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title" id="page-title">OVERVIEW</div>
    </div>
    <div class="topbar-right">
      <?php if($activeSemester): ?>
      <span class="hide-mobile" style="font-size:.7rem;color:var(--gold);border:1px solid var(--gold-dim);padding:.2rem .6rem;border-radius:2px;white-space:nowrap"><?= htmlspecialchars($activeSemester['name']) ?></span>
      <?php endif; ?>
      <span class="hide-mobile" style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?= date('l, d M Y') ?></span>
      <span class="badge-admin">Boss</span>
      <button id="theme-btn" onclick="toggleTheme()" style="background:none;border:1px solid var(--border);color:var(--muted);cursor:pointer;padding:.25rem .6rem;border-radius:2px;font-size:.75rem">🌙</button>
    </div>
  </div>

  <div class="content">

    <!-- ══ OVERVIEW ══ -->
    <div class="page-section active" id="sec-overview">
      <div class="stats-grid">
        <div class="stat-card gold"><div class="stat-label">Total Students</div><div class="stat-value"><?= $totalStudents ?></div><div class="stat-sub">Active in system</div></div>
        <div class="stat-card steel"><div class="stat-label">Lecturers</div><div class="stat-value"><?= $totalLecturers ?></div><div class="stat-sub">Active faculty</div></div>
        <div class="stat-card green"><div class="stat-label">Total Sessions</div><div class="stat-value"><?= $totalSessions ?></div><div class="stat-sub">All time</div></div>
        <div class="stat-card red"><div class="stat-label">Today's Records</div><div class="stat-value"><?= $todayAttendance ?></div><div class="stat-sub"><?= date('d M Y') ?></div></div>
      </div>
      <div class="two-col">
        <div class="card">
          <div class="card-head"><div class="card-head-title">Today — <?= $today ?></div><span class="pill pill-steel"><?= count($todayClasses) ?> classes</span></div>
          <div class="card-body">
            <?php if(empty($todayClasses)): ?><p style="color:var(--muted);font-size:.83rem">No classes scheduled today.</p>
            <?php else: ?><div class="tt-grid"><?php foreach($todayClasses as $c): ?>
              <div class="tt-item">
                <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
                <div class="tt-course">
                  <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                  <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                  <div class="tt-room">📍 <?= htmlspecialchars($c['room']) ?> · <?= htmlspecialchars($c['lecturer_name']) ?></div>
                </div>
              </div>
            <?php endforeach; ?></div><?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-head-title">Recent Activity</div></div>
          <div class="card-body" style="padding:0;overflow-x:auto">
            <table class="data-table"><thead><tr><th>Student</th><th>Course</th><th>Status</th><th>Time</th></tr></thead><tbody>
            <?php if(empty($recentActivity)): ?><tr><td colspan="4" style="color:var(--muted)">No activity yet.</td></tr>
            <?php else: foreach($recentActivity as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['full_name']) ?><br><small style="color:var(--muted)"><?= $r['index_no'] ?></small></td>
                <td><?= htmlspecialchars($r['course_code']) ?></td>
                <td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':'red') ?>"><?= $r['status'] ?></span></td>
                <td style="color:var(--muted);font-size:.75rem"><?= date('H:i',strtotime($r['timestamp'])) ?></td>
              </tr>
            <?php endforeach; endif; ?></tbody></table>
          </div>
        </div>
      </div>
      <div class="card" style="margin-bottom:1.5rem" id="charts-card"><div class="card-head"><div class="card-head-title">Attendance <span>Analytics</span></div></div><div class="card-body"><div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem" class="charts-grid"><div><canvas id="chart-attendance-trend" height="200"></canvas></div><div><canvas id="chart-course-rates" height="200"></canvas></div></div></div></div>
    </div>

     <!-- ══ TIMETABLE ══ -->
    <div class="page-section" id="sec-timetable">
      <div class="section-header">
        <div class="section-title">Class <span>Timetable</span></div>
        <button class="btn btn-gold" onclick="openAddSlot()">+ Add Slot</button>
      </div>
      <?php
      $ttStmt = $pdo->prepare("
          SELECT t.*, u.full_name AS lecturer_name,
                 COALESCE(c.code, t.course_code) AS course_code,
                 COALESCE(c.name, t.course_name) AS course_name
          FROM timetable t
          JOIN users u ON u.id = t.lecturer_id
          LEFT JOIN courses c ON c.id = t.course_id
          WHERE u.institution_id=$inst_id
          AND (t.semester_id=? OR t.semester_id IS NULL OR ?=0)
          ORDER BY FIELD(t.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'), t.start_time
      ");
$ttStmt->execute([$activeSemId, $activeSemId]);
$ttAll = $ttStmt->fetchAll();
      $days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
      foreach ($days as $day):
        $dayCls = array_filter($ttAll, fn($c) => $c['day_of_week'] === $day);
        if (empty($dayCls)) continue;
      ?>
        <div style="margin-bottom:1.5rem">
          <div style="font-family:'Cinzel',serif;font-size:.8rem;color:var(--gold);letter-spacing:.15em;margin-bottom:.6rem;text-transform:uppercase"><?= $day ?></div>
          <div class="tt-grid"><?php foreach($dayCls as $c): ?>
            <div class="tt-item">
              <div class="tt-time"><?= substr($c['start_time'],0,5) ?> – <?= substr($c['end_time'],0,5) ?></div>
              <div class="tt-course" style="flex:1">
                <div class="tt-course-code"><?= htmlspecialchars($c['course_code']) ?></div>
                <div class="tt-course-name"><?= htmlspecialchars($c['course_name']) ?></div>
                <div class="tt-room">📍 <?= htmlspecialchars($c['room'] ?? '') ?> · <?= htmlspecialchars($c['lecturer_name'] ?? '—') ?></div>
              </div>
              <div style="display:flex;gap:.4rem;flex-shrink:0">
                <button class="btn btn-ghost btn-sm" onclick="editSlot(<?= $c['id'] ?>,'<?= $c['day_of_week'] ?>','<?= substr($c['start_time'],0,5) ?>','<?= substr($c['end_time'],0,5) ?>','<?= htmlspecialchars(addslashes($c['course_code'])) ?>','<?= htmlspecialchars(addslashes($c['course_name'])) ?>','<?= htmlspecialchars(addslashes($c['room'] ?? '')) ?>',<?= $c['lecturer_id'] ?? 'null' ?>)">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteSlot(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['course_code'])) ?>')">Del</button>
              </div>
            </div>
          <?php endforeach; ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($ttAll)): ?>
        <div class="card"><div class="card-body" style="color:var(--muted)">No timetable slots yet. Click "+ Add Slot" to get started.</div></div>
      <?php endif; ?>
    </div>

    <!-- ══ SEMESTERS ══ -->
    <div class="page-section" id="sec-semesters">
      <div class="section-header">
        <div class="section-title">Semester <span>Management</span></div>
        <button class="btn btn-gold" onclick="openAddSemester()">+ New Semester</button>
      </div>
      <?php if($activeSemester): ?>
      <div style="background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.25);border-radius:2px;padding:1rem 1.4rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <div>
          <div style="font-size:.7rem;color:var(--gold);letter-spacing:.15em;text-transform:uppercase">Active Semester</div>
          <div style="font-size:.95rem;color:var(--text);font-weight:500"><?= htmlspecialchars($activeSemester['name']) ?></div>
          <div style="font-size:.72rem;color:var(--muted)"><?= $activeSemester['start_date'] ?> → <?= $activeSemester['end_date'] ?></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Semester</th><th class="hide-mobile">Academic Year</th><th class="hide-mobile">Dates</th><th class="hide-mobile">Courses</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($allSemesters as $sem): ?>
        <tr>
          <td style="max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($sem['name']) ?></td>
          <td class="hide-mobile" style="color:var(--gold);font-size:.78rem"><?= $sem['academic_year'] ?> · Sem <?= $sem['semester_no'] ?></td>
          <td class="hide-mobile" style="color:var(--muted);font-size:.75rem"><?= $sem['start_date'] ?> → <?= $sem['end_date'] ?></td>
          <td class="hide-mobile"><span class="pill pill-steel"><?= $sem['course_count'] ?> courses</span></td>
          <td><?= $sem['is_active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td><div style="display:flex;flex-direction:column;gap:.3rem">
            <?php if(!$sem['is_active']): ?><button class="btn btn-gold btn-sm" onclick="setActiveSemester(<?= $sem['id'] ?>,'<?= htmlspecialchars(addslashes($sem['name'])) ?>')">Set Active</button><?php endif; ?>
            <button class="btn btn-ghost btn-sm" onclick="editSemester(<?= $sem['id'] ?>,'<?= htmlspecialchars(addslashes($sem['name'])) ?>','<?= $sem['academic_year'] ?>',<?= $sem['semester_no'] ?>,'<?= $sem['start_date'] ?>','<?= $sem['end_date'] ?>')">Edit</button>
          </div></td>
        </tr>
        <?php endforeach; if(empty($allSemesters)): ?><tr><td colspan="6" style="color:var(--muted)">No semesters yet.</td></tr><?php endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ PROGRAMS ══ -->
    <div class="page-section" id="sec-programs">
      <div class="section-header">
        <div class="section-title">Program <span>Management</span></div>
        <button class="btn btn-gold" onclick="openAddProgram()">+ Add Program</button>
      </div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Program Name</th><th>Code</th><th class="hide-mobile">Department</th><th class="hide-mobile">Duration</th><th class="hide-mobile">Students</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($allPrograms as $p): ?>
        <?php
          $pStudents = $pdo->prepare("SELECT COUNT(*) FROM users WHERE program_id=? AND role='student'");
          $pStudents->execute([$p['id']]); $pCount = $pStudents->fetchColumn();
          $pDept = $pdo->prepare("SELECT name FROM departments WHERE id=?");
          $pDept->execute([$p['department_id']]); $pDeptName = $pDept->fetchColumn();
        ?>
        <tr>
          <td style="font-weight:500"><?= htmlspecialchars($p['name']) ?></td>
          <td style="color:var(--gold);font-size:.82rem"><?= htmlspecialchars($p['code']) ?></td>
          <td class="hide-mobile" style="color:var(--muted)"><?= htmlspecialchars($pDeptName ?? '—') ?></td>
          <td class="hide-mobile" style="color:var(--muted)"><?= $p['duration_yrs'] ?> yr<?= $p['duration_yrs']>1?'s':'' ?></td>
          <td class="hide-mobile"><span class="pill pill-steel"><?= $pCount ?> students</span></td>
          <td style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" onclick="editProgram(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>','<?= htmlspecialchars(addslashes($p['code'])) ?>',<?= $p['department_id'] ?>,<?= $p['duration_yrs'] ?>)">Edit</button>
            <button class="btn btn-danger btn-sm" onclick="deleteProgram(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>')">Delete</button>
          </td>
        </tr>
        <?php endforeach; if(empty($allPrograms)): ?><tr><td colspan="6" style="color:var(--muted)">No programs yet. Add one to get started.</td></tr><?php endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ COURSES ══ -->
    <div class="page-section" id="sec-courses">
      <div class="section-header">
        <div class="section-title">Course <span>Management</span><?php if($activeSemester): ?><span style="font-size:.65rem;color:var(--muted);font-family:'DM Sans',sans-serif;letter-spacing:.1em;margin-left:.8rem"><?= htmlspecialchars($activeSemester['name']) ?></span><?php endif; ?></div>
        <button class="btn btn-gold" onclick="openAddCourse()">+ Add Course</button>
      </div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Code</th><th>Course Name</th><th class="hide-mobile">Lecturer</th><th class="hide-mobile">Enrolled</th><th class="hide-mobile">Credits</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($activeCourses as $c): ?>
        <tr>
          <td style="color:var(--gold);font-size:.82rem;font-weight:600"><?= htmlspecialchars($c['code']) ?></td>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td class="hide-mobile"><?= $c['lecturer_name'] ? htmlspecialchars($c['lecturer_name']) : '<span style="color:var(--muted);font-size:.75rem">— unassigned —</span>' ?></td>
          <td class="hide-mobile"><span class="pill pill-steel"><?= $c['enrolled_count'] ?> students</span></td>
          <td class="hide-mobile" style="color:var(--muted)"><?= $c['credit_hrs'] ?> cr</td>
          <td style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" onclick="editCourse(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['code'])) ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>',<?= $c['lecturer_id'] ?? 'null' ?>,<?= $c['credit_hrs'] ?>)">Edit</button>
            <button class="btn btn-danger btn-sm" onclick="deleteCourse(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['code'])) ?>')">Delete</button>
          </td>
        </tr>
        <?php endforeach; if(empty($activeCourses)): ?><tr><td colspan="6" style="color:var(--muted)">No courses for this semester yet.</td></tr><?php endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ LECTURERS ══ -->
    <div class="page-section" id="sec-lecturers">
      <div class="section-header">
        <div class="section-title">Lecturer <span>Registry</span></div>
        <button class="btn btn-gold" onclick="openAddLecturer()">+ Add Lecturer</button>
      </div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Full Name</th><th class="hide-mobile">Email</th><th class="hide-mobile">Department</th><th class="hide-mobile">Courses</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($allLecturers as $l): ?>
        <tr>
          <td><?= htmlspecialchars($l['full_name']) ?></td>
          <td class="hide-mobile" style="color:var(--muted);font-size:.78rem"><?= $l['email'] ?></td>
          <td class="hide-mobile"><?= htmlspecialchars($l['department_name'] ?? '—') ?></td>
          <td class="hide-mobile"><span class="pill pill-steel"><?= $l['assigned_courses'] ?> assigned</span></td>
          <td><?= $l['is_active'] ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-red">Inactive</span>' ?></td>
          <td style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" onclick="editLecturer(<?= $l['id'] ?>,'<?= htmlspecialchars(addslashes($l['full_name'])) ?>','<?= $l['email'] ?>',<?= $l['is_active'] ?>)">Edit</button>
            <button class="btn btn-ghost btn-sm" onclick="toggleUser(<?= $l['id'] ?>,<?= $l['is_active'] ?>)"><?= $l['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </td>
        </tr>
        <?php endforeach; if(empty($allLecturers)): ?><tr><td colspan="6" style="color:var(--muted)">No lecturers yet.</td></tr><?php endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ STUDENTS ══ -->
    <div class="page-section" id="sec-students">
      <div class="section-header">
        <div class="section-title">Student <span>Registry</span></div>
        <button class="btn btn-gold" onclick="openModal('modal-add-student')">+ Add Student</button>
        <button class="btn btn-ghost" onclick="openModal('modal-import-csv')">⬆ Import CSV</button>
      </div>
      <div class="filter-bar"><input type="text" id="student-search" placeholder="Search name or index number..." oninput="filterStudents()"></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table" id="student-table">
          <thead><tr><th>#</th><th>Index No.</th><th>Full Name</th><th class="hide-mobile">Email</th><th class="hide-mobile">Role</th><th class="hide-mobile">Attendance</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach($students as $i=>$s): ?>
            <tr data-name="<?= strtolower($s['full_name']) ?>" data-index="<?= $s['index_no'] ?>">
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td style="color:var(--gold);font-size:.8rem"><?= $s['index_no'] ?></td>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td class="hide-mobile" style="color:var(--muted);font-size:.78rem"><?= $s['email'] ?></td>
              <td class="hide-mobile"><span class="pill pill-<?= $s['role']==='rep'?'gold':'steel' ?>"><?= $s['role'] ?></span></td>
              <?php $pct=$s['attendance_pct']??0; $color=$pct>=75?'var(--success)':($pct>=50?'var(--warning)':'var(--danger)'); ?>
              <td class="hide-mobile"><div style="display:flex;align-items:center;gap:.5rem"><div style="width:60px;height:5px;background:var(--border);border-radius:3px"><div style="width:<?= min($pct,100) ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div></div><span style="font-size:.75rem;color:<?= $color ?>;font-weight:600"><?= $pct ?>%</span><?php if($pct<75&&$s['total_sessions']>3): ?><span title="Below 75%" style="color:var(--danger);font-size:.8rem">⚠</span><?php endif; ?></div></td>
              <td>
                <a href="../../api/attendance_certificate.php?student_id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" style="text-decoration:none">⬇ Cert</a>
                <button class="btn btn-ghost btn-sm" onclick="editStudent(<?= $s['id'] ?>,'<?= htmlspecialchars(addslashes($s['full_name'])) ?>','<?= $s['index_no'] ?>','<?= $s['email'] ?>','<?= $s['role'] ?>',<?= $s['is_locked'] ?>,'<?= $s['device_fingerprint']?'registered':'none' ?>')">Edit</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    </div>

    <!-- ══ SESSIONS ══ -->
    <div class="page-section" id="sec-sessions">
      <div class="section-header"><div class="section-title">Attendance <span>Sessions</span></div></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Course</th><th class="hide-mobile">Lecturer</th><th class="hide-mobile">Started</th><th>Status</th><th class="hide-mobile">Attendance</th><th>Actions</th></tr></thead><tbody>
        <?php if(empty($sessions)): ?><tr><td colspan="6" style="color:var(--muted)">No sessions yet.</td></tr>
        <?php else: foreach($sessions as $s): ?>
          <tr>
            <td style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($s['course_code']) ?></td>
            <td class="hide-mobile"><?= htmlspecialchars($s['lecturer_name']) ?></td>
            <td class="hide-mobile" style="color:var(--muted);font-size:.78rem"><?= date('d M, H:i',strtotime($s['start_time'])) ?></td>
            <td><span class="pill pill-<?= $s['active_status']?'green':'red' ?>"><?= $s['active_status']?'Active':'Closed' ?></span></td>
            <td class="hide-mobile"><?= $s['attendance_count'] ?> students</td>
            <td><?php if($s['active_status']): ?><button class="btn btn-danger btn-sm" onclick="closeSession(<?= $s['id'] ?>)">Close</button><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ ATTENDANCE ══ -->
    <div class="page-section" id="sec-attendance">
      <div class="section-header">
        <div class="section-title">Attendance <span>Records</span></div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <a href="../../api/export_attendance.php" class="btn btn-ghost btn-sm">⬇ Export All</a>
          <a href="../../api/export_attendance.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">⬇ Today</a>
        </div>
      </div>
      <div class="filter-bar">
        <input type="text" placeholder="Search student...">
        <select>
          <option value="">All Courses</option>
          <?php foreach($activeCourses as $c): ?><option value="<?= $c['code'] ?>"><?= htmlspecialchars($c['code']) ?></option><?php endforeach; ?>
        </select>
        <select><option value="">All Status</option><option>present</option><option>absent</option><option>late</option></select>
      </div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Student</th><th class="hide-mobile">Index No.</th><th class="hide-mobile">Course</th><th>Status</th><th class="hide-mobile">Timestamp</th><th class="hide-mobile">Selfie</th></tr></thead><tbody>
        <?php
        $records = $pdo->query("SELECT a.*,u.full_name,u.index_no,s.course_code FROM attendance a JOIN users u ON a.student_id=u.id JOIN sessions s ON a.session_id=s.id WHERE u.institution_id=$inst_id ORDER BY a.timestamp DESC LIMIT 50")->fetchAll();
        if(empty($records)): ?><tr><td colspan="6" style="color:var(--muted)">No attendance records yet.</td></tr>
        <?php else: foreach($records as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td class="hide-mobile" style="color:var(--gold);font-size:.78rem"><?= $r['index_no'] ?></td>
            <td class="hide-mobile"><?= $r['course_code'] ?></td>
            <td><span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':'red') ?>"><?= $r['status'] ?></span></td>
            <td class="hide-mobile" style="color:var(--muted);font-size:.75rem"><?= date('d M Y H:i',strtotime($r['timestamp'])) ?></td>
            <td class="hide-mobile"><?= $r['selfie_url']?'<span class="pill pill-green">✓</span>':'<span class="pill pill-red">—</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ OVERRIDE ══ -->
    <div class="page-section" id="sec-override">
      <div class="section-header"><div class="section-title">Attendance <span>Override</span></div></div>
      <div class="override-banner"><strong>⚠ Boss Override Zone.</strong> Changes made here are logged and irreversible without a second override. Use with caution.</div>
      <div class="card" style="margin-bottom:1.5rem;border-color:rgba(224,92,92,.3)"><div class="card-head" style="border-color:rgba(224,92,92,.2)"><div class="card-head-title" style="color:var(--danger)">🔴 SYSTEM RESET</div></div><div class="card-body"><p style="font-size:.83rem;color:var(--muted);margin-bottom:1.2rem">Clears ALL attendance records and sessions. Students and lecturers remain.</p><form method="POST" onsubmit="return confirm('RESET ALL ATTENDANCE DATA? This cannot be undone!')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reset_system"><button type="submit" class="btn" style="background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3);padding:.7rem 1.5rem">🗑 Reset All Attendance & Sessions</button></form></div></div>
      <div class="card"><div class="card-head"><div class="card-head-title">Manual Attendance Adjustment</div></div><div class="card-body">
        <div class="form-row">
          <div class="form-field"><label>Student Index No.</label><input type="text" placeholder="e.g. 52430540001"></div>
          <div class="form-field"><label>Course Code</label><select><option value="">Select Course</option><?php foreach($activeCourses as $c): ?><option value="<?= $c['code'] ?>"><?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Set Status</label><select><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option></select></div>
          <div class="form-field"><label>Reason / Note</label><input type="text" placeholder="Reason for override"></div>
        </div>
        <button class="btn btn-gold">Apply Override</button>
      </div></div>
      <div class="card" style="margin-top:1.5rem"><div class="card-head"><div class="card-head-title">Device Fingerprint Ban</div></div><div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="form-row">
            <div class="form-field"><label>Student Index No.</label><input type="text" name="index_no" placeholder="e.g. 52430540001" required></div>
            <div class="form-field" style="display:flex;align-items:flex-end;gap:.6rem">
              <button type="submit" name="action" value="ban_device" class="btn btn-danger" style="width:100%">Ban Device</button>
              <button type="submit" name="action" value="unban_device" class="btn btn-ghost" style="width:100%">Unban</button>
            </div>
          </div>
        </form>
      </div></div>
    </div>

    <!-- ══ AUDIT LOG ══ -->
    <div class="page-section" id="sec-audit">
      <div class="section-header"><div class="section-title">System <span>Audit Log</span></div></div>
      <div class="card"><div class="card-body">
        <?php if(empty($auditLog)): ?>
          <div class="audit-item"><div class="audit-time">—</div><div class="audit-text" style="color:var(--muted)">No audit entries yet. Actions performed by admins will appear here.</div></div>
        <?php else: foreach($auditLog as $log): ?>
          <div class="audit-item">
            <div class="audit-time"><?= date('d M Y H:i',strtotime($log['created_at'])) ?></div>
            <div class="audit-text"><strong><?= htmlspecialchars($log['actor_name']) ?></strong> — <?= htmlspecialchars($log['action']) ?><?= $log['target_type'] ? ' <em>('.$log['target_type'].' #'.$log['target_id'].')</em>' : '' ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div></div>
    </div>

    <!-- ══ DEVICES ══ -->
    <div class="page-section" id="sec-locked">
      <div class="section-header">
        <div class="section-title">Locked <span>Accounts</span></div>
      </div>
      <?php if(empty($lockedUsers)): ?>
        <div class="card"><div class="card-body" style="text-align:center;color:var(--muted);padding:3rem">No locked accounts</div></div>
      <?php else: ?>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Index</th><th>Role</th><th>Attempts</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach($lockedUsers as $lu): ?>
          <tr>
            <td><?= htmlspecialchars($lu["full_name"]) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($lu["index_no"] ?? "N/A") ?></td>
            <td><span class="pill pill-red"><?= $lu["role"] ?></span></td>
            <td style="color:var(--danger)"><?= $lu["login_attempts"] ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="unlock_account">
                <input type="hidden" name="user_id" value="<?= $lu["id"] ?>">
                <button type="submit" class="btn btn-sm" style="background:rgba(76,175,130,.15);color:var(--success);border:1px solid rgba(76,175,130,.3)">Unlock</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div></div>
      <?php endif ?>
    </div>
    <div class="page-section" id="sec-devices">
      <div class="section-header"><div class="section-title">Device <span>Control</span></div></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Name</th><th class="hide-mobile">Index No.</th><th class="hide-mobile">Device</th><th>Login Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($devs as $d): ?>
          <tr>
            <td><?= htmlspecialchars($d['full_name']) ?></td>
            <td class="hide-mobile" style="color:var(--gold);font-size:.78rem"><?= $d['index_no'] ?></td>
            <td class="hide-mobile" style="color:var(--muted);font-size:.72rem;max-width:160px;overflow:hidden;text-overflow:ellipsis"><?= $d['device_fingerprint']?:'— not registered —' ?></td>
            <td><?= $d['is_locked']?'<span class="pill pill-red">🔒 Locked ('.$d["login_attempts"].' attempts)</span>':'<span class="pill pill-green">Active</span>' ?></td>
            <td><button class="btn btn-ghost btn-sm" onclick="manageDevice(<?= $d['id'] ?>,'<?= htmlspecialchars(addslashes($d['full_name'])) ?>',<?= $d['is_locked'] ?>)">Manage</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ LIVE SESSION ══ -->
    <div class="page-section" id="sec-live">
      <div class="section-header"><div class="section-title">Live <span>Session</span></div></div>
      <?php if($activeSession): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body" style="text-align:center;padding:2rem">
          <div style="font-size:.75rem;color:var(--muted);letter-spacing:.15em;margin-bottom:.5rem">CURRENT CODE</div>
          <div style="font-family:Cinzel,serif;font-size:3rem;color:var(--gold);letter-spacing:.3em"><?= $currentCode ?></div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:.5rem"><?= $activeSession['course_code'] ?> · <?= $timeRemaining ?>s remaining</div>
          <form method="POST" style="margin-top:1.5rem"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="end_session"><input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>"><button type="submit" class="btn btn-danger" onclick="return confirm('End this session?')">End Session</button></form>
        </div></div>
        <div class="card"><div class="card-head"><div class="card-head-title">Live Attendance (<?= count($liveAttendance) ?>)</div></div><div class="card-body" style="padding:0;overflow-x:auto">
          <table class="data-table"><thead><tr><th>Student</th><th>Index</th><th>Status</th><th>Time</th></tr></thead><tbody>
          <?php foreach($liveAttendance as $la): ?>
            <tr><td><?= htmlspecialchars($la['full_name']) ?></td><td style="color:var(--gold);font-size:.78rem"><?= $la['index_no'] ?></td>
            <td><span class="pill pill-<?= $la['status']==='present'?'green':'gold' ?>"><?= $la['status'] ?><?= $la['status']==='late'&&$la['minutes_late']>0?' ('.$la['minutes_late'].'m)':'' ?></span></td>
            <td style="color:var(--muted);font-size:.72rem"><?= date('H:i',strtotime($la['timestamp'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div></div>
      <?php else: ?>
        <div class="card"><div class="card-body" style="color:var(--muted);text-align:center;padding:2rem">No active session right now.</div></div>
      <?php endif; ?>
    </div>

    <!-- ══ APPROVALS ══ -->
    <div class="page-section" id="sec-approvals">
      <div class="section-header"><div class="section-title">Pending <span>Approvals</span></div><span id="pending-count-badge" style="background:var(--warning);color:#060910;padding:.2rem .7rem;border-radius:2px;font-size:.75rem;font-weight:700"><?= $pendingCount ?> PENDING</span></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table" id="approvals-table"><thead><tr><th>Student</th><th>Index</th><th>Photos</th><th>Submitted</th><th>Actions</th></tr></thead>
        <tbody id="approvals-tbody"><tr><td colspan="5" style="color:var(--muted);text-align:center">Loading...</td></tr></tbody>
        </table>
      </div></div>
    </div>

    <!-- ══ SESSION HISTORY ══ -->
    <div class="page-section" id="sec-history">
      <div class="section-header"><div class="section-title">Session <span>History</span></div><a href="../../api/export_attendance.php" class="btn btn-ghost btn-sm">⬇ Export All CSV</a></div>
      <div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Course</th><th>Present</th><th class="hide-mobile">Late</th><th>Absent</th><th>Export</th></tr></thead><tbody>
        <?php if(empty($sessionHistory)): ?><tr><td colspan="5" style="color:var(--muted)">No past sessions yet.</td></tr>
        <?php else: foreach($sessionHistory as $sh): ?>
          <tr>
            <td><strong><?= htmlspecialchars($sh['course_code']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($sh['course_name']) ?></small><br><small style="color:var(--muted);font-size:.7rem"><?= date('d M Y H:i',strtotime($sh['start_time'])) ?></small></td>
            <td><span class="pill pill-green"><?= $sh['present_count'] ?></span></td>
            <td class="hide-mobile"><span class="pill pill-gold"><?= $sh['late_count'] ?></span></td>
            <td><span class="pill pill-red"><?= $sh['absent_count'] ?></span></td>
            <td><a href="../../api/export_attendance.php?session_id=<?= $sh['id'] ?>" class="btn btn-ghost btn-sm">⬇ CSV</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

    <!-- ══ ANNOUNCEMENTS ══ -->
    <div class="page-section" id="sec-announce">
      <div class="section-header"><div class="section-title">Class <span>Announcements</span></div></div>
      <div class="card" style="margin-bottom:1.5rem"><div class="card-head"><div class="card-head-title">Post Announcement</div></div><div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="announce">
          <div class="form-field"><label>Message</label><textarea name="message" required style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.75rem;border-radius:2px;font-family:'DM Sans',sans-serif;min-height:80px;resize:vertical"></textarea></div>
          <button type="submit" class="btn btn-gold">📢 Post to Class</button>
        </form>
      </div></div>
      <div class="card"><div class="card-head"><div class="card-head-title">Recent Announcements</div></div><div class="card-body" style="padding:0;overflow-x:auto">
        <table class="data-table"><thead><tr><th>Message</th><th>From</th><th>Date</th></tr></thead><tbody>
        <?php if(empty($announcements)): ?><tr><td colspan="3" style="color:var(--muted)">No announcements yet.</td></tr>
        <?php else: foreach($announcements as $ann): ?>
          <tr><td><?= htmlspecialchars($ann['message']) ?></td><td style="color:var(--gold);font-size:.75rem"><?= htmlspecialchars($ann['full_name']) ?></td><td style="color:var(--muted);font-size:.72rem"><?= date('d M H:i',strtotime($ann['created_at'])) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </div></div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->

<!-- ══ MODALS ══ -->

<!-- Add Student -->
<div class="modal-overlay" id="modal-add-student">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">ADD STUDENT</div><button class="modal-close" onclick="closeModal('modal-add-student')">✕</button></div>
    <div class="modal-body">
      <form method="POST" action="../../api/add_student.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="form-row">
          <div class="form-field"><label>Full Name</label><input type="text" name="full_name" required placeholder="Surname, Firstname"></div>
          <div class="form-field"><label>Index Number</label><input type="text" name="index_no" required placeholder="52430540000"></div>
        </div>
        <div class="form-field"><label>Program</label>
          <select name="program_id" required>
            <option value="">-- Select Program --</option>
            <?php foreach($allPrograms as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Level</label><select name="level"><option value="1">Level 100</option><option value="2" selected>Level 200</option><option value="3">Level 300</option><option value="4">Level 400</option></select></div>
          <div class="form-field"><label>Role</label><select name="role"><option value="student">Student</option><option value="rep">Course Rep</option></select></div>
        </div>
        <div class="form-field"><label>Email (optional)</label><input type="email" name="email" placeholder="auto-generated if blank"></div>
        <button type="submit" class="btn btn-gold" style="width:100%">Add to Citadel</button>
      </form>
    </div>
  </div>
</div>

<!-- Edit Student -->
<div class="modal-overlay" id="modal-edit-student">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">EDIT STUDENT</div><button class="modal-close" onclick="closeModal('modal-edit-student')">✕</button></div>
    <div class="modal-body">
      <form method="POST" action="../../api/edit_student.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="id" id="edit-id">
        <div class="form-row">
          <div class="form-field"><label>Full Name</label><input type="text" name="full_name" id="edit-name" required></div>
          <div class="form-field"><label>Index Number</label><input type="text" name="index_no" id="edit-index" required></div>
        </div>
        <div class="form-field"><label>Email</label><input type="email" name="email" id="edit-email"></div>
        <div class="form-field"><label>Role</label><select name="role" id="edit-role"><option value="student">Student</option><option value="rep">Course Rep</option></select></div>
        <button type="submit" class="btn btn-gold" style="width:100%">Save Changes</button>
      </form>
      <div style="margin-top:1.5rem;padding-top:1.2rem;border-top:1px solid rgba(224,92,92,.2)">
        <div style="font-size:.7rem;color:var(--danger);letter-spacing:.12em;margin-bottom:.8rem">DANGER ZONE</div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem">
          <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reset_device"><input type="hidden" name="user_id" id="danger-user-id"><button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Reset device fingerprint?')">📱 Reset Device</button></form>
          <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="unlock_account"><input type="hidden" name="user_id" id="danger-user-id2"><button type="submit" class="btn btn-sm" style="background:rgba(76,175,130,.15);color:var(--success);border:1px solid rgba(76,175,130,.3)" onclick="return confirm('Unlock this account?')">🔓 Unlock Account</button></form>
          <button class="btn btn-danger btn-sm" id="danger-remove-btn">🗑 Remove Student</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Semester -->
<div class="modal-overlay" id="modal-add-semester">
  <div class="modal">
    <div class="modal-head"><div class="modal-title" id="semester-modal-title">ADD SEMESTER</div><button class="modal-close" onclick="closeModal('modal-add-semester')">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="sem-edit-id">
      <div class="form-row">
        <div class="form-field"><label>Semester Name</label><input type="text" id="sem-name" placeholder="e.g. 2025/2026 Semester 1"></div>
        <div class="form-field"><label>Academic Year</label><input type="text" id="sem-year" placeholder="e.g. 2025/2026"></div>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Semester Number</label><select id="sem-no"><option value="1">Semester 1</option><option value="2">Semester 2</option></select></div>
        <div class="form-field"></div>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Start Date</label><input type="date" id="sem-start"></div>
        <div class="form-field"><label>End Date</label><input type="date" id="sem-end"></div>
      </div>
      <button class="btn btn-gold" style="width:100%" onclick="saveSemester()">Save Semester</button>
    </div>
  </div>
</div>

<!-- Add/Edit Course -->
<div class="modal-overlay" id="modal-add-course">
  <div class="modal">
    <div class="modal-head"><div class="modal-title" id="course-modal-title">ADD COURSE</div><button class="modal-close" onclick="closeModal('modal-add-course')">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="course-edit-id">
      <div class="form-row">
        <div class="form-field"><label>Course Code</label><input type="text" id="course-code" placeholder="e.g. CSH221"></div>
        <div class="form-field"><label>Credit Hours</label><select id="course-credits"><option value="1">1</option><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select></div>
      </div>
      <div class="form-field"><label>Course Name</label><input type="text" id="course-name" placeholder="e.g. Systems Analysis and Design"></div>
      <div class="form-field"><label>Assign Lecturer</label><select id="course-lecturer"><option value="">— Select Lecturer —</option><?php foreach($allLecturers as $l): ?><option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-field" style="display:flex;align-items:center;gap:.6rem;margin-top:.4rem"><input type="checkbox" id="course-enroll-all" style="width:auto"><label for="course-enroll-all" style="font-size:.8rem;color:var(--muted)">Auto-enroll all active students</label></div>
      <button class="btn btn-gold" style="width:100%;margin-top:1rem" onclick="saveCourse()">Save Course</button>
    </div>
  </div>
</div>

<!-- Add/Edit Lecturer -->
<div class="modal-overlay" id="modal-add-lecturer">
  <div class="modal">
    <div class="modal-head"><div class="modal-title" id="lecturer-modal-title">ADD LECTURER</div><button class="modal-close" onclick="closeModal('modal-add-lecturer')">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="lecturer-edit-id">
      <div class="form-row">
        <div class="form-field"><label>Full Name</label><input type="text" id="lecturer-name" placeholder="Surname, Firstname"></div>
        <div class="form-field"><label>Email</label><input type="email" id="lecturer-email" placeholder="lecturer@citadel.edu"></div>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Phone (optional)</label><input type="text" id="lecturer-phone" placeholder="+233..."></div>
        <div class="form-field"><label>Temp Password</label><input type="text" id="lecturer-password" placeholder="citadel123"></div>
      </div>
      <button class="btn btn-gold" style="width:100%;margin-top:.5rem" onclick="saveLecturer()">Save Lecturer</button>
    </div>
  </div>
</div>

<script>
// ── Navigation ──

// ── Modals ──
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// ── Edit Student ──
function editStudent(id, name, index, email, role, locked, device) {
  document.getElementById('edit-id').value    = id;
  document.getElementById('edit-name').value  = name;
  document.getElementById('edit-index').value = index;
  document.getElementById('edit-email').value = email;
  document.getElementById('edit-role').value  = role;
  document.getElementById('danger-user-id').value  = id;
  document.getElementById('danger-user-id2').value = id;
  document.getElementById('danger-remove-btn').onclick = () => confirmDelete(id, 'student');
  openModal('modal-edit-student');
}

function confirmDelete(id, type) {
  if (confirm('Remove this ' + type + '? Cannot be undone.')) {
    window.location.href = API + '/delete_user.php?id=' + id;
  }
}

function filterStudents() {
  const q = document.getElementById('student-search').value.toLowerCase();
  document.querySelectorAll('#student-table tbody tr').forEach(tr => {
    const name = tr.dataset.name || '', index = tr.dataset.index || '';
    tr.style.display = (name.includes(q) || index.includes(q)) ? '' : 'none';
  });
}

function closeSession(id) {
  if (confirm('Close this session?')) {
    fetch(API + '/close_session.php?id=' + id).then(() => location.reload());
  }
}

// ── Semester Management ──
function openAddSemester() {
  document.getElementById('sem-edit-id').value = '';
  document.getElementById('sem-name').value    = '';
  document.getElementById('sem-year').value    = '';
  document.getElementById('sem-start').value   = '';
  document.getElementById('sem-end').value     = '';
  document.getElementById('semester-modal-title').textContent = 'ADD SEMESTER';
  openModal('modal-add-semester');
}

function editSemester(id, name, year, no, start, end) {
  document.getElementById('sem-edit-id').value = id;
  document.getElementById('sem-name').value    = name;
  document.getElementById('sem-year').value    = year;
  document.getElementById('sem-no').value      = no;
  document.getElementById('sem-start').value   = start;
  document.getElementById('sem-end').value     = end;
  document.getElementById('semester-modal-title').textContent = 'EDIT SEMESTER';
  openModal('modal-add-semester');
}

function saveSemester() {
  const id = document.getElementById('sem-edit-id').value;
  const body = {
    name: document.getElementById('sem-name').value.trim(),
    academic_year: document.getElementById('sem-year').value.trim(),
    semester_no: parseInt(document.getElementById('sem-no').value),
    start_date: document.getElementById('sem-start').value,
    end_date: document.getElementById('sem-end').value,
  };
  if (!body.name || !body.academic_year || !body.start_date || !body.end_date) { alert('All fields required'); return; }
  if (id) body.id = id;
  fetch(API + '/semesters.php', { method: id ? 'PUT' : 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => { if (d.success) { closeModal('modal-add-semester'); location.reload(); } else alert(d.error || 'Failed'); });
}

function setActiveSemester(id, name) {
  if (!confirm('Set "' + name + '" as the active semester?')) return;
  fetch(API + '/semesters.php', { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id, action:'set_active'}) })
    .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
}

// ── Course Management ──
function openAddCourse() {
  document.getElementById('course-edit-id').value = '';
  document.getElementById('course-code').value    = '';
  document.getElementById('course-name').value    = '';
  document.getElementById('course-lecturer').value = '';
  document.getElementById('course-modal-title').textContent = 'ADD COURSE';
  openModal('modal-add-course');
}

function editCourse(id, code, name, lecturerId, credits) {
  document.getElementById('course-edit-id').value = id;
  document.getElementById('course-code').value    = code;
  document.getElementById('course-name').value    = name;
  document.getElementById('course-credits').value = credits;
  if (lecturerId) document.getElementById('course-lecturer').value = lecturerId;
  document.getElementById('course-modal-title').textContent = 'EDIT COURSE';
  openModal('modal-add-course');
}

function saveCourse() {
  const id    = document.getElementById('course-edit-id').value;
  const semId = <?= $activeSemId ?? 'null' ?>;
  const body  = {
    code: document.getElementById('course-code').value.trim().toUpperCase(),
    name: document.getElementById('course-name').value.trim(),
    credit_hrs: parseInt(document.getElementById('course-credits').value),
    lecturer_id: document.getElementById('course-lecturer').value || null,
    program_id: null, semester_id: semId,
    enroll_all: document.getElementById('course-enroll-all').checked ? 1 : 0,
  };
  if (!body.code || !body.name) { alert('Code and name required'); return; }
  if (!id && !semId) { alert('No active semester. Create and activate one first.'); return; }
  if (id) body.id = id;
  fetch(API + '/courses.php', { method: id ? 'PUT' : 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => { if (d.success) { closeModal('modal-add-course'); location.reload(); } else alert(d.error || 'Failed'); });
}

function deleteCourse(id, code) {
  if (!confirm('Delete course ' + code + '?')) return;
  fetch(API + '/courses.php', { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) })
    .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Cannot delete'); });
}

// ── Lecturer Management ──
function openAddLecturer() {
  document.getElementById('lecturer-edit-id').value = '';
  document.getElementById('lecturer-name').value    = '';
  document.getElementById('lecturer-email').value   = '';
  document.getElementById('lecturer-phone').value   = '';
  document.getElementById('lecturer-password').value = '';
  document.getElementById('lecturer-modal-title').textContent = 'ADD LECTURER';
  openModal('modal-add-lecturer');
}

function editLecturer(id, name, email, isActive) {
  document.getElementById('lecturer-edit-id').value = id;
  document.getElementById('lecturer-name').value    = name;
  document.getElementById('lecturer-email').value   = email;
  document.getElementById('lecturer-modal-title').textContent = 'EDIT LECTURER';
  openModal('modal-add-lecturer');
}

function saveLecturer() {
  const id   = document.getElementById('lecturer-edit-id').value;
  const body = {
    full_name: document.getElementById('lecturer-name').value.trim(),
    email: document.getElementById('lecturer-email').value.trim(),
    phone: document.getElementById('lecturer-phone').value.trim(),
    role: 'lecturer', department_id: 1, institution_id: 1,
  };
  if (!id) body.password = document.getElementById('lecturer-password').value.trim() || 'citadel123';
  if (!body.full_name || !body.email) { alert('Name and email required'); return; }
  if (id) body.id = id;
  fetch(API + '/users.php', { method: id ? 'PUT' : 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => { if (d.success) { closeModal('modal-add-lecturer'); location.reload(); } else alert(d.error || 'Failed'); });
}

function toggleUser(id, isActive) {
  if (!confirm((isActive ? 'Deactivate' : 'Activate') + ' this user?')) return;
  fetch(API + '/users.php', { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) })
    .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
}

// ── Approvals ──
function loadApprovalsAdmin() {
  fetch(API + '/pending_approvals.php').then(r => r.json()).then(data => {
    const tbody = document.getElementById('approvals-tbody');
    const badge = document.getElementById('pending-badge');
    const countBadge = document.getElementById('pending-count-badge');
    if (!tbody) return;
    if (!data.rows || !data.rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);text-align:center">No pending approvals.</td></tr>';
      if (badge) badge.style.display = 'none';
      if (countBadge) countBadge.textContent = '0 PENDING';
      return;
    }
    if (badge) { badge.style.display = 'inline'; badge.textContent = data.rows.length; }
    if (countBadge) countBadge.textContent = data.rows.length + ' PENDING';
    tbody.innerHTML = data.rows.map(r => `
      <tr>
        <td>${r.full_name}</td>
        <td style="color:var(--gold);font-size:.78rem">${r.index_no}</td>
        <td style="display:flex;gap:.4rem">
          <img src="../../${r.selfie_url}" style="width:44px;height:44px;border-radius:50%;object-fit:cover;cursor:pointer;border:2px solid var(--steel)" onclick="viewImg('../../'+r.selfie_url,r.full_name+' — Face')">
          ${r.classroom_url ? `<img src="../../${r.classroom_url}" style="width:44px;height:44px;border-radius:2px;object-fit:cover;cursor:pointer;border:2px solid var(--steel-dim)" onclick="viewImg('../../'+r.classroom_url,r.full_name+' — Classroom')">` : "<span style='color:var(--muted);font-size:.7rem'>No classroom</span>"}
        </td>
        <td style="color:var(--muted);font-size:.72rem">${r.submitted_at}</td>
        <td style="display:flex;gap:.4rem">
          <button class="btn btn-sm" style="background:rgba(76,175,130,.15);color:var(--success);border:1px solid rgba(76,175,130,.3)" onclick="approveAdmin(${r.id},'approve')">✓ Approve</button>
          <button class="btn btn-sm" style="background:rgba(224,92,92,.15);color:var(--danger);border:1px solid rgba(224,92,92,.3)" onclick="approveAdmin(${r.id},'reject')">✗ Reject</button>
        </td>
      </tr>`).join('');
  }).catch(() => {});
}

function approveAdmin(id, action) {
  fetch(API + '/approve_attendance.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({attendance_id:id,action}) })
    .then(r => r.json()).then(() => loadApprovalsAdmin()).catch(() => {});
}

function viewImg(src, title) {
  const ov = document.createElement('div');
  ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1rem';
  ov.innerHTML = `<div style="color:var(--gold);font-family:Cinzel,serif;font-size:.85rem">${title}</div><img src="${src}" style="max-width:90vw;max-height:80vh;border-radius:2px"><button onclick="this.parentElement.remove()" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem 1.5rem;cursor:pointer;border-radius:2px">Close</button>`;
  document.body.appendChild(ov);
}

function manageDevice(id, name, locked) {
  alert('Device management for ' + name + ' — use the Override section to ban/unban devices.');
}

function toggleTheme() {
  const body = document.body, btn = document.getElementById('theme-btn');
  if (body.classList.contains('light')) { body.classList.remove('light'); localStorage.setItem('theme','dark'); if(btn) btn.textContent='🌙'; }
  else { body.classList.add('light'); localStorage.setItem('theme','light'); if(btn) btn.textContent='☀️'; }
}
(function(){ if(localStorage.getItem('theme')==='light'){ document.body.classList.add('light'); const btn=document.getElementById('theme-btn'); if(btn) btn.textContent='☀️'; } })();

loadApprovalsAdmin();
setInterval(loadApprovalsAdmin, 15000);

// CSRF injection
const csrfToken = "<?= csrfToken() ?>";
document.querySelectorAll('form').forEach(form => {
  if (!form.querySelector('[name="csrf_token"]')) {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'csrf_token'; input.value = csrfToken;
    form.appendChild(input);
  }
});
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
  options.headers = options.headers || {};
  options.headers['X-CSRF-Token'] = csrfToken;
  return originalFetch(url, options);
};
</script>
<div class="modal-overlay" id="modal-timetable">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title" id="tt-modal-title">ADD TIMETABLE SLOT</div>
      <button class="modal-close" onclick="closeModal('modal-timetable')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="tt-edit-id">
      <div class="form-row">
        <div class="form-field">
          <label>Day</label>
          <select id="tt-day">
            <option>Monday</option><option>Tuesday</option><option>Wednesday</option>
            <option>Thursday</option><option>Friday</option>
          </select>
        </div>
        <div class="form-field">
          <label>Room</label>
          <input type="text" id="tt-room" placeholder="e.g. CLT 303">
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Start Time</label>
          <input type="time" id="tt-start">
        </div>
        <div class="form-field">
          <label>End Time</label>
          <input type="time" id="tt-end">
        </div>
      </div>
      <div class="form-field">
        <label>Course</label>
        <select id="tt-course" onchange="fillTTCourseName()">
          <option value="">— Select Course —</option>
          <?php foreach ($activeCourses as $c): ?>
          <option value="<?= $c['id'] ?>" data-code="<?= htmlspecialchars($c['code']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
            <?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Course Code (manual)</label>
          <input type="text" id="tt-code" placeholder="e.g. CSH221">
        </div>
        <div class="form-field">
          <label>Course Name (manual)</label>
          <input type="text" id="tt-name" placeholder="e.g. Systems Analysis">
        </div>
      </div>
      <div class="form-field">
        <label>Lecturer</label>
        <select id="tt-lecturer">
          <option value="">— Select Lecturer —</option>
          <?php foreach ($allLecturers as $l): ?>
          <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-gold" style="width:100%;margin-top:.5rem" onclick="saveTimetableSlot()">Save Slot</button>
    </div>
  </div>
</div>
 
<script>
function openAddSlot() {
  document.getElementById('tt-edit-id').value = '';
  document.getElementById('tt-day').value     = 'Monday';
  document.getElementById('tt-start').value   = '';
  document.getElementById('tt-end').value     = '';
  document.getElementById('tt-room').value    = '';
  document.getElementById('tt-code').value    = '';
  document.getElementById('tt-name').value    = '';
  document.getElementById('tt-course').value  = '';
  document.getElementById('tt-lecturer').value = '';
  document.getElementById('tt-modal-title').textContent = 'ADD TIMETABLE SLOT';
  openModal('modal-timetable');
}
 
function editSlot(id, day, start, end, code, name, room, lecturerId) {
  document.getElementById('tt-edit-id').value  = id;
  document.getElementById('tt-day').value      = day;
  document.getElementById('tt-start').value    = start;
  document.getElementById('tt-end').value      = end;
  document.getElementById('tt-code').value     = code;
  document.getElementById('tt-name').value     = name;
  document.getElementById('tt-room').value     = room;
  if (lecturerId) document.getElementById('tt-lecturer').value = lecturerId;
  document.getElementById('tt-modal-title').textContent = 'EDIT TIMETABLE SLOT';
  openModal('modal-timetable');
}
 
function fillTTCourseName() {
  const sel = document.getElementById('tt-course');
  const opt = sel?.options[sel.selectedIndex];
  if (opt && opt.value) {
    document.getElementById('tt-code').value = opt.dataset.code || '';
    document.getElementById('tt-name').value = opt.dataset.name || '';
  }
}
 
function saveTimetableSlot() {
  const id = document.getElementById('tt-edit-id').value;
  const body = {
    day_of_week:  document.getElementById('tt-day').value,
    start_time:   document.getElementById('tt-start').value,
    end_time:     document.getElementById('tt-end').value,
    course_code:  document.getElementById('tt-code').value.trim().toUpperCase(),
    course_name:  document.getElementById('tt-name').value.trim(),
    room:         document.getElementById('tt-room').value.trim(),
    lecturer_id:  document.getElementById('tt-lecturer').value || null,
    course_id:    document.getElementById('tt-course').value || null,
  };
  if (!body.day_of_week || !body.start_time || !body.end_time || !body.course_code) {
    alert('Day, times and course code are required'); return;
  }
  if (id) body.id = id;
  fetch(API + '/timetable.php', {
    method: id ? 'PUT' : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if (d.success) { closeModal('modal-timetable'); location.reload(); }
    else alert(d.error || 'Failed to save slot');
  });
}
 
function deleteSlot(id, code) {
  if (!confirm('Delete ' + code + ' slot?')) return;
  fetch(API + '/timetable.php', {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.error || 'Cannot delete');
  });
}
</script>

<!-- CSV Import Modal -->
<div class="modal-overlay" id="modal-import-csv">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">IMPORT STUDENTS — CSV</div>
      <button class="modal-close" onclick="closeModal('modal-import-csv')">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:rgba(74,111,165,.06);border:1px solid rgba(74,111,165,.2);border-radius:2px;padding:.8rem 1rem;font-size:.78rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.6">
        <strong style="color:var(--steel)">CSV Format:</strong> full_name, index_no, email (optional), role (optional)<br>
        First row can be a header — skipped automatically.<br>
        Default password = index number. Auto-enrolled in active semester courses.
      </div>
      <div class="form-field">
        <label>Select CSV File</label>
        <input type="file" id="csv-file" accept=".csv" style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.65rem .9rem;border-radius:2px;width:100%;font-family:'DM Sans',sans-serif">
      </div>
      <button class="btn btn-gold" style="width:100%" onclick="importCSV()">Import Students</button>
      <div id="import-result" style="margin-top:1rem;font-size:.82rem;display:none"></div>
    </div>
  </div>
</div>

<script>
function importCSV() {
  const file = document.getElementById('csv-file').files[0];
  const result = document.getElementById('import-result');
  if (!file) { alert('Please select a CSV file'); return; }
  const formData = new FormData();
  formData.append('csv', file);
  result.style.display = 'block';
  result.style.color = 'var(--muted)';
  result.textContent = 'Importing...';
  fetch(API + '/import_students.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        result.style.color = 'var(--success)';
        result.innerHTML = '✓ ' + d.inserted + ' students imported, ' + d.skipped + ' skipped.';
        setTimeout(() => { closeModal('modal-import-csv'); location.reload(); }, 2000);
      } else {
        result.style.color = 'var(--danger)';
        result.textContent = d.error || 'Import failed';
      }
    }).catch(() => { result.style.color='var(--danger)'; result.textContent='Connection error'; });
}
</script>

<!-- Add Department Modal -->
<div class="modal-overlay" id="modal-add-dept">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">ADD DEPARTMENT</div><button class="modal-close" onclick="closeModal('modal-add-dept')">✕</button></div>
    <div class="modal-body">
      <div class="form-field"><label>Department Name</label><input type="text" id="dept-name" placeholder="e.g. Computer Science"></div>
      <div class="form-field"><label>Code (optional)</label><input type="text" id="dept-code" placeholder="e.g. CS"></div>
      <button class="btn btn-gold" style="width:100%" onclick="saveDept()">Save Department</button>
    </div>
  </div>
</div>

<!-- Add/Edit Program Modal -->
<div class="modal-overlay" id="modal-add-program">
  <div class="modal">
    <div class="modal-head"><div class="modal-title" id="prog-modal-title">ADD PROGRAM</div><button class="modal-close" onclick="closeModal('modal-add-program')">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="prog-edit-id">
      <div class="form-field">
        <label>Department</label>
        <select id="prog-dept" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.7rem 1rem;border-radius:2px">
          <?php foreach($allDepartments as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-field"><label>Program Name</label><input type="text" id="prog-name" placeholder="e.g. HND Computer Science"></div>
      <div class="form-row">
        <div class="form-field"><label>Code</label><input type="text" id="prog-code" placeholder="e.g. HND-CS"></div>
        <div class="form-field"><label>Duration (years)</label><input type="number" id="prog-duration" value="3" min="1" max="6"></div>
      </div>
      <button class="btn btn-gold" style="width:100%;margin-top:.5rem" onclick="saveProgram()">Save Program</button>
      <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid var(--border)">
        <div style="font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem">Or Upload CSV</div>
        <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem">Format: name,code,duration_yrs (one per line)</div>
        <input type="file" id="prog-csv" accept=".csv" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.5rem;border-radius:2px;font-size:.8rem">
        <button class="btn btn-steel" style="width:100%;margin-top:.5rem" onclick="uploadProgramCSV()">Upload CSV</button>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/toast.php'; ?>
<script src="/admin_charts.js"></script>
<script>
// ── PROGRAMS ──
function openAddProgram(){
  document.getElementById('prog-edit-id').value='';
  document.getElementById('prog-modal-title').textContent='ADD PROGRAM';
  document.getElementById('prog-name').value='';
  document.getElementById('prog-code').value='';
  document.getElementById('prog-duration').value='2';
  document.getElementById('modal-add-program').classList.add('open');
}
function editProgram(id,name,code,deptId,duration){
  document.getElementById('prog-edit-id').value=id;
  document.getElementById('prog-modal-title').textContent='EDIT PROGRAM';
  document.getElementById('prog-name').value=name;
  document.getElementById('prog-code').value=code;
  document.getElementById('prog-dept').value=deptId;
  document.getElementById('prog-duration').value=duration;
  document.getElementById('modal-add-program').classList.add('open');
}
async function saveProgram(){
  const id=document.getElementById('prog-edit-id').value;
  const name=document.getElementById('prog-name').value.trim();
  const code=document.getElementById('prog-code').value.trim();
  const dept=document.getElementById('prog-dept').value;
  const duration=document.getElementById('prog-duration').value;
  if(!name||!code){toast('error','Name and code required');return;}
  const body={name,code,department_id:dept,duration_yrs:duration};
  if(id) body.id=id;
  const r=await fetch('/api/programs.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const d=await r.json();
  if(d.ok){toast('success',id?'Program updated':'Program added');closeModal('modal-add-program');setTimeout(()=>location.reload(),800);}
  else toast('error',d.error||'Error');
}
async function deleteProgram(id,name){
  if(!confirm('Delete program "'+name+'"?

Students assigned to this program will be unaffected.'))return;
  const r=await fetch('/api/programs.php',{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  const d=await r.json();
  if(d.ok){toast('success','Program deleted');setTimeout(()=>location.reload(),800);}
  else toast('error',d.error||'Error');
}
</script>
</body>
</html>
