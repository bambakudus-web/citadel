<?php
session_start();
// If already logged in, redirect to their dashboard
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $map  = ['admin' => 'admin', 'lecturer' => 'lecturer', 'rep' => 'rep', 'student' => 'student'];
    header('Location: pages/' . ($map[$role] ?? 'student') . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Citadel — Attendance Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060910;--surface:#0c1018;--surface2:#111722;--border:#1a2535;--gold:#c9a84c;--gold-dim:#7a5f28;--steel:#4a6fa5;--text:#e8eaf0;--muted:#6b7a8d;--success:#4caf82;--danger:#e05c5c}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;overflow-x:hidden}

/* Background */
body::before{content:'';position:fixed;inset:0;z-index:0;
  background:
    radial-gradient(ellipse 80% 60% at 50% -10%,rgba(74,111,165,.18) 0%,transparent 70%),
    radial-gradient(ellipse 40% 40% at 90% 80%,rgba(201,168,76,.08) 0%,transparent 60%),
    radial-gradient(ellipse 30% 30% at 10% 90%,rgba(74,111,165,.06) 0%,transparent 60%);
  pointer-events:none}

.grid{position:fixed;inset:0;z-index:0;
  background-image:linear-gradient(rgba(74,111,165,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(74,111,165,.04) 1px,transparent 1px);
  background-size:60px 60px;
  mask-image:radial-gradient(ellipse 100% 100% at 50% 0%,black 20%,transparent 100%)}

/* Layout */
.page{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column}

/* Nav */
nav{display:flex;align-items:center;justify-content:space-between;padding:1.4rem 4rem;border-bottom:1px solid rgba(26,37,53,.6)}
.nav-brand{display:flex;align-items:center;gap:.8rem}
.nav-brand svg{width:36px;height:36px}
.nav-brand-text .name{font-family:'Cinzel',serif;font-size:1.1rem;font-weight:700;color:var(--gold);letter-spacing:.15em}
.nav-brand-text .sub{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted)}
.nav-links{display:flex;align-items:center;gap:1rem}
.btn-outline{padding:.45rem 1.1rem;border:1px solid var(--border);border-radius:2px;color:var(--muted);font-family:'DM Sans',sans-serif;font-size:.82rem;text-decoration:none;transition:all .2s}
.btn-outline:hover{border-color:var(--gold);color:var(--gold)}
.btn-primary{padding:.45rem 1.2rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;color:#060910;font-family:'Cinzel',serif;font-size:.75rem;font-weight:700;letter-spacing:.12em;text-decoration:none;transition:opacity .2s}
.btn-primary:hover{opacity:.88}

/* Hero */
.hero{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:5rem 2rem 4rem}
.hero-badge{display:inline-flex;align-items:center;gap:.5rem;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.2);border-radius:2px;padding:.35rem 1rem;font-size:.68rem;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin-bottom:2rem}
.hero-badge-dot{width:6px;height:6px;border-radius:50%;background:var(--gold);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

.hero h1{font-family:'Cinzel',serif;font-size:clamp(2.5rem,6vw,4.5rem);font-weight:700;line-height:1.1;color:var(--text);letter-spacing:.05em;margin-bottom:1.5rem}
.hero h1 span{color:var(--gold)}
.hero p{font-size:clamp(.9rem,2vw,1.1rem);color:var(--muted);max-width:560px;line-height:1.7;margin-bottom:2.5rem}

.hero-cta{display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;margin-bottom:4rem}
.cta-primary{padding:.85rem 2.2rem;background:linear-gradient(135deg,var(--gold-dim),var(--gold));border-radius:2px;color:#060910;font-family:'Cinzel',serif;font-size:.88rem;font-weight:700;letter-spacing:.15em;text-decoration:none;transition:opacity .2s,transform .15s}
.cta-primary:hover{opacity:.88;transform:translateY(-2px)}
.cta-secondary{padding:.85rem 2.2rem;border:1px solid var(--border);border-radius:2px;color:var(--muted);font-family:'DM Sans',sans-serif;font-size:.88rem;text-decoration:none;transition:all .2s}
.cta-secondary:hover{border-color:var(--steel);color:var(--text)}

/* Stats */
.hero-stats{display:flex;gap:3rem;flex-wrap:wrap;justify-content:center}
.stat{text-align:center}
.stat-num{font-family:'Cinzel',serif;font-size:2rem;font-weight:700;color:var(--gold)}
.stat-label{font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}

/* Features */
.features{padding:5rem 4rem;border-top:1px solid var(--border)}
.features-title{text-align:center;font-family:'Cinzel',serif;font-size:1.8rem;color:var(--text);margin-bottom:.8rem}
.features-title span{color:var(--gold)}
.features-sub{text-align:center;color:var(--muted);font-size:.9rem;margin-bottom:3rem}
.features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem;max-width:1100px;margin:0 auto}
.feature-card{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:1.8rem;position:relative;overflow:hidden;transition:border-color .2s}
.feature-card:hover{border-color:var(--gold-dim)}
.feature-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent);opacity:0;transition:opacity .2s}
.feature-card:hover::before{opacity:1}
.feature-icon{width:40px;height:40px;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.15);border-radius:2px;display:flex;align-items:center;justify-content:center;margin-bottom:1.2rem}
.feature-icon svg{width:20px;height:20px;stroke:var(--gold)}
.feature-title{font-family:'Cinzel',serif;font-size:.88rem;color:var(--text);letter-spacing:.08em;margin-bottom:.6rem}
.feature-desc{font-size:.82rem;color:var(--muted);line-height:1.6}

