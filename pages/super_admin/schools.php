<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/brevo_mail.php';
guardSuperAdmin();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
    if ($action==='toggle_school'){
        $val=(int)($_POST['val']??0);
        $pdo->prepare("UPDATE institutions SET is_active=? WHERE id=?")->execute([$val,$id]);
        audit('TOGGLE_SCHOOL','institution',$id,"is_active=$val");
        // Send activation email to school admin
        if ($val === 1) {
            try {
                $adminRow = $pdo->prepare("SELECT u.email, u.full_name, u.password_hash, i.name AS inst_name, i.slug FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.institution_id=? AND u.role='admin' LIMIT 1");
                $adminRow->execute([$id]);
                $admin = $adminRow->fetch();
                if ($admin && filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                    $html = "
                    <div style='font-family:sans-serif;background:#060910;color:#e8eaf0;padding:2rem;border-radius:4px'>
                        <div style='font-family:Georgia,serif;font-size:1.2rem;color:#c9a84c;letter-spacing:4px;margin-bottom:1rem'>CITADEL</div>
                        <h2 style='color:#4caf82;margin-bottom:.8rem'>✓ School Approved!</h2>
                        <p style='color:#6b7a8d'>Hi {$admin['full_name']}, your institution <strong style='color:#e8eaf0'>{$admin['inst_name']}</strong> has been approved on Citadel.</p>
                        <div style='background:#0c1018;border:1px solid #1a2535;padding:1rem 1.2rem;margin:1.2rem 0;border-radius:2px'>
                            <div style='margin-bottom:.6rem'><span style='color:#6b7a8d;font-size:.8rem'>School Code</span><br><span style='color:#c9a84c;font-size:1.3rem;font-family:Georgia,serif;letter-spacing:4px'>".strtoupper($admin['slug'])."</span></div>
                            <div><span style='color:#6b7a8d;font-size:.8rem'>Login URL</span><br><span style='color:#e8eaf0'>https://citadel-production-5edc.up.railway.app</span></div>
                        </div>
                        <a href='https://citadel-production-5edc.up.railway.app' style='display:inline-block;background:linear-gradient(135deg,#7a5f28,#c9a84c);color:#060910;padding:12px 24px;border-radius:2px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:2px'>Login to Citadel</a>
                    </div>";
                    sendBrevoEmail($admin['email'], $admin['full_name'], 'Your School Has Been Approved — Citadel', $html);
                }
            } catch(Exception $e) {}
        }
        echo json_encode(['ok'=>true]);exit;
    }
    if ($action==='change_plan'){$plan=$_POST['plan']??'free';if(!in_array($plan,['free','pro','enterprise'])){echo json_encode(['ok'=>false,'msg'=>'Bad plan']);exit;}$pdo->prepare("UPDATE institutions SET plan=? WHERE id=?")->execute([$plan,$id]);audit('CHANGE_PLAN','institution',$id,"plan=$plan");echo json_encode(['ok'=>true]);exit;}
    if ($action==='delete_school'){if($id===1){echo json_encode(['ok'=>false,'msg'=>'Cannot delete default.']);exit;}$pdo->prepare("DELETE FROM institutions WHERE id=?")->execute([$id]);audit('DELETE_SCHOOL','institution',$id);echo json_encode(['ok'=>true]);exit;}
    if ($action==='update_school'){$pdo->prepare("UPDATE institutions SET name=?,email=?,phone=?,address=? WHERE id=?")->execute([trim($_POST['name']??''),trim($_POST['email']??''),trim($_POST['phone']??''),trim($_POST['address']??''),$id]);audit('UPDATE_SCHOOL','institution',$id);echo json_encode(['ok'=>true]);exit;}
    echo json_encode(['ok'=>false,'msg'=>'Unknown']); exit;
}

$q=trim($_GET['q']??''); $pf=$_GET['plan']??''; $viewId=(int)($_GET['view']??0);
$w=[]; $p=[];
if($q){$w[]="(i.name LIKE ? OR i.slug LIKE ? OR i.email LIKE ?)";$x="%$q%";$p=[$x,$x,$x];}
if($pf){$w[]="i.plan=?";$p[]=$pf;}
$sql="SELECT i.*,
  (SELECT COUNT(*) FROM users u WHERE u.institution_id=i.id AND u.role='student') AS student_count,
  (SELECT COUNT(*) FROM users u WHERE u.institution_id=i.id AND u.role='lecturer') AS lecturer_count,
  (SELECT COUNT(*) FROM sessions ss JOIN users u2 ON u2.id=ss.lecturer_id WHERE u2.institution_id=i.id) AS session_count
  FROM institutions i ".($w?"WHERE ".implode(" AND ",$w):"")." ORDER BY i.created_at DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($p);$schools=$stmt->fetchAll();
