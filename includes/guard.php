<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ' . _rootPath() . 'index.php');
    exit;
}

require_once __DIR__ . '/db.php';

$_u = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$_u->execute([$_SESSION['user_id']]);
$me = $_u->fetch();

if (!$me || !$me['is_active'] || !empty($me['is_locked'])) {
    session_destroy();
    header('Location: ' . _rootPath() . 'index.php');
    exit;
}

$_SESSION['role']           = $me['role'];
$_SESSION['institution_id'] = $me['institution_id'] ?? 1;
$_SESSION['user'] = [
    'id'=>$me['id'],'full_name'=>$me['full_name'],
    'index_no'=>$me['index_no'] ?? '','email'=>$me['email'],
    'role'=>$me['role'],'institution_id'=>$me['institution_id'] ?? 1,
    'program_id'=>$me['program_id'] ?? null,'level'=>$me['level'] ?? null,
    'profile_photo'=>$me['profile_photo'] ?? null,
];

$inst_id = (int)($me['institution_id'] ?? 1);
$_i = $pdo->prepare("SELECT * FROM institutions WHERE id=? LIMIT 1");
$_i->execute([$inst_id]);
$institution = $_i->fetch();

function guardRole(array $allowed): void {
    global $me, $institution;
    if ($me['role'] === 'super_admin') return;
    if (!in_array($me['role'], $allowed, true)) {
        $map = ['admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
        $instType4 = $institution['inst_type'] ?? 'university';
        $adminDash4 = ($me['role'] === 'admin' && $instType4 !== 'university') ? 'school' : ($map[$me['role']] ?? 'student');
        header('Location: ' . _rootPath() . 'pages/' . $adminDash4 . '/dashboard.php');
        exit;
    }
}

function guardSuperAdmin(): void {
    global $me, $institution;
    if ($me['role'] !== 'super_admin') {
        header('Location: ' . _rootPath() . 'index.php');
        exit;
    }
}

function assertInstitution(int $target): void {
    global $me, $inst_id;
    if ($me['role'] === 'super_admin') return;
    if ($target !== $inst_id) { http_response_code(403); die('403 Access Denied'); }
}

function _rootPath(): string {
    $script = realpath($_SERVER['SCRIPT_FILENAME']);
    $root   = realpath(__DIR__ . '/..');
    $rel    = substr(dirname($script), strlen($root));
    $depth  = $rel ? substr_count(ltrim($rel,'/'), '/') + 1 : 0;
    return str_repeat('../', $depth);
}

function audit(string $action, string $type='', int $id=0, string $detail=''): void {
    global $pdo, $me;
    try {
        $pdo->prepare("INSERT INTO audit_log (actor_id,action,target_type,target_id,detail,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$me['id'],$action,$type,$id?:null,$detail?:null,$_SERVER['REMOTE_ADDR']??null]);
    } catch(Exception $e){}
}
