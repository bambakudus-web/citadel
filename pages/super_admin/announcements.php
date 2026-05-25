<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/brevo_mail.php';
guardSuperAdmin();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'broadcast') {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $target  = $_POST['target'] ?? 'all'; // all, admins, free, pro
        if (!$subject || !$message) { echo json_encode(['ok'=>false,'msg'=>'Subject and message required']); exit; }

        // Get target emails
        $where = "u.role='admin' AND u.is_active=1 AND i.is_active=1";
        if ($target === 'free')       $where .= " AND i.plan='free'";
        elseif ($target === 'pro')    $where .= " AND i.plan='pro'";
        elseif ($target === 'enterprise') $where .= " AND i.plan='enterprise'";

        $rows = $pdo->query("SELECT u.email, u.full_name, i.name AS inst_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE $where")->fetchAll();

        $sent = 0; $failed = 0;
        foreach ($rows as $r) {
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
            $html = "
            <div style='font-family:sans-serif;background:#060910;color:#e8eaf0;padding:2rem;border-radius:4px'>
                <div style='font-family:Georgia,serif;font-size:1.2rem;color:#c9a84c;letter-spacing:4px;margin-bottom:1rem'>CITADEL</div>
                <h2 style='color:#e8eaf0;margin-bottom:.8rem'>".htmlspecialchars($subject)."</h2>
                <p style='color:#6b7a8d;margin-bottom:.5rem'>Hi ".htmlspecialchars($r['full_name']).",</p>
                <div style='background:#0c1018;border:1px solid #1a2535;border-left:3px solid #c9a84c;padding:1rem 1.2rem;margin:1.2rem 0;border-radius:2px;color:#e8eaf0;line-height:1.6'>".nl2br(htmlspecialchars($message))."</div>
                <p style='color:#6b7a8d;font-size:.8rem;margin-top:1rem'>— Citadel Platform Team</p>
            </div>";
            $r2 = sendBrevoEmail($r['email'], $r['full_name'], $subject, $html);
            if ($r2['success'] ?? false) $sent++; else $failed++;
        }
        audit('BROADCAST', 'platform', 0, "sent=$sent,failed=$failed,target=$target");
        echo json_encode(['ok'=>true, 'sent'=>$sent, 'failed'=>$failed]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown']); exit;
}

// Get stats for targeting
$counts = [
    'all'        => (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.role='admin' AND u.is_active=1 AND i.is_active=1")->fetchColumn(),
    'free'       => (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.role='admin' AND u.is_active=1 AND i.plan='free' AND i.is_active=1")->fetchColumn(),
    'pro'        => (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.role='admin' AND u.is_active=1 AND i.plan='pro' AND i.is_active=1")->fetchColumn(),
    'enterprise' => (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.role='admin' AND u.is_active=1 AND i.plan='enterprise' AND i.is_active=1")->fetchColumn(),
];

// Recent broadcasts from audit log
$broadcasts = $pdo->query("SELECT * FROM audit_log WHERE action='BROADCAST' ORDER BY created_at DESC LIMIT 20")->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Citadel — Announcements</title>
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
.wrap2{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem}
.sec{background:var(--surface);border:1px solid var(--border);border-radius:2px}
.sh{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
.sb{padding:1.4rem}
.df{margin-bottom:1rem}.df label{display:block;font-size:.63rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.38rem}
.df input,.df textarea,.df select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.6rem .85rem;border-radius:2px;font-family:'DM Sans',sans-serif;font-size:.84rem;outline:none}
.df input:focus,.df textarea:focus,.df select:focus{border-color:var(--steel)}
.df textarea{resize:vertical;min-height:140px}
.bg{padding:.6rem 1.2rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.74rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;width:100%;margin-top:.5rem}
.bg:hover{opacity:.88}
.target-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem}
.tcard{background:var(--bg);border:1px solid var(--border);border-radius:2px;padding:.7rem .9rem;cursor:pointer;transition:all .2s}
.tcard:hover,.tcard.sel{border-color:var(--gold);background:rgba(201,168,76,.06)}
.tcard .tc-label{font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.tcard .tc-count{font-size:1.4rem;font-weight:600;color:var(--gold)}
.fi{display:flex;gap:.9rem;align-items:flex-start;padding:.8rem 1.2rem;border-bottom:1px solid rgba(26,37,53,.5)}
.fi:last-child{border-bottom:none}
.fd{width:7px;height:7px;border-radius:50%;background:var(--gold);flex-shrink:0;margin-top:.35rem}
.fa{font-size:.8rem;color:var(--text)}
.fm{font-size:.7rem;color:var(--muted);margin-top:.15rem}
.ft{font-size:.68rem;color:var(--muted);flex-shrink:0;white-space:nowrap}
.empty{text-align:center;padding:2rem;color:var(--muted);font-size:.83rem}
.menu-toggle{display:none}
#toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.65rem 1.1rem;border-radius:2px;font-size:.8rem;z-index:9999;transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
#toast.ok{background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.4);color:var(--success)}
#toast.err{background:rgba(224,92,92,.1);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
@media(max-width:768px){
  #menu-toggle{display:flex!important;align-items:center;justify-content:center;width:36px;height:36px;background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:4px;color:var(--text);cursor:pointer;font-size:18px;margin-bottom:1rem}
  .sidebar{width:260px!important;position:fixed!important;top:0!important;left:0!important;height:100dvh!important;z-index:500!important;transform:translateX(-100%)!important;transition:transform .25s ease!important;box-shadow:4px 0 20px rgba(0,0,0,.8)!important;overflow:hidden!important}
  .sidebar.open{transform:translateX(0)!important}
  .sb-nav{flex:1 1 0!important;overflow-y:auto!important;min-height:0!important}
  .sb-foot{flex-shrink:0!important}
  .main{margin-left:0!important;padding:1rem!important}
  .wrap2{grid-template-columns:1fr!important}
  .target-grid{grid-template-columns:1fr 1fr!important}
}
#sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:499;backdrop-filter:blur(2px)}
#sidebar-overlay.show{display:block}
input,select,textarea{font-size:16px!important}
@media(min-width:769px){input,select,textarea{font-size:unset!important}}
</style>
<script>
function toggleSidebar(){var sb=document.getElementById('sidebar');var ov=document.getElementById('sidebar-overlay');if(!sb)return;var open=sb.classList.toggle('open');if(ov)ov.style.display=open?'block':'none';}
</script>
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
    <a href="announcements.php" class="sb-a on">📣 Announcements</a>
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
  <div style="margin-bottom:1.5rem">
    <div class="pt">Announcements</div>
    <div class="ps">Broadcast messages to school admins</div>
  </div>
  <div class="wrap2">
    <div>
      <div class="sec">
        <div class="sh">Compose Broadcast</div>
        <div class="sb">
          <div class="df"><label>Target Audience</label>
            <div class="target-grid">
              <div class="tcard sel" id="tc-all" onclick="selTarget('all')"><div class="tc-label">All Schools</div><div class="tc-count"><?php echo $counts['all']; ?></div></div>
              <div class="tcard" id="tc-free" onclick="selTarget('free')"><div class="tc-label">Free Plan</div><div class="tc-count"><?php echo $counts['free']; ?></div></div>
              <div class="tcard" id="tc-pro" onclick="selTarget('pro')"><div class="tc-label">Pro Plan</div><div class="tc-count"><?php echo $counts['pro']; ?></div></div>
              <div class="tcard" id="tc-enterprise" onclick="selTarget('enterprise')"><div class="tc-label">Enterprise</div><div class="tc-count"><?php echo $counts['enterprise']; ?></div></div>
            </div>
          </div>
          <div class="df"><label>Subject</label><input type="text" id="ann-subj" placeholder="e.g. New Feature Available"></div>
          <div class="df"><label>Message</label><textarea id="ann-msg" placeholder="Write your announcement here..."></textarea></div>
          <button class="bg" onclick="sendBroadcast()">📣 Send Broadcast</button>
        </div>
      </div>
    </div>
    <div>
      <div class="sec">
        <div class="sh">Broadcast History</div>
        <?php if($broadcasts): ?>
        <?php foreach($broadcasts as $b):
          preg_match('/sent=(\d+),failed=(\d+),target=(\w+)/', $b['details']??'', $m);
        ?>
        <div class="fi">
          <div class="fd"></div>
          <div style="flex:1">
            <div class="fa">Broadcast sent</div>
            <div class="fm">✅ <?php echo $m[1]??'?'; ?> sent · ❌ <?php echo $m[2]??'0'; ?> failed · Target: <?php echo ucfirst($m[3]??'all'); ?></div>
          </div>
          <div class="ft"><?php echo date('d M H:i', strtotime($b['created_at'])); ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?><div class="empty">No broadcasts yet.</div><?php endif; ?>
      </div>
    </div>
  </div>
</main>
</div>
<div id="toast"></div>
<script>
let selTgt='all';
function selTarget(t){selTgt=t;document.querySelectorAll('.tcard').forEach(c=>c.classList.remove('sel'));document.getElementById('tc-'+t).classList.add('sel');}
function toast(m,t='ok'){const el=document.getElementById('toast');el.textContent=m;el.className='show '+t;setTimeout(()=>el.className='',3500)}
async function sendBroadcast(){
  const subj=document.getElementById('ann-subj').value.trim();
  const msg=document.getElementById('ann-msg').value.trim();
  if(!subj||!msg){toast('Subject and message required','err');return;}
  const btn=document.querySelector('.bg');btn.textContent='Sending...';btn.disabled=true;
  const r=await(await fetch('announcements.php',{method:'POST',body:new URLSearchParams({action:'broadcast',subject:subj,message:msg,target:selTgt})})).json();
  btn.textContent='📣 Send Broadcast';btn.disabled=false;
  if(r.ok){toast(`Sent to ${r.sent} schools!${r.failed?' ('+r.failed+' failed)':''}`);document.getElementById('ann-msg').value='';setTimeout(()=>location.reload(),2000);}
  else toast(r.msg||'Error','err');
}
</script>
</body></html>