$vs=null;if($viewId){$s2=$pdo->prepare("SELECT * FROM institutions WHERE id=?");$s2->execute([$viewId]);$vs=$s2->fetch();}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Schools</title>
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
.sb-foot{padding:1rem 1.3rem;border-top:1px solid var(--border)}.sb-av{width:32px;height:32px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;color:#060910}
.sb-out{display:block;margin-top:.7rem;font-size:.74rem;color:var(--muted)}.sb-out:hover{color:var(--danger)}
.main{margin-left:230px;flex:1;padding:2rem 2.2rem}
.ph{margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.pt{font-family:'Cinzel',serif;font-size:1.4rem;letter-spacing:.08em}.ps{font-size:.8rem;color:var(--muted);margin-top:.2rem}
.toolbar{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap}
.sbox{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .85rem;border-radius:2px;font-size:.84rem;outline:none;width:200px}.sbox:focus{border-color:var(--steel)}
.fsel{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .75rem;border-radius:2px;font-size:.8rem;cursor:pointer}
.bg{padding:.5rem 1rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.74rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;text-decoration:none;display:inline-block}.bg:hover{opacity:.88}
.sec{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.tbl{width:100%;border-collapse:collapse}.tbl th{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);padding:.65rem .9rem;text-align:left;border-bottom:1px solid var(--border)}
.tbl td{padding:.7rem .9rem;font-size:.83rem;border-bottom:1px solid rgba(26,37,53,.5);vertical-align:middle}.tbl tr:last-child td{border-bottom:none}.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-block;padding:.18rem .5rem;border-radius:2px;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;font-weight:600}
.bf{background:rgba(107,122,141,.15);color:var(--muted)}.bp{background:rgba(74,111,165,.15);color:#7aabf5}.be{background:rgba(201,168,76,.15);color:var(--gold)}
.ba{background:rgba(76,175,130,.12);color:var(--success)}.bi{background:rgba(224,92,92,.12);color:var(--danger)}
.psel{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.28rem .55rem;border-radius:2px;font-size:.76rem;cursor:pointer}
.tog{position:relative;display:inline-block;width:34px;height:18px;cursor:pointer}.tog input{opacity:0;width:0;height:0}
.tsl{position:absolute;inset:0;background:#1a2535;border-radius:18px;transition:.2s}.tsl::before{content:'';position:absolute;width:12px;height:12px;left:3px;top:3px;background:var(--muted);border-radius:50%;transition:.2s}
.tog input:checked+.tsl{background:rgba(76,175,130,.3)}.tog input:checked+.tsl::before{background:var(--success);transform:translateX(16px)}
.ab{background:none;border:none;color:var(--muted);cursor:pointer;padding:.28rem .45rem;border-radius:2px;font-size:.76rem;transition:all .2s}
.ab:hover{background:rgba(255,255,255,.05);color:var(--text)}.ab.del:hover{color:var(--danger)}
.empty{text-align:center;padding:2.5rem;color:var(--muted);font-size:.83rem}
.dov{position:fixed;inset:0;background:rgba(4,6,14,.8);z-index:200;opacity:0;pointer-events:none;transition:opacity .25s}
.dov.open{opacity:1;pointer-events:all}
.drw{position:fixed;right:0;top:0;height:100vh;width:400px;max-width:100vw;background:var(--surface);border-left:1px solid var(--border);z-index:201;transform:translateX(100%);transition:transform .3s;padding:2rem;overflow-y:auto}
.drw.open{transform:translateX(0)}
.dt{font-family:'Cinzel',serif;font-size:.95rem;color:var(--gold);letter-spacing:.1em;margin-bottom:1.4rem;display:flex;align-items:center;justify-content:space-between}
.dc{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.1rem}
.df{margin-bottom:.9rem}.df label{display:block;font-size:.63rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.38rem}
.df input,.df textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.6rem .85rem;border-radius:2px;font-family:'DM Sans',sans-serif;font-size:.84rem;outline:none}.df input:focus,.df textarea:focus{border-color:var(--steel)}
.df textarea{resize:vertical;min-height:65px}
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
.sidebar{overflow-y:auto!important;overflow-x:hidden!important;-webkit-overflow-scrolling:touch!important;scrollbar-width:none!important}
.sidebar::-webkit-scrollbar{display:none!important}
.sidebar-nav,.sb-nav{overflow:visible!important}
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
<aside class="sidebar">
  <div class="sb-brand"><div><div class="sb-logo">CITADEL</div><div class="sb-role">Super Admin</div></div></div>
  <nav class="sb-nav">
    <div class="sb-sec">Platform</div>
    <a href="dashboard.php" class="sb-a">📊 Overview</a>
    <a href="schools.php"   class="sb-a on">🏫 Schools</a>
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
    <div><div class="pt">Schools</div><div class="ps"><?php echo count($schools); ?> institution<?php echo count($schools)!=1?'s':''; ?></div></div>
    <div class="toolbar">
      <form method="GET" style="display:contents">
        <input type="text" name="q" class="sbox" placeholder="Search name, code, email…" value="<?php echo htmlspecialchars($q); ?>">
        <select name="plan" class="fsel" onchange="this.form.submit()">
          <option value="">All Plans</option>
          <?php foreach(['free','pro','enterprise'] as $pp): ?><option value="<?php echo $pp; ?>" <?php echo $pf===$pp?'selected':''; ?>><?php echo ucfirst($pp); ?></option><?php endforeach; ?>
        </select>
      </form>
      <a href="../../onboard.php" class="bg">+ Add School</a>
    </div>
  </div>
  <div class="sec">
    <?php if($schools): ?>
    <div style="overflow-x:auto"><table class="tbl">
      <thead><tr><th>Institution</th><th>Code</th><th>Plan</th><th>Students</th><th>Lecturers</th><th>Status</th><th>Active</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($schools as $s): ?>
      <tr id="row-<?php echo $s['id']; ?>">
        <td><div style="font-weight:500"><?php echo htmlspecialchars($s['name']); ?></div><div style="font-size:.7rem;color:var(--muted)"><?php echo htmlspecialchars($s['email']??''); ?></div></td>
        <td><span style="font-family:'Cinzel',serif;color:var(--gold);letter-spacing:.1em;font-size:.8rem"><?php echo strtoupper(htmlspecialchars($s['slug']??'')); ?></span></td>
        <td><select class="psel" onchange="chPlan(<?php echo $s['id']; ?>,this.value)"><?php foreach(['free','pro','enterprise'] as $pp): ?><option value="<?php echo $pp; ?>" <?php echo ($s['plan']??'free')===$pp?'selected':''; ?>><?php echo ucfirst($pp); ?></option><?php endforeach; ?></select></td>
        <td><?php echo number_format($s['student_count']); ?></td>
        <td><?php echo number_format($s['lecturer_count']); ?></td>
        <td><span class="badge <?php echo $s['is_active']?'ba':'bi'; ?>"><?php echo $s['is_active']?'Active':'Inactive'; ?></span></td>
        <td><label class="tog"><input type="checkbox" <?php echo $s['is_active']?'checked':''; ?> onchange="togSchool(<?php echo $s['id']; ?>,this.checked?1:0,this)"><span class="tsl"></span></label></td>
        <td style="white-space:nowrap">
          <button class="ab" onclick="openDrw(<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES); ?>)">✏️</button>
          <?php if($s['id']!=1): ?><button class="ab del" onclick="delSchool(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars(addslashes($s['name'])); ?>')">🗑</button><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><div class="empty">No schools found. <a href="../../onboard.php" style="color:var(--gold)">Register one →</a></div><?php endif; ?>
  </div>
</main>
</div>
<div class="dov" id="dov" onclick="closeDrw()"></div>
<div class="drw" id="drw">
  <div class="dt">Edit Institution <button class="dc" onclick="closeDrw()">✕</button></div>
  <input type="hidden" id="dId">
  <div class="df"><label>Name</label><input type="text" id="dName"></div>
  <div class="df"><label>Email</label><input type="email" id="dEmail"></div>
  <div class="df"><label>Phone</label><input type="text" id="dPhone"></div>
  <div class="df"><label>Address</label><textarea id="dAddr"></textarea></div>
  <button class="bg" style="width:100%;margin-top:.5rem" onclick="saveSchool()">Save Changes</button>
</div>
<div id="toast"></div>
<script>
function toast(m,t='ok'){const el=document.getElementById('toast');el.textContent=m;el.className='show '+t;setTimeout(()=>el.className='',3000)}
async function post(d){return(await fetch('schools.php',{method:'POST',body:new URLSearchParams(d)})).json()}
async function togSchool(id,val,el){const r=await post({action:'toggle_school',id,val});if(r.ok){const b=document.querySelector('#row-'+id+' .badge');if(b){b.className='badge '+(val?'ba':'bi');b.textContent=val?'Active':'Inactive';}toast(val?'Activated.':'Deactivated.');}else{toast(r.msg||'Error','err');el.checked=!el.checked;}}
async function chPlan(id,plan){const r=await post({action:'change_plan',id,plan});r.ok?toast('Plan updated.'):toast(r.msg||'Error','err')}
async function delSchool(id,name){if(!confirm('Delete "'+name+'"?\n\nRemoves ALL data. Cannot undo.'))return;const r=await post({action:'delete_school',id});if(r.ok){document.getElementById('row-'+id)?.remove();toast('Deleted.');}else toast(r.msg||'Error','err')}
function openDrw(s){document.getElementById('dId').value=s.id;document.getElementById('dName').value=s.name||'';document.getElementById('dEmail').value=s.email||'';document.getElementById('dPhone').value=s.phone||'';document.getElementById('dAddr').value=s.address||'';document.getElementById('dov').classList.add('open');document.getElementById('drw').classList.add('open')}
function closeDrw(){document.getElementById('dov').classList.remove('open');document.getElementById('drw').classList.remove('open')}
async function saveSchool(){const r=await post({action:'update_school',id:document.getElementById('dId').value,name:document.getElementById('dName').value,email:document.getElementById('dEmail').value,phone:document.getElementById('dPhone').value,address:document.getElementById('dAddr').value});if(r.ok){toast('Saved.');closeDrw();setTimeout(()=>location.reload(),800);}else toast(r.msg||'Error','err')}
<?php if($vs): ?>openDrw(<?php echo json_encode($vs); ?>);<?php endif; ?>
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
