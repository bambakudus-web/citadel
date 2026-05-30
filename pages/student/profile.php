<?php
require_once '../../includes/security.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/guard.php';
require_once '../../includes/terminology.php';
requireRole('student','rep');

$userId  = $_SESSION['user_id'];
$inst_id = (int)($_SESSION['institution_id'] ?? 1);
$instType = $institution['inst_type'] ?? 'university';

// Student info
$student = $pdo->prepare("
    SELECT u.*, p.name AS program_name, p.code AS program_code, d.name AS dept_name
    FROM users u
    LEFT JOIN programs p ON p.id=u.program_id
    LEFT JOIN departments d ON d.id=u.department_id
    WHERE u.id=?
");
$student->execute([$userId]); $student = $student->fetch();

// Active semester
$activeSem = $pdo->query("SELECT * FROM semesters WHERE is_active=1 AND institution_id=$inst_id LIMIT 1")->fetch();
$semId = $activeSem['id'] ?? null;

// Enrolled courses with attendance stats
$courses = [];
if ($semId) {
    $stmt = $pdo->prepare("
        SELECT c.code, c.name,
               COUNT(DISTINCT s.id) AS total_sessions,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) AS late,
               SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS absent
        FROM course_enrollments ce
        JOIN courses c ON c.id=ce.course_id
        LEFT JOIN sessions s ON s.course_id=c.id
        LEFT JOIN attendance a ON a.session_id=s.id AND a.student_id=?
        WHERE ce.student_id=? AND ce.semester_id=? AND ce.status='active'
        GROUP BY c.id ORDER BY c.code
    ");
    $stmt->execute([$userId, $userId, $semId]);
    $courses = $stmt->fetchAll();
}

// Overall stats
$totalSessions = array_sum(array_column($courses, 'total_sessions'));
$totalPresent  = array_sum(array_column($courses, 'present')) + array_sum(array_column($courses, 'late'));
$attendanceRate = $totalSessions > 0 ? round(($totalPresent / $totalSessions) * 100) : 0;

// Recent attendance
$recent = $pdo->prepare("
    SELECT a.status, a.timestamp, s.course_code, s.course_name
    FROM attendance a
    JOIN sessions s ON s.id=a.session_id
    WHERE a.student_id=? ORDER BY a.timestamp DESC LIMIT 20
");
$recent->execute([$userId]); $recent = $recent->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — My Profile</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--steel-dim:#2a4060;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warning:#e0a050}
html,body{min-height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
.page{max-width:800px;margin:0 auto;padding:2rem 1rem}
.back{display:inline-flex;align-items:center;gap:.5rem;color:var(--muted);text-decoration:none;font-size:.82rem;margin-bottom:1.5rem}
.back:hover{color:var(--gold)}
.profile-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2rem;margin-bottom:1.5rem;position:relative;overflow:hidden}
.profile-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.profile-avatar{width:64px;height:64px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:1.4rem;font-weight:700;color:#060910;margin-bottom:1rem}
.profile-name{font-family:'Cinzel',serif;font-size:1.2rem;color:var(--text);letter-spacing:.06em;margin-bottom:.3rem}
.profile-meta{font-size:.78rem;color:var(--muted);margin-bottom:.2rem}
.profile-meta span{color:var(--gold)}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.stat-box{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.2rem;text-align:center}
.stat-num{font-size:2rem;font-weight:600;color:var(--gold);line-height:1}
.stat-lbl{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:2px;margin-bottom:1.5rem}
.card-head{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
.card-body{padding:1.2rem}
.course-row{display:flex;align-items:center;justify-content:space-between;padding:.7rem 0;border-bottom:1px solid rgba(26,37,53,.5)}
.course-row:last-child{border-bottom:none}
.course-name{font-size:.85rem;color:var(--text)}
.course-code{font-size:.72rem;color:var(--gold);letter-spacing:.1em}
.bar-wrap{width:120px;height:6px;background:var(--border);border-radius:3px;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;transition:width .8s ease}
.bar-pct{font-size:.72rem;color:var(--muted);margin-left:.5rem;min-width:35px}
.pill{display:inline-block;font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;border-radius:2px}
.pill-green{background:rgba(76,175,130,.12);color:var(--success);border:1px solid rgba(76,175,130,.3)}
.pill-red{background:rgba(224,92,92,.12);color:var(--danger);border:1px solid rgba(224,92,92,.3)}
.pill-gold{background:rgba(201,168,76,.12);color:var(--gold);border:1px solid rgba(201,168,76,.3)}
.att-row{display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid rgba(26,37,53,.5);font-size:.82rem}
.att-row:last-child{border-bottom:none}
.att-time{font-size:.7rem;color:var(--muted)}
@media(max-width:600px){.stats-row{grid-template-columns:1fr 1fr 1fr}.bar-wrap{width:80px}}
</style>
</head>
<body>
<div class="page">
  <a href="dashboard.php" class="back">← Back to Dashboard</a>

  <div class="profile-card">
    <div class="profile-avatar"><?= strtoupper(substr($student['full_name'],0,2)) ?></div>
    <div class="profile-name"><?= htmlspecialchars($student['full_name']) ?></div>
    <div class="profile-meta"><?= terms('index_no',$instType) ?>: <span><?= htmlspecialchars($student['index_no']??'—') ?></span></div>
    <div class="profile-meta"><?= terms('program',$instType) ?>: <span><?= htmlspecialchars($student['program_name']??'—') ?></span></div>
    <?php if($activeSem): ?>
    <div class="profile-meta"><?= terms('semester',$instType) ?>: <span><?= htmlspecialchars($activeSem['name']) ?></span></div>
    <?php endif; ?>
    <div class="profile-meta">Email: <span><?= htmlspecialchars($student['email']??'—') ?></span></div>
  </div>

  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-num"><?= $attendanceRate ?>%</div>
      <div class="stat-lbl">Attendance Rate</div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= $totalPresent ?></div>
      <div class="stat-lbl">Sessions Attended</div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= $totalSessions - $totalPresent ?></div>
      <div class="stat-lbl">Sessions Missed</div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><?= terms('courses',$instType) ?> This <?= terms('semester',$instType) ?></div>
    <div class="card-body">
      <?php if(empty($courses)): ?>
        <div style="color:var(--muted);font-size:.83rem">No enrolled <?= terms('courses',$instType) ?> this <?= terms('semester',$instType) ?>.</div>
      <?php else: foreach($courses as $c):
        $pct = $c['total_sessions'] > 0 ? round((($c['present']+$c['late'])/$c['total_sessions'])*100) : 0;
        $color = $pct >= 75 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
      ?>
      <div class="course-row">
        <div>
          <div class="course-code"><?= htmlspecialchars($c['code']) ?></div>
          <div class="course-name"><?= htmlspecialchars($c['name']) ?></div>
          <div style="font-size:.7rem;color:var(--muted);margin-top:.2rem"><?= $c['present'] ?> present · <?= $c['late'] ?> late · <?= $c['absent'] ?> absent</div>
        </div>
        <div style="display:flex;align-items:center">
          <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
          <span class="bar-pct" style="color:<?= $color ?>"><?= $pct ?>%</span>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Recent Attendance</div>
    <div class="card-body">
      <?php if(empty($recent)): ?>
        <div style="color:var(--muted);font-size:.83rem">No attendance records yet.</div>
      <?php else: foreach($recent as $r): ?>
      <div class="att-row">
        <div>
          <div style="font-size:.82rem;color:var(--text)"><?= htmlspecialchars($r['course_code']) ?> — <?= htmlspecialchars($r['course_name']) ?></div>
          <div class="att-time"><?= date('d M Y H:i',strtotime($r['timestamp'])) ?></div>
        </div>
        <span class="pill pill-<?= $r['status']==='present'?'green':($r['status']==='late'?'gold':'red') ?>"><?= $r['status'] ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div style="text-align:center;margin-top:1rem">
    <a href="../../change_password.php" style="color:var(--muted);font-size:.8rem;text-decoration:none"> Change Password</a>
  </div>
</div>
</body>
</html>
