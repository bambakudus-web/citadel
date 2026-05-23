<?php
require_once __DIR__ . '/../../includes/guard.php';
guardSuperAdmin();

$page  = max(1,(int)($_GET['page']??1));
$limit = 50;
$offset= ($page-1)*$limit;
$q     = trim($_GET['q']??'');
$inst  = (int)($_GET['inst']??0);
$action= trim($_GET['action']??'');

$w=[]; $p=[];
if($q){$w[]="(u.full_name LIKE ? OR al.action LIKE ?)";$x="%$q%";$p[]=$x;$p[]=$x;}
if($inst){$w[]="u.institution_id=?";$p[]=$inst;}
if($action){$w[]="al.action=?";$p[]=$action;}
$where=$w?"WHERE ".implode(" AND ",$w):"";

$cnt=$pdo->prepare("SELECT COUNT(*) FROM audit_log al JOIN users u ON u.id=al.actor_id $where");
$cnt->execute($p); $total=(int)$cnt->fetchColumn();
$pages=ceil($total/$limit);

$stmt=$pdo->prepare("
    SELECT al.*, u.full_name, u.role, i.name AS inst_name, i.slug AS inst_slug
    FROM audit_log al
    JOIN users u ON u.id=al.actor_id
    LEFT JOIN institutions i ON i.id=u.institution_id
    $where
    ORDER BY al.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($p);
$logs=$stmt->fetchAll();

$insts=$pdo->query("SELECT id,name FROM institutions ORDER BY name")->fetchAll();
$actions=$pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Activity Log</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100%}a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
.sidebar{width:230px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50}
.sb-brand{display:flex;align-items:center;gap:.75rem;padding:1.5rem 1.3rem 1.1rem;border-bottom:1px solid var(--border)}
.sb-logo{font-family:'Cinzel',serif;font-size:.95rem;font-weight:700;color:var(--gold);letter-spacing:.12em}.sb-role{font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
.sb-nav{flex:1;padding:1rem 0}.sb-sec{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);padding:.8rem 1.3rem .3rem}
.sb-a{display:flex;align-items:center;gap:.7rem;padding:.6rem 1.3rem;color:var(--muted);font-size:.84rem;border-left:2px solid transparent;transition:all .15s}
.sb-a:hover{color:var(--text);background:rgba(255,255,255,.03)}.sb-a.on{color:var(--gold);border-left-color:var(--gold);background:rgba(201,168,76,.05)}
.sb-foot{padding:1rem 1.3rem;border-top:1px solid var(--border)}
.sb-av{width:32px;height:32px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;color:#060910}
.sb-out{display:block;margin-top:.7rem;font-size:.74rem;color:var(--muted)}.sb-out:hover{color:var(--danger)}
.main{margin-left:230px;flex:1;padding:2rem 2.2rem}
.ph{margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.pt{font-family:'Cinzel',serif;font-size:1.4rem;letter-spacing:.08em}.ps{font-size:.8rem;color:var(--muted);margin-top:.2rem}
.toolbar{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap}
.sbox{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .85rem;border-radius:2px;font-size:.84rem;outline:none;width:180px}.sbox:focus{border-color:var(--steel)}
.fsel{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .75rem;border-radius:2px;font-size:.8rem;cursor:pointer}
.sec{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.feed{padding:0}
.fi{display:grid;grid-template-columns:120px 1fr 140px 160px;gap:1rem;align-items:center;padding:.85rem 1.3rem;border-bottom:1px solid rgba(26,37,53,.5);font-size:.83rem}
.fi:last-child{border-bottom:none}.fi:hover{background:rgba(255,255,255,.015)}
.fi-time{font-size:.72rem;color:var(--muted)}
.fi-action{font-weight:500;color:var(--text)}
.fi-detail{font-size:.75rem;color:var(--muted);margin-top:.15rem}
.fi-user{font-size:.78rem}
.fi-uname{color:var(--text)}
.fi-urole{font-size:.68rem;color:var(--muted)}
.fi-inst{font-size:.75rem;color:var(--gold);font-family:'Cinzel',serif;letter-spacing:.08em}
.thead{display:grid;grid-template-columns:120px 1fr 140px 160px;gap:1rem;padding:.65rem 1.3rem;border-bottom:1px solid var(--border)}
.thead span{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.badge{display:inline-block;padding:.18rem .5rem;border-radius:2px;font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;font-weight:600}
.r-admin{background:rgba(201,168,76,.15);color:var(--gold)}
.r-lecturer{background:rgba(138,111,212,.15);color:#b09ae8}
.r-student{background:rgba(74,111,165,.15);color:#7aabf5}
.r-rep{background:rgba(76,175,130,.15);color:var(--success)}
.r-super_admin{background:rgba(224,92,92,.15);color:var(--danger)}
.pag{display:flex;align-items:center;gap:.5rem;padding:1rem 1.3rem;border-top:1px solid var(--border);flex-wrap:wrap}
.pag a{padding:.35rem .7rem;border:1px solid var(--border);border-radius:2px;font-size:.78rem;color:var(--muted);transition:all .2s}
.pag a:hover{border-color:var(--gold);color:var(--gold)}.pag a.on{border-color:var(--gold);color:var(--gold);background:rgba(201,168,76,.08)}
.pag-info{font-size:.75rem;color:var(--muted);margin-left:auto}
.empty{text-align:center;padding:3rem;color:var(--muted);font-size:.83rem}

.menu-toggle{display:none;align-items:center;justify-content:center;width:36px;height:36px;background:var(--surface2,var(--surface));border:1px solid var(--border);border-radius:2px;color:var(--text);cursor:pointer;font-size:1.1rem;margin-bottom:1rem}

/* Safari zoom fix — inputs must be 16px */

/* ═══ MOBILE ═══ */
@media(max-width:768px){
  .sidebar{width:260px!important;position:fixed!important;top:0!important;left:0!important;height:100vh!important;z-index:500!important;transform:translateX(-100%)!important;transition:transform .25s ease!important;box-shadow:4px 0 20px rgba(0,0,0,.8)!important}
  .sidebar.open{transform:translateX(0)!important}
  .main{margin-left:0!important;padding:1rem!important}
  .menu-toggle{display:flex!important;align-items:center!important;justify-content:center!important;width:36px!important;height:36px!important;background:rgba(255,255,255,.08)!important;border:1px solid var(--border)!important;border-radius:4px!important;color:var(--text)!important;cursor:pointer!important;font-size:18px!important;flex-shrink:0!important;padding:0!important;margin-bottom:1rem!important}
  .ph{flex-direction:column!important;align-items:flex-start!important}
  .toolbar{width:100%!important}
  .sbox{width:100%!important}
  .stats,.stats-row{grid-template-columns:1fr 1fr!important;gap:.5rem!important}
  .sc,.stat-card{padding:.8rem!important}
  .sn,.stat-num{font-size:1.5rem!important}
  .wrap2{grid-template-columns:1fr!important}
  .sec{overflow-x:auto!important}
  .tbl{font-size:.72rem!important;min-width:420px!important}
  .tbl th,.tbl td{padding:.38rem .45rem!important;white-space:nowrap!important}
  .drw,.drawer{width:100vw!important}
  .fi{grid-template-columns:1fr!important;gap:.3rem!important}
  .thead{display:none!important}
}
@media(max-width:420px){
  .stats,.stats-row{grid-template-columns:1fr!important}
}
@media(min-width:769px){
  .menu-toggle{display:none!important}
}
#sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:499;backdrop-filter:blur(2px)}
#sidebar-overlay.show{display:block}
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:unset!important}}
</style><script>
function toggleSidebar(){
  var sb=document.getElementById('sidebar');
  var ov=document.getElementById('sidebar-overlay');
  if(!sb)return;
  var open=sb.classList.toggle('open');
  if(ov){ov.style.display=open?'block':'none';}
}
document.addEventListener('DOMContentLoaded',function(){
  var btn=document.getElementById('menu-btn')||document.getElementById('menu-toggle');
  if(btn)btn.addEventListener('click',function(e){e.stopPropagation();toggleSidebar();});
  var ov=document.getElementById('sidebar-overlay');
  if(ov)ov.addEventListener('click',function(){toggleSidebar();});
});
</script>
</head><body>
<div class="layout">
<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
<aside class="sidebar">
  <div class="sb-brand"><div><div class="sb-logo">CITADEL</div><div class="sb-role">Super Admin</div></div></div>
  <nav class="sb-nav">
    <div class="sb-sec">Platform</div>
    <a href="dashboard.php" class="sb-a">📊 Overview</a>
    <a href="schools.php"   class="sb-a">🏫 Schools</a>
    <a href="users.php"     class="sb-a">👥 All Users</a>
    <div class="sb-sec">Reports</div>
    <a href="activity.php"  class="sb-a on">📋 Activity Log</a>
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
    <div><div class="pt">Activity Log</div><div class="ps"><?php echo number_format($total); ?> events</div></div>
    <div class="toolbar">
      <form method="GET" style="display:contents">
        <input type="text" name="q" class="sbox" placeholder="Search action, user…" value="<?php echo htmlspecialchars($q); ?>">
        <select name="inst" class="fsel" onchange="this.form.submit()">
          <option value="">All Schools</option>
          <?php foreach($insts as $i): ?><option value="<?php echo $i['id']; ?>" <?php echo $inst===$i['id']?'selected':''; ?>><?php echo htmlspecialchars($i['name']); ?></option><?php endforeach; ?>
        </select>
        <select name="action" class="fsel" onchange="this.form.submit()">
          <option value="">All Actions</option>
          <?php foreach($actions as $a): ?><option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action===$a?'selected':''; ?>><?php echo htmlspecialchars($a); ?></option><?php endforeach; ?>
        </select>
        <button type="submit" style="padding:.5rem .9rem;background:var(--surface);border:1px solid var(--border);color:var(--muted);border-radius:2px;cursor:pointer;font-size:.8rem">Filter</button>
        <?php if($q||$inst||$action): ?><a href="activity.php" style="font-size:.78rem;color:var(--muted);padding:.5rem">Clear</a><?php endif; ?>
      </form>
    </div>
  </div>
  <div class="sec">
    <?php if($logs): ?>
    <div class="thead"><span>Time</span><span>Action</span><span>User</span><span>Institution</span></div>
    <div class="feed">
      <?php foreach($logs as $l): ?>
      <div class="fi">
        <div class="fi-time"><?php echo date('d M Y', strtotime($l['created_at'])); ?><br><?php echo date('H:i:s', strtotime($l['created_at'])); ?></div>
        <div>
          <div class="fi-action"><?php echo htmlspecialchars($l['action']); ?></div>
          <div class="fi-detail">
            <?php if($l['target_type']): ?>
              <?php echo htmlspecialchars($l['target_type']); ?> #<?php echo $l['target_id']; ?>
            <?php endif; ?>
            <?php if($l['detail']): ?> — <?php echo htmlspecialchars($l['detail']); ?><?php endif; ?>
            <?php if($l['ip_address']): ?> <span style="color:var(--border)">·</span> <?php echo htmlspecialchars($l['ip_address']); ?><?php endif; ?>
          </div>
        </div>
        <div class="fi-user">
          <div class="fi-uname"><?php echo htmlspecialchars($l['full_name']); ?></div>
          <span class="badge r-<?php echo $l['role']; ?>"><?php echo $l['role']; ?></span>
        </div>
        <div class="fi-inst"><?php echo strtoupper(htmlspecialchars($l['inst_slug']??'')); ?><div style="font-size:.68rem;color:var(--muted);font-family:'DM Sans',sans-serif;letter-spacing:0"><?php echo htmlspecialchars($l['inst_name']??''); ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if($pages>1): ?>
    <div class="pag">
      <?php for($i=1;$i<=$pages;$i++): ?><a href="?q=<?php echo urlencode($q); ?>&inst=<?php echo $inst; ?>&action=<?php echo urlencode($action); ?>&page=<?php echo $i; ?>" class="<?php echo $i===$page?'on':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
      <span class="pag-info">Page <?php echo $page; ?> of <?php echo $pages; ?> · <?php echo number_format($total); ?> events</span>
    </div>
    <?php endif; ?>
    <?php else: ?><div class="empty">No activity logged yet.</div><?php endif; ?>
  </div>
</main>
</div>
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
<script>
</script>
</body></html>
