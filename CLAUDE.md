# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the Application

```bash
# Development server (defaults to port 80)
./start.sh
# or directly:
php -S 0.0.0.0:${PORT:-80} -t .

# Docker
docker build -t citadel .
docker run -p 80:80 citadel

# Database setup (run once)
mysql -h localhost -u root citadel < citadel_v3_migration.sql

# Install PHP dependencies
composer install
```

**Required PHP extensions:** pdo, pdo_mysql, mysqli, curl, mbstring

There is no test suite. Verification is manual via the browser.

## Architecture Overview

Citadel is a **multi-tenant attendance management system** built in procedural PHP 8.2 with no framework. It supports universities, secondary schools, junior high, and primary schools from a single codebase.

### Request Flow

All authenticated routes start at `index.php`, which reads the session role and renders the appropriate page from `pages/{role}/dashboard.php`. API calls go directly to `api/*.php` endpoints, which return JSON. There is no routing library — files map 1:1 to URLs.

### Multi-Tenancy

Every user belongs to an `institution_id`. All database queries **must** include an `institution_id` filter to prevent cross-tenant data leaks. Super admins (institution_id=1) can access all institutions. The `includes/terminology.php` maps role/course names to institution-appropriate terms (e.g., "Lecturer" → "Teacher" for schools).

### Role-Based Access Control

Five roles: `super_admin`, `admin`, `lecturer`, `rep`, `student`. Access is enforced via `includes/guard.php`:

```php
guardRole(['admin', 'super_admin']); // Redirects unauthorized users
audit($action, $targetType, $targetId, $detail); // Write to audit_log table
```

### Core Includes

| File | Purpose |
|---|---|
| `includes/db.php` | PDO MySQL connection |
| `includes/auth.php` | `requireLogin()`, `requireRole()`, `currentUser()`, `institutionId()` |
| `includes/guard.php` | `guardRole()`, `assertInstitution()`, `audit()` |
| `includes/security.php` | CSRF tokens, rate limiting, input sanitization, session timeout (30 min) |
| `includes/brevo_mail.php` | Email via Brevo API with fallback to `mail()` |
| `includes/logger.php` | `logError()` writes to monthly log files in `logs/` |

### Attendance Verification Pipeline

When a student marks attendance (`api/mark_attendance.php`):
1. **Client-side face recognition** — `assets/js/face-verify.js` uses `face-api.js` (TensorFlow.js) to compute a 128-point face descriptor and compare it against the stored `users.face_profile`; liveness is confirmed via eye-blink detection (EAR algorithm).
2. **Server-side AI verification** — `api/ai_verify.php` sends the classroom photo to the Anthropic Claude vision API to confirm a real classroom environment (whiteboard/projector + ≥2 other people visible).
3. **Manual approval** — If not auto-approved, admin/lecturer reviews via `api/approve_attendance.php`.

Key attendance columns: `face_match_score`, `ai_confidence`, `ai_auto_approved`, `liveness_pass`, `status` (present/late/absent/pending).

### Session Lifecycle

Lecturer opens session → students submit selfie + optional classroom photo → AI verification → optional admin approval → lecturer closes session.

### Notification Channels

- **Email:** Brevo API (`includes/brevo_mail.php`)
- **WhatsApp/SMS:** Twilio SDK (`api/notify_whatsapp.php`)

### Frontend

Vanilla JS and CSS — no build step. Key CSS variables in `assets/css/theme.css` (e.g., `--gold`, `--steel`). Dark mode toggled via `.light` class. Face detection models are in `assets/models/` (TensorFlow.js model files, not edited manually).

## Environment Variables

Defined in `.env` (excluded from git):

```
ANTHROPIC_API_KEY     # Claude vision API for classroom verification
BREVO_API_KEY         # Transactional email
MAIL_FROM / MAIL_FROM_NAME
MYSQLHOST / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD / MYSQLPORT
```

## Security Conventions

- All DB queries use PDO prepared statements — never concatenate user input into SQL.
- CSRF: Call `csrfToken()` to generate, `verifyCsrf()` to validate on POST.
- Input: use `clean()`, `validateLength()`, `sanitizeInt()`, `requireFields()` from `includes/security.php`.
- Rate limiting: `checkRateLimit($key, $max, $window)` backed by the `rate_limits` table.
- Every sensitive action should call `audit()` from `guard.php`.
- New API endpoints must enforce institution scoping — never return data from another institution.
