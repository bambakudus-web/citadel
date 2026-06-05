<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/brevo_mail.php';
requireLogin();

$user   = currentUser();
$userId = $user['id'];
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'change_password';

    if ($action === 'update_email') {
        $newEmail = trim(strtolower($_POST['new_email'] ?? ''));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email address.'; $msgType = 'error';
        } else {
            // Check not taken
            $taken = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $taken->execute([$newEmail, $userId]);
            if ($taken->fetch()) {
                $msg = 'That email is already in use.'; $msgType = 'error';
            } else {
                $pdo->prepare("UPDATE users SET email=? WHERE id=?")->execute([$newEmail, $userId]);
                $msg = 'Email updated successfully!'; $msgType = 'success';
                // Refresh userData
                $userData = $pdo->prepare("SELECT full_name, email, phone, role, index_no FROM users WHERE id=?");
                $userData->execute([$userId]); $userData = $userData->fetch();
            }
        }
    } elseif ($action === 'update_phone') {
        $phone = trim($_POST['phone'] ?? '');
        $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone, $userId]);
        $msg = 'Phone number updated!'; $msgType = 'success';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $msg = 'All fields are required.'; $msgType = 'error';
        } elseif (strlen($new) < 8) {
            $msg = 'New password must be at least 8 characters.'; $msgType = 'error';
        } elseif ($new !== $confirm) {
            $msg = 'Passwords do not match.'; $msgType = 'error';
        } else {
            $row = $pdo->prepare("SELECT password_hash, email, full_name FROM users WHERE id=?");
            $row->execute([$userId]); $row = $row->fetch();
            if (!password_verify($current, $row['password_hash'])) {
                $msg = 'Current password is incorrect.'; $msgType = 'error';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $userId]);
                // Send confirmation email
                if ($row['email'] && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $html = "
                    <div style='font-family:sans-serif;background:#060910;color:#e8eaf0;padding:2rem;border-radius:4px'>
                        <div style='font-family:Georgia,serif;font-size:1.2rem;color:#c9a84c;letter-spacing:4px;margin-bottom:1rem'>CITADEL</div>
                        <h2 style='color:#e8eaf0;margin-bottom:.8rem'>Password Changed</h2>
                        <p style='color:#6b7a8d'>Hi {$row['full_name']}, your Citadel password was changed successfully.</p>
                        <p style='color:#6b7a8d;margin-top:.8rem'>If you did not make this change, contact your administrator immediately.</p>
                        <p style='color:#6b7a8d;font-size:.8rem;margin-top:1.5rem'>Time: " . date('d M Y H:i') . "</p>
                    </div>";
                    try { sendBrevoEmail($row['email'], $row['full_name'], 'Password Changed — Citadel', $html); } catch(Exception $e){}
                }
                $msg = 'Password changed successfully!'; $msgType = 'success';
            }
        }
    }
}

// Get current user data
$userData = $pdo->prepare("SELECT full_name, email, phone, role, index_no FROM users WHERE id=?");
$userData->execute([$userId]); $userData = $userData->fetch();

$role = $user['role'] ?? 'student';
$map  = ['super_admin'=>'super_admin','admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
$dashUrl = 'pages/' . ($map[$role] ?? 'student') . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Citadel — Change Password</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{min-height:100vh;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 0%,rgba(74,111,165,.12) 0%,transparent 70%);pointer-events:none}
input,select,textarea{font-size:16px!important}
.wrap{position:relative;z-index:1;max-width:480px;margin:0 auto;padding:2rem 1rem}
.back{display:inline-flex;align-items:center;gap:.5rem;color:var(--muted);text-decoration:none;font-size:.82rem;margin-bottom:1.5rem;transition:color .2s}
.back:hover{color:var(--gold)}
.user-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.4rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem}
.avatar{width:48px;height:48px;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:1.1rem;font-weight:700;color:#060910;flex-shrink:0}
.user-name{font-size:.95rem;font-weight:500}
.user-role{font-size:.7rem;color:var(--gold);letter-spacing:.15em;text-transform:uppercase;margin-top:.2rem}
.user-email{font-size:.75rem;color:var(--muted);margin-top:.2rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:2px;margin-bottom:1.2rem}
.card-head{padding:1rem 1.3rem;border-bottom:1px solid var(--border)}
.card-title{font-family:'Cinzel',serif;font-size:.85rem;letter-spacing:.1em;color:var(--text)}
.card-body{padding:1.3rem}
.ff{margin-bottom:1rem}
.ff label{display:block;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.ff input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.72rem 1rem;border-radius:2px;outline:none;font-family:'DM Sans',sans-serif;transition:border-color .2s}
.ff input:focus{border-color:var(--steel)}
.btn{width:100%;padding:.78rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));color:#060910;font-family:'Cinzel',serif;font-size:.8rem;font-weight:700;letter-spacing:.12em;border:none;border-radius:2px;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.88}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--muted);font-family:'DM Sans',sans-serif}
.btn-ghost:hover{border-color:var(--steel);color:var(--text);opacity:1}
.alert{padding:.65rem .9rem;border-radius:2px;font-size:.82rem;margin-bottom:1rem}
.alert-success{background:rgba(76,175,130,.08);border:1px solid rgba(76,175,130,.3);color:var(--success)}
.alert-error{background:rgba(224,92,92,.08);border:1px solid rgba(224,92,92,.3);color:var(--danger)}
</style>
</head>
<body>
<div class="wrap">
  <a href="<?= $dashUrl ?>" class="back">← Back to Dashboard</a>
  
  <div class="user-card">
    <div class="avatar"><?= strtoupper(substr($userData['full_name'],0,2)) ?></div>
    <div>
      <div class="user-name"><?= htmlspecialchars($userData['full_name']) ?></div>
      <div class="user-role"><?= htmlspecialchars($userData['role']) ?></div>
      <div class="user-email"><?= htmlspecialchars($userData['email']) ?></div>
    </div>
  </div>

  <?php if($msg): ?>
  <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- Change Password -->
  <div class="card">
    <div class="card-head"><div class="card-title">Change Password</div></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="ff"><label>Current Password</label><input type="password" name="current_password" placeholder="••••••••" required></div>
        <div class="ff"><label>New Password</label><input type="password" name="new_password" placeholder="Min. 8 characters" required></div>
        <div class="ff"><label>Confirm New Password</label><input type="password" name="confirm_password" placeholder="Repeat new password" required></div>
        <button type="submit" class="btn">Update Password</button>
      </form>
    </div>
  </div>

  <!-- Update Email -->
  <div class="card">
    <div class="card-head"><div class="card-title">Change Email</div></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_email">
        <div class="ff"><label>Current Email</label><input type="email" value="<?= htmlspecialchars($userData['email']) ?>" disabled style="opacity:.5"></div>
        <div class="ff"><label>New Email Address</label><input type="email" name="new_email" placeholder="your@newemail.com" required></div>
        <button type="submit" class="btn btn-ghost">Update Email</button>
      </form>
    </div>
  </div>

  <!-- Update Phone -->
  <div class="card">
    <div class="card-head"><div class="card-title">Contact Info</div></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_phone">
        <div class="ff"><label>Phone Number</label><input type="tel" name="phone" placeholder="+233 24 000 0000" value="<?= htmlspecialchars($userData['phone'] ?? '') ?>"></div>
        <button type="submit" class="btn btn-ghost">Save Phone</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