/* Roles */
.roles{padding:4rem;border-top:1px solid var(--border);background:var(--surface)}
.roles-title{text-align:center;font-family:'Cinzel',serif;font-size:1.5rem;color:var(--text);margin-bottom:2.5rem}
.roles-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;max-width:900px;margin:0 auto}
.role-card{border:1px solid var(--border);border-radius:2px;padding:1.4rem;text-align:center;text-decoration:none;transition:all .2s;display:block}
.role-card:hover{transform:translateY(-3px)}
.role-card.admin{border-color:rgba(201,168,76,.3);background:rgba(201,168,76,.04)}
.role-card.lecturer{border-color:rgba(138,111,212,.3);background:rgba(138,111,212,.04)}
.role-card.rep{border-color:rgba(90,159,122,.3);background:rgba(90,159,122,.04)}
.role-card.student{border-color:rgba(74,111,165,.3);background:rgba(74,111,165,.04)}
.role-icon{font-size:2rem;margin-bottom:.8rem}
.role-name{font-family:'Cinzel',serif;font-size:.88rem;letter-spacing:.1em;margin-bottom:.4rem}
.role-card.admin .role-name{color:var(--gold)}
.role-card.lecturer .role-name{color:#8a6fd4}
.role-card.rep .role-name{color:#5a9f7a}
.role-card.student .role-name{color:var(--steel)}
.role-desc{font-size:.75rem;color:var(--muted)}

/* Footer */
footer{padding:2rem 4rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.footer-brand{font-family:'Cinzel',serif;font-size:.85rem;color:var(--gold);letter-spacing:.15em}
.footer-links{display:flex;gap:1.5rem}
.footer-links a{font-size:.78rem;color:var(--muted);text-decoration:none;transition:color .2s}
.footer-links a:hover{color:var(--text)}
.footer-copy{font-size:.72rem;color:var(--muted)}

@media(max-width:768px){
  nav{padding:1rem 1.5rem}
  .features,.roles{padding:3rem 1.5rem}
  footer{padding:1.5rem;flex-direction:column;text-align:center}
  .hero-stats{gap:2rem}
  .stat-num{font-size:1.5rem}
}
</style>
<link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#c9a84c"><script>if("serviceWorker"in navigator)navigator.serviceWorker.register("/sw.js");</script>
</head>
<body>
<div class="grid"></div>
<div class="page">

  <!-- Nav -->
  <nav>
    <div class="nav-brand">
      <svg viewBox="0 0 52 52" fill="none">
        <polygon points="26,2 50,14 50,38 26,50 2,38 2,14" fill="none" stroke="#c9a84c" stroke-width="1.5"/>
        <polygon points="26,9 43,18 43,34 26,43 9,34 9,18" fill="none" stroke="#c9a84c" stroke-width="0.8" opacity="0.5"/>
        <rect x="20" y="20" width="12" height="14" rx="1" fill="none" stroke="#4a6fa5" stroke-width="1.5"/>
        <circle cx="26" cy="25" r="2" fill="#c9a84c"/>
        <line x1="26" y1="27" x2="26" y2="31" stroke="#c9a84c" stroke-width="1.5"/>
      </svg>
      <div class="nav-brand-text">
        <div class="name">CITADEL</div>
        <div class="sub">Attendance System</div>
      </div>
    </div>
    <div class="nav-links">
      <a href="register.php" class="btn-outline">Register</a>
      <a href="login.php" class="btn-primary">Sign In</a>
    </div>
  </nav>

  <!-- Hero -->
  <section class="hero">
    <div class="hero-badge"><div class="hero-badge-dot"></div>Smart Attendance Management</div>
    <h1>Attendance Tracking<br>for <span>Modern Institutions</span></h1>
    <p>Citadel makes attendance effortless — real-time sessions, selfie verification, instant reports, and full semester management for students, lecturers, and administrators.</p>
    <div class="hero-cta">
      <a href="login.php" class="cta-primary">Get Started</a>
      <a href="register.php" class="cta-secondary">Create Account</a>
    </div>
    <div class="hero-stats">
      <div class="stat"><div class="stat-num">4</div><div class="stat-label">Role Types</div></div>
      <div class="stat"><div class="stat-num">TOTP</div><div class="stat-label">Secure Codes</div></div>
      <div class="stat"><div class="stat-num">Live</div><div class="stat-label">Real-Time</div></div>
      <div class="stat"><div class="stat-num">PDF</div><div class="stat-label">Certificates</div></div>
    </div>
  </section>

  <!-- Features -->
  <section class="features">
    <div class="features-title">Everything You <span>Need</span></div>
    <div class="features-sub">Built for institutions that take attendance seriously</div>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="feature-title">Real-Time Sessions</div>
        <div class="feature-desc">Lecturers start sessions with rotating 6-digit codes. Students mark attendance live with selfie verification.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div class="feature-title">Multi-Role Access</div>
        <div class="feature-desc">Separate dashboards for Admin, Lecturers, Course Reps and Students — each with the right level of control.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <div class="feature-title">Semester Management</div>
        <div class="feature-desc">Create semesters, assign courses and lecturers. Switch semesters without losing historical data.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="feature-title">PDF Certificates</div>
        <div class="feature-desc">Students download attendance certificates per semester. Admins export full records as CSV.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div class="feature-title">Device Security</div>
        <div class="feature-desc">Device fingerprinting prevents proxy attendance. Admins can ban, reset or unlock devices from the dashboard.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <div class="feature-title">Live Analytics</div>
        <div class="feature-desc">Track attendance rates per course, per student, per session. Instant warnings for students below 75%.</div>
      </div>
    </div>
  </section>

  <!-- Roles -->
  <section class="roles">
    <div class="roles-title">Sign In As</div>
    <div class="roles-grid">
      <a href="login.php" class="role-card admin">
        <div class="role-icon">👑</div>
        <div class="role-name">ADMIN</div>
        <div class="role-desc">Full control — manage semesters, courses, users and system settings</div>
      </a>
      <a href="login.php" class="role-card lecturer">
        <div class="role-icon">🎓</div>
        <div class="role-name">LECTURER</div>
        <div class="role-desc">Start sessions, view attendance and track course performance</div>
      </a>
      <a href="login.php" class="role-card rep">
        <div class="role-icon">📋</div>
        <div class="role-name">COURSE REP</div>
        <div class="role-desc">Approve selfies, manage class records and post announcements</div>
      </a>
      <a href="login.php" class="role-card student">
        <div class="role-icon">📚</div>
        <div class="role-name">STUDENT</div>
        <div class="role-desc">Mark attendance, track your rate and download certificates</div>
      </a>
    </div>
  </section>

  <!-- Footer -->
  <footer>
    <div class="footer-brand">CITADEL</div>
    <div class="footer-links">
      <a href="login.php">Sign In</a>
      <a href="register.php">Register</a>
    </div>
    <div class="footer-copy">© <?= date('Y') ?> Citadel Attendance System</div>
  </footer>

</div>
</body>
</html>
