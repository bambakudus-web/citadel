<?php
require_once __DIR__ . '/../../includes/guard.php';
guardSuperAdmin();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['id']??0);
    if ($action==='toggle_user') {
        $val=(int)($_POST['val']??0);
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$val,$id]);
        audit('TOGGLE_USER','user',$id,"is_active=$val");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action==='unlock_user') {
        $pdo->prepare("UPDATE users SET is_locked=0, login_attempts=0 WHERE id=?")->execute([$id]);
        audit('UNLOCK_USER','user',$id);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action==='reset_password') {
        $pass=trim($_POST['password']??'');
        if(strlen($pass)<6){echo json_encode(['ok'=>false,'msg'=>'Password too short.']);exit;}
        $hash=password_hash($pass,PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash=?, is_locked=0, login_attempts=0 WHERE id=?")->execute([$hash,$id]);
        audit('RESET_PASSWORD','user',$id);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action==='delete_user') {
        if($id===$_SESSION['user_id']){echo json_encode(['ok'=>false,'msg'=>"Can't delete yourself."]);exit;}
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        audit('DELETE_USER','user',$id);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown']); exit;
}

// Filters
$q     = trim($_GET['q']??'');
$role  = $_GET['role']??'';
$inst  = (int)($_GET['inst']??0);
$page  = max(1,(int)($_GET['page']??1));
$limit = 30;
$offset= ($page-1)*$limit;

$w=[]; $p=[];
if($q){$w[]="(u.full_name LIKE ? OR u.email LIKE ? OR u.index_no LIKE ?)";$x="%$q%";$p=[$x,$x,$x];}
if($role){$w[]="u.role=?";$p[]=$role;}
if($inst){$w[]="u.institution_id=?";$p[]=$inst;}
$where=$w?"WHERE ".implode(" AND ",$w):"";

$total=(int)$pdo->prepare("SELECT COUNT(*) FROM users u $where")->execute($p) ? $pdo->prepare("SELECT COUNT(*) FROM users u $where")->execute($p) : 0;
$cnt=$pdo->prepare("SELECT COUNT(*) FROM users u $where");
$cnt->execute($p); $total=(int)$cnt->fetchColumn();
$pages=ceil($total/$limit);

$stmt=$pdo->prepare("
    SELECT u.*, i.name AS inst_name, i.slug AS inst_slug
    FROM users u
    LEFT JOIN institutions i ON i.id=u.institution_id
    $where
    ORDER BY u.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($p);
$users=$stmt->fetchAll();

$insts=$pdo->query("SELECT id,name,slug FROM institutions ORDER BY name")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — All Users</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c;--warn:#e09a3c}
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
.sbox{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .85rem;border-radius:2px;font-size:.84rem;outline:none;width:200px;transition:border-color .2s}.sbox:focus{border-color:var(--steel)}
.fsel{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .75rem;border-radius:2px;font-size:.8rem;cursor:pointer}
.sec{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);padding:.65rem .9rem;text-align:left;border-bottom:1px solid var(--border)}
.tbl td{padding:.7rem .9rem;font-size:.83rem;border-bottom:1px solid rgba(26,37,53,.5);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-block;padding:.18rem .5rem;border-radius:2px;font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;font-weight:600}
.r-student{background:rgba(74,111,165,.15);color:#7aabf5}
.r-lecturer{background:rgba(138,111,212,.15);color:#b09ae8}
.r-admin{background:rgba(201,168,76,.15);color:var(--gold)}
.r-rep{background:rgba(76,175,130,.15);color:var(--success)}
.r-super_admin{background:rgba(224,92,92,.15);color:var(--danger)}
.b-active{background:rgba(76,175,130,.12);color:var(--success)}
.b-inactive{background:rgba(107,122,141,.12);color:var(--muted)}
.b-locked{background:rgba(224,92,92,.12);color:var(--danger)}
.tog{position:relative;display:inline-block;width:34px;height:18px;cursor:pointer}.tog input{opacity:0;width:0;height:0}
.tsl{position:absolute;inset:0;background:#1a2535;border-radius:18px;transition:.2s}
.tsl::before{content:'';position:absolute;width:12px;height:12px;left:3px;top:3px;background:var(--muted);border-radius:50%;transition:.2s}
.tog input:checked+.tsl{background:rgba(76,175,130,.3)}.tog input:checked+.tsl::before{background:var(--success);transform:translateX(16px)}
.ab{background:none;border:none;color:var(--muted);cursor:pointer;padding:.28rem .45rem;border-radius:2px;font-size:.76rem;transition:all .2s}
.ab:hover{background:rgba(255,255,255,.05);color:var(--text)}.ab.del:hover{color:var(--danger)}.ab.warn:hover{color:var(--warn)}
.empty{text-align:center;padding:2.5rem;color:var(--muted);font-size:.83rem}
.pag{display:flex;align-items:center;gap:.5rem;padding:1rem 1.3rem;border-top:1px solid var(--border);flex-wrap:wrap}
.pag a{padding:.35rem .7rem;border:1px solid var(--border);border-radius:2px;font-size:.78rem;color:var(--muted);transition:all .2s}
.pag a:hover{border-color:var(--gold);color:var(--gold)}.pag a.on{border-color:var(--gold);color:var(--gold);background:rgba(201,168,76,.08)}
.pag-info{font-size:.75rem;color:var(--muted);margin-left:auto}
/* Modal */
.mov{position:fixed;inset:0;background:rgba(4,6,14,.85);z-index:200;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.mov.open{opacity:1;pointer-events:all}
.mod{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:2rem;position:relative;transform:translateY(20px);transition:transform .25s}
.mov.open .mod{transform:translateY(0)}
.mod-title{font-family:'Cinzel',serif;font-size:.95rem;color:var(--gold);letter-spacing:.1em;margin-bottom:1.2rem}
.mod-close{position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.1rem}
.mf{margin-bottom:.9rem}.mf label{display:block;font-size:.63rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.38rem}
.mf input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.6rem .85rem;border-radius:2px;font-family:'DM Sans',sans-serif;font-size:.84rem;outline:none}
.mf input:focus{border-color:var(--steel)}
.bg{padding:.55rem 1.1rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.74rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;width:100%;margin-top:.3rem}
.bg:hover{opacity:.88}
#toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.65rem 1.1rem;border-radius:2px;font-size:.8rem;z-index:9999;transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
#toast.ok{background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.4);color:var(--success)}
#toast.err{background:rgba(224,92,92,.1);border:1px solid rgba(224,92,92,.3);color:var(--danger)}

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
  .tbl{font-size:.72rem!important;min-width:0!important;width:100%!important}
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

/* SIDEBAR SCROLL FIX */
.sidebar{display:flex!important;flex-direction:column!important}
.sidebar-nav,.sb-nav{flex:1!important;overflow-y:auto!important;min-height:0!important}
.sidebar-user,.sidebar-footer,.sb-foot{flex-shrink:0!important;margin-top:auto!important}

/* SIGNOUT ALWAYS VISIBLE */
.sidebar{overflow:hidden!important;-webkit-overflow-scrolling:touch!important;scrollbar-width:none!important}
.sidebar::-webkit-scrollbar{display:none!important}

.sidebar-user,.sidebar-footer,.sb-foot{padding-bottom:env(safe-area-inset-bottom,20px)!important}
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
<aside class="sidebar" id="sidebar">
  <div class="sb-brand"><div><div class="sb-logo">CITADEL</div><div class="sb-role">Super Admin</div></div></div>
  <nav class="sb-nav">
    <div class="sb-sec">Platform</div>
    <a href="dashboard.php" class="sb-a">📊 Overview</a>
    <a href="schools.php"   class="sb-a">🏫 Schools</a>
    <a href="users.php"     class="sb-a on">👥 All Users</a>
    <a href="activity.php" class="sb-a">📋 Activity Log</a>
    <div class="sb-sec">Tools</div>
    <a href="announcements.php" class="sb-a">📣 Announcements</a>
    <a href="export.php" class="sb-a">💾 Export Data</a>
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
  <div class="ph">
    <div><div class="pt">All Users</div><div class="ps"><?php echo number_format($total); ?> user<?php echo $total!=1?'s':''; ?> found</div></div>
    <div class="toolbar">
      <form method="GET" style="display:contents">
        <input type="text" name="q" class="sbox" placeholder="Search name, email, index…" value="<?php echo htmlspecialchars($q); ?>">
        <select name="role" class="fsel" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <?php foreach(['student','lecturer','admin','rep','super_admin'] as $r): ?>
          <option value="<?php echo $r; ?>" <?php echo $role===$r?'selected':''; ?>><?php echo ucfirst($r); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="inst" class="fsel" onchange="this.form.submit()">
          <option value="">All Schools</option>
          <?php foreach($insts as $i): ?>
          <option value="<?php echo $i['id']; ?>" <?php echo $inst===$i['id']?'selected':''; ?>><?php echo htmlspecialchars($i['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
  <div class="sec">
    <?php if($users): ?>
    <div style="overflow-x:auto"><table class="tbl">
      <thead><tr><th>User</th><th class="hide-col-mobile">Index / Email</th><th>Role</th><th class="hide-col-mobile">School</th><th class="hide-col-mobile">Status</th><th>Active</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($users as $u): ?>
      <?php
        $status = $u['is_locked'] ? 'locked' : ($u['is_active'] ? 'active' : 'inactive');
        $statusLabel = $u['is_locked'] ? '🔒 Locked' : ($u['is_active'] ? 'Active' : 'Inactive');
        $statusClass = 'b-'.$status;
      ?>
      <tr id="ur-<?php echo $u['id']; ?>">
        <td>
          <div style="font-weight:500"><?php echo htmlspecialchars($u['full_name']); ?></div>
          <div style="font-size:.7rem;color:var(--muted)"><?php echo htmlspecialchars($u['email']); ?></div>
        </td>
        <td class="hide-col-mobile" style="font-size:.78rem;color:var(--muted)"><?php echo htmlspecialchars($u['index_no']??'—'); ?></td>
        <td><span class="badge r-<?php echo $u['role']; ?>"><?php echo $u['role']; ?></span></td>
        <td class="hide-col-mobile" style="font-size:.78rem">
          <span style="color:var(--gold);font-family:'Cinzel',serif;letter-spacing:.08em"><?php echo strtoupper(htmlspecialchars($u['inst_slug']??'')); ?></span>
          <div style="font-size:.68rem;color:var(--muted)"><?php echo htmlspecialchars($u['inst_name']??''); ?></div>
        </td>
        <td class="hide-col-mobile"><span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
        <td>
          <label class="tog">
            <input type="checkbox" <?php echo $u['is_active']?'checked':''; ?> onchange="togUser(<?php echo $u['id']; ?>,this.checked?1:0,this)">
            <span class="tsl"></span>
          </label>
        </td>
        <td style="white-space:nowrap">
          <?php if($u['is_locked']): ?>
          <button class="ab warn" title="Unlock" onclick="unlockUser(<?php echo $u['id']; ?>)">🔓</button>
          <?php endif; ?>
          <button class="ab" title="Reset password" onclick="openReset(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">🔑</button>
          <?php if($u['id']!=$_SESSION['user_id']): ?>
          <button class="ab del" title="Delete" onclick="delUser(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">🗑</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if($pages>1): ?>
    <div class="pag">
      <?php for($i=1;$i<=$pages;$i++): ?>
      <a href="?q=<?php echo urlencode($q); ?>&role=<?php echo urlencode($role); ?>&inst=<?php echo $inst; ?>&page=<?php echo $i; ?>" class="<?php echo $i===$page?'on':''; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
      <span class="pag-info">Page <?php echo $page; ?> of <?php echo $pages; ?> &middot; <?php echo number_format($total); ?> users</span>
    </div>
    <?php endif; ?>
    <?php else: ?><div class="empty">No users found.</div><?php endif; ?>
  </div>
</main>
</div>

<!-- Reset password modal -->
<div class="mov" id="mov" onclick="if(event.target===this)closeMod()">
  <div class="mod">
    <button class="mod-close" onclick="closeMod()">✕</button>
    <div class="mod-title">Reset Password</div>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:1.2rem">Setting new password for <strong id="modName" style="color:var(--text)"></strong></div>
    <input type="hidden" id="modId">
    <div class="mf"><label>New Password</label><input type="password" id="modPass" placeholder="Min. 6 characters"></div>
    <button class="bg" onclick="doReset()">Set Password</button>
  </div>
</div>

<div id="toast"></div>
<script>
function toast(m,t='ok'){const el=document.getElementById('toast');el.textContent=m;el.className='show '+t;setTimeout(()=>el.className='',3000)}
async function post(d){return(await fetch('users.php',{method:'POST',body:new URLSearchParams(d)})).json()}
async function togUser(id,val,el){
  const r=await post({action:'toggle_user',id,val});
  if(r.ok){toast(val?'User activated.':'User deactivated.');}
  else{toast(r.msg||'Error','err');el.checked=!el.checked;}
}
async function unlockUser(id){
  const r=await post({action:'unlock_user',id});
  if(r.ok){toast('User unlocked.');setTimeout(()=>location.reload(),800);}
  else toast(r.msg||'Error','err');
}
async function delUser(id,name){
  if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
  const r=await post({action:'delete_user',id});
  if(r.ok){document.getElementById('ur-'+id)?.remove();toast('Deleted.');}
  else toast(r.msg||'Error','err');
}
function openReset(id,name){
  document.getElementById('modId').value=id;
  document.getElementById('modName').textContent=name;
  document.getElementById('modPass').value='';
  document.getElementById('mov').classList.add('open');
  setTimeout(()=>document.getElementById('modPass').focus(),200);
}
function closeMod(){document.getElementById('mov').classList.remove('open')}
async function doReset(){
  const id=document.getElementById('modId').value;
  const pass=document.getElementById('modPass').value;
  const r=await post({action:'reset_password',id,password:pass});
  if(r.ok){toast('Password updated.');closeMod();}
  else toast(r.msg||'Error','err');
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
<script>
</script>
</body></html>
