<?php
require_once __DIR__ . '/../../includes/guard.php';
guardSuperAdmin();

$stats = [];
$stats['schools']      = (int)$pdo->query("SELECT COUNT(*) FROM institutions WHERE is_active=1")->fetchColumn();
$stats['users']        = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin')")->fetchColumn();
$stats['students']     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$stats['attendance']   = (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
$stats['live']         = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE active_status=1")->fetchColumn();

$schools = $pdo->query("
  SELECT i.*,
    (SELECT COUNT(*) FROM users u WHERE u.institution_id=i.id AND u.role='student') AS student_count,
    (SELECT COUNT(*) FROM users u WHERE u.institution_id=i.id AND u.role='lecturer') AS lecturer_count
  FROM institutions i ORDER BY i.created_at DESC LIMIT 20
")->fetchAll();

$recent = $pdo->query("
  SELECT a.*, u.full_name, inst.name AS inst_name
  FROM audit_log a
  JOIN users u ON u.id=a.actor_id
  LEFT JOIN institutions inst ON inst.id=u.institution_id
  ORDER BY a.created_at DESC LIMIT 20
")->fetchAll();

$plans = $pdo->query("SELECT plan, COUNT(*) AS cnt FROM institutions GROUP BY plan")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
    if ($action==='toggle_school') {
        $val=(int)($_POST['val']??0);
        $pdo->prepare("UPDATE institutions SET is_active=? WHERE id=?")->execute([$val,$id]);
        audit('TOGGLE_SCHOOL','institution',$id,"is_active=$val");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action==='change_plan') {
        $plan=$_POST['plan']??'free';
        if (!in_array($plan,['free','pro','enterprise'])){echo json_encode(['ok'=>false,'msg'=>'Bad plan']);exit;}
        $pdo->prepare("UPDATE institutions SET plan=? WHERE id=?")->execute([$plan,$id]);
        audit('CHANGE_PLAN','institution',$id,"plan=$plan");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action==='delete_school') {
        if ($id===1){echo json_encode(['ok'=>false,'msg'=>'Cannot delete default institution.']);exit;}
        $pdo->prepare("DELETE FROM institutions WHERE id=?")->execute([$id]);
        audit('DELETE_SCHOOL','institution',$id);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100%}
a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
.sidebar{width:230px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50}
.sb-brand{display:flex;align-items:center;gap:.75rem;padding:1.5rem 1.3rem 1.1rem;border-bottom:1px solid var(--border)}
.sb-logo{font-family:'Cinzel',serif;font-size:.95rem;font-weight:700;color:var(--gold);letter-spacing:.12em}
.sb-role{font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
.sb-nav{flex:1;padding:1rem 0;overflow-y:auto}
.sb-sec{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.3rem .3rem}
.sb-a{display:flex;align-items:center;gap:.7rem;padding:.6rem 1.3rem;color:var(--muted);font-size:.84rem;border-left:2px solid transparent;transition:all .15s}
.sb-a:hover{color:var(--text);background:rgba(255,255,255,.03)}
.sb-a.on{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.05)}
.sb-foot{padding:1rem 1.3rem;border-top:1px solid var(--border)}
.sb-av{width:32px;height:32px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;color:#060910}
.sb-out{display:block;margin-top:.7rem;font-size:.74rem;color:var(--muted)}
.sb-out:hover{color:var(--danger)}
.main{margin-left:230px;flex:1;padding:2rem 2.2rem}
.ph{margin-bottom:1.8rem}
.pt{font-family:'Cinzel',serif;font-size:1.4rem;letter-spacing:.08em}
.ps{font-size:.8rem;color:var(--muted);margin-top:.2rem}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.2rem 1.3rem;border-top:2px solid var(--ac,var(--gold))}
.sl{font-size:.65rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.sn{font-family:'Cinzel',serif;font-size:1.9rem;font-weight:700}
.sb2{font-size:.7rem;color:var(--muted);margin-top:.2rem}
.live{color:var(--success)!important;animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.wrap2{display:grid;grid-template-columns:1fr 320px;gap:1.5rem}
.sec{background:var(--surface);border:1px solid var(--border);border-radius:2px;margin-bottom:1.5rem}
.sh{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.3rem;border-bottom:1px solid var(--border)}
.st{font-family:'Cinzel',serif;font-size:.88rem;letter-spacing:.08em}
.sa{font-size:.74rem;color:var(--gold);cursor:pointer;background:none;border:1px solid rgba(201,168,76,.3);padding:.3rem .65rem;border-radius:2px}
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);padding:.65rem .9rem;text-align:left;border-bottom:1px solid var(--border)}
.tbl td{padding:.7rem .9rem;font-size:.83rem;border-bottom:1px solid rgba(26,37,53,.5);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-block;padding:.18rem .5rem;border-radius:2px;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;font-weight:600}
.bf{background:rgba(107,122,141,.15);color:var(--muted)}
.bp{background:rgba(74,111,165,.15);color:#7aabf5}
.be{background:rgba(201,168,76,.15);color:var(--gold)}
.ba{background:rgba(76,175,130,.12);color:var(--success)}
.bi{background:rgba(224,92,92,.12);color:var(--danger)}
.psel{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.28rem .55rem;border-radius:2px;font-size:.76rem;cursor:pointer}
.tog{position:relative;display:inline-block;width:34px;height:18px;cursor:pointer}
.tog input{opacity:0;width:0;height:0}
.tsl{position:absolute;inset:0;background:#1a2535;border-radius:18px;transition:.2s}
.tsl::before{content:'';position:absolute;width:12px;height:12px;left:3px;top:3px;background:var(--muted);border-radius:50%;transition:.2s}
.tog input:checked+.tsl{background:rgba(76,175,130,.3)}
.tog input:checked+.tsl::before{background:var(--success);transform:translateX(16px)}
.ab{background:none;border:none;color:var(--muted);cursor:pointer;padding:.28rem .45rem;border-radius:2px;font-size:.76rem;transition:all .2s}
.ab:hover{background:rgba(255,255,255,.05);color:var(--text)}
.ab.del:hover{color:var(--danger)}
.feed{padding:0}
.fi{display:flex;gap:.9rem;align-items:flex-start;padding:.8rem 1.3rem;border-bottom:1px solid rgba(26,37,53,.5)}
.fi:last-child{border-bottom:none}
.fd{width:7px;height:7px;border-radius:50%;background:var(--gold);flex-shrink:0;margin-top:.35rem}
.fb{flex:1;min-width:0}
.fa{font-size:.8rem}
.fm{font-size:.7rem;color:var(--muted);margin-top:.15rem}
.ft{font-size:.68rem;color:var(--muted);flex-shrink:0;white-space:nowrap}
.empty{text-align:center;padding:2.5rem;color:var(--muted);font-size:.83rem}
#toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.65rem 1.1rem;border-radius:2px;font-size:.8rem;z-index:9999;transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
#toast.ok{background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.4);color:var(--success)}
#toast.err{background:rgba(224,92,92,.1);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
@media(max-width:900px){.sidebar{width:200px}.main{margin-left:200px}.wrap2{grid-template-columns:1fr}}
@media(max-width:600px){.sidebar{display:none}.main{margin-left:0}}

/* ── MOBILE ── */
@media(max-width:768px){
  .sidebar{width:280px;position:fixed;transform:translateX(-100%);transition:transform .3s;z-index:200;box-shadow:4px 0 24px rgba(0,0,0,.5)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0!important;padding:1rem!important}
  .ph{flex-direction:column!important;align-items:flex-start!important}
  .toolbar{width:100%}
  .sbox{width:100%!important}
  .stats,.stats-row{grid-template-columns:repeat(2,1fr)!important;gap:.6rem!important}
  .sc,.stat-card{padding:1rem!important}
  .sn,.stat-num{font-size:1.6rem!important}
  .wrap2{grid-template-columns:1fr!important}
  .tbl{font-size:.78rem;min-width:600px}
  .tbl th,.tbl td{padding:.5rem .7rem!important}
  .sec{overflow-x:auto}
  .drw,.drawer{width:100vw!important}
  .menu-toggle{display:flex!important}
  .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199}
  .sidebar-overlay.active{display:block}
}
@media(max-width:480px){
  .stats,.stats-row{grid-template-columns:1fr!important}
}
@media(min-width:769px){
  .menu-toggle{display:none!important}
}
.menu-toggle{display:none;align-items:center;justify-content:center;width:36px;height:36px;background:var(--surface2,var(--surface));border:1px solid var(--border);border-radius:2px;color:var(--text);cursor:pointer;font-size:1.1rem;margin-bottom:1rem}

/* Safari zoom fix — inputs must be 16px */
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:inherit!important}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <div class="sb-brand">
    <div><div class="sb-logo">CITADEL</div><div class="sb-role">Super Admin</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Platform</div>
    <a href="dashboard.php" class="sb-a on">📊 Overview</a>
    <a href="schools.php"   class="sb-a">🏫 Schools</a>
    <a href="users.php"     class="sb-a">👥 All Users</a>
    <div class="sb-sec">Tools</div>
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
  <div class="ph">
    <div class="pt">Platform Overview</div>
    <div class="ps">Welcome back, <?php echo htmlspecialchars(explode(' ',$me['full_name'])[0]); ?>. Here's the platform at a glance.</div>
  </div>
  <div class="stats">
    <div class="sc" style="--ac:var(--gold)"><div class="sl">Institutions</div><div class="sn"><?php echo number_format($stats['schools']); ?></div><div class="sb2">Active schools</div></div>
    <div class="sc" style="--ac:var(--steel)"><div class="sl">Total Users</div><div class="sn"><?php echo number_format($stats['users']); ?></div><div class="sb2"><?php echo number_format($stats['students']); ?> students</div></div>
    <div class="sc" style="--ac:var(--success)"><div class="sl">Live Sessions</div><div class="sn live"><?php echo $stats['live']; ?></div><div class="sb2">Right now</div></div>
    <div class="sc" style="--ac:#8a6fd4"><div class="sl">Attendance</div><div class="sn"><?php echo number_format($stats['attendance']); ?></div><div class="sb2">All time records</div></div>
  </div>
  <div class="wrap2">
    <div>
      <div class="sec">
        <div class="sh"><div class="st">Institutions</div><a href="schools.php"><button class="sa">View All →</button></a></div>
        <?php if($schools): ?>
        <div style="overflow-x:auto"><table class="tbl">
          <thead><tr><th>Name</th><th>Code</th><th>Plan</th><th>Students</th><th>Active</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach($schools as $s): ?>
          <tr id="sr-<?php echo $s['id']; ?>">
            <td><div><?php echo htmlspecialchars($s['name']); ?></div><div style="font-size:.7rem;color:var(--muted)"><?php echo htmlspecialchars($s['email']??''); ?></div></td>
            <td><span style="font-family:'Cinzel',serif;color:var(--gold);letter-spacing:.1em;font-size:.8rem"><?php echo strtoupper(htmlspecialchars($s['slug']??'')); ?></span></td>
            <td><select class="psel" onchange="chPlan(<?php echo $s['id']; ?>,this.value)"><?php foreach(['free','pro','enterprise'] as $p): ?><option value="<?php echo $p; ?>" <?php echo ($s['plan']??'free')===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?></select></td>
            <td><?php echo number_format($s['student_count']); ?></td>
            <td><label class="tog"><input type="checkbox" <?php echo $s['is_active']?'checked':''; ?> onchange="togSchool(<?php echo $s['id']; ?>,this.checked?1:0)"><span class="tsl"></span></label></td>
            <td>
              <a href="schools.php?view=<?php echo $s['id']; ?>"><button class="ab" title="View">👁</button></a>
              <?php if($s['id']!=1): ?><button class="ab del" onclick="delSchool(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars(addslashes($s['name'])); ?>')">🗑</button><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?><div class="empty">No institutions yet. <a href="../../onboard.php" style="color:var(--gold)">Add one →</a></div><?php endif; ?>
      </div>
    </div>
    <div>
      <div class="sec">
        <div class="sh"><div class="st">Recent Activity</div></div>
        <div class="feed">
          <?php if($recent): foreach(array_slice($recent,0,15) as $a): ?>
          <div class="fi"><div class="fd"></div><div class="fb"><div class="fa"><?php echo htmlspecialchars($a['action']); ?></div><div class="fm"><?php echo htmlspecialchars($a['full_name']); ?> · <?php echo htmlspecialchars($a['inst_name']??'Platform'); ?></div></div><div class="ft"><?php echo date('d M H:i',strtotime($a['created_at'])); ?></div></div>
          <?php endforeach; else: ?><div class="empty">No activity yet.</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
</div>
<div id="toast"></div>
<script>
function toast(m,t='ok'){const el=document.getElementById('toast');el.textContent=m;el.className='show '+t;setTimeout(()=>el.className='',3000)}
async function post(d){return (await fetch('dashboard.php',{method:'POST',body:new URLSearchParams(d)})).json()}
async function togSchool(id,val){const r=await post({action:'toggle_school',id,val});r.ok?toast(val?'Activated.':'Deactivated.'):toast(r.msg||'Error','err')}
async function chPlan(id,plan){const r=await post({action:'change_plan',id,plan});r.ok?toast('Plan updated.'):toast(r.msg||'Error','err')}
async function delSchool(id,name){
  if(!confirm('Delete "'+name+'"? All data removed. Cannot undo.'))return;
  const r=await post({action:'delete_school',id});
  if(r.ok){document.getElementById('sr-'+id)?.remove();toast('Deleted.');}else toast(r.msg||'Error','err');
}
</script>
<script>
// Mobile sidebar
(function(){
  var toggle = document.getElementById('menu-toggle');
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if(!toggle||!sidebar) return;
  toggle.addEventListener('click', function(){
    sidebar.classList.toggle('open');
    if(overlay) overlay.classList.toggle('active', sidebar.classList.contains('open'));
  });
  if(overlay) overlay.addEventListener('click', function(){
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
  });
})();
</script>
</body></html>
