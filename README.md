# Campus Lost & Found

A PHP and MySQL app for submitting lost-and-found reports, browsing published reports, and letting Campus Security staff review them behind a login.

## Run with XAMPP

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\campus-lost-found`.
3. Open `http://localhost/phpmyadmin`, choose **Import**, select `database.sql`, and import it. It is safe to re-run, and existing reports are kept.
4. The defaults in `api/config.php` match XAMPP (`127.0.0.1:3306`, `root`, blank password). If your MySQL settings differ, set `LF_DB_HOST`, `LF_DB_PORT`, `LF_DB_NAME`, `LF_DB_USER` and `LF_DB_PASSWORD` in the environment Apache runs with, then restart Apache.
5. Create accounts. In a Command Prompt, from the project folder:

   ```
   C:\xampp\php\php.exe tools\create-admin.php security --role=admin --name="Security Office" --email=security@yourcampus.edu
   C:\xampp\php\php.exe tools\create-admin.php jdelacruz --role=staff --name="Juan Dela Cruz" --email=juan@yourcampus.edu
   ```

   You will be asked for a password (12+ characters). Run the same command again to reset a password or change the role, name or email. `--disable` / `--enable` turn an account off or on.
6. Optionally configure email with the environment variables in **Email** below, and set `app_url` in `api/config.php` to the site's address.
7. Visit `http://localhost/campus-lost-found/`. Do not open `index.html` directly; PHP has to run through Apache.
8. Staff sign in at `http://localhost/campus-lost-found/#/staff`. This page is deliberately not linked from the public site; bookmark it. Once signed in, a **Dashboard** link appears in the menu.

### Upgrading from the previous version

Re-import `database.sql` in phpMyAdmin. It only adds what is missing: existing reports keep their data and get a reference code, and existing staff accounts become **administrators** so nobody loses access. Also copy the new `storage/` folder (photos are saved there; Apache must be able to write to it).

## Roles

| | Staff | Administrator |
| --- | --- | --- |
| Review reports, change status, view details | ✓ | ✓ |
| Send email to a lost item's owner, view email history, resend failed emails | ✓ | ✓ |
| Review claims (approve / reject) | ✓ | ✓ |
| Create matches; update matches assigned to them or unassigned | ✓ | ✓ |
| Assign staff to matches, update any match, delete matches and reports, send test emails | | ✓ |
| View priority analytics, recovery rates, category and location trends | | ✓ |

Administrator analytics support daily, weekly, monthly, semester (last six months), and yearly filters. A lost report counts as recovered only when it is linked to a non-dismissed match marked **Completed**; an approved claim alone does not count. Recovery trends use report-month cohorts, and category/location rankings use report creation dates.

## Email

Email is disabled by default. To enable it, set `LF_MAIL_ENABLED=true` and configure `LF_MAIL_HOST`, `LF_MAIL_PORT`, `LF_MAIL_ENCRYPTION`, `LF_MAIL_USERNAME`, `LF_MAIL_PASSWORD` and `LF_MAIL_FROM_EMAIL` in the environment Apache runs with, then restart Apache. Do not put an email password in source control. The settings are only read on the server; staff see the host, port and sender address but never the username or password.

- **Gmail:** host `smtp.gmail.com`, port `587`, encryption `tls`, username = the Gmail address, password = an **App Password** (Google Account → Security → 2-Step Verification → App passwords), `from_email` = the same Gmail address.
- **Outlook / Office 365:** host `smtp.office365.com`, port `587`, encryption `tls`.

Then open **Dashboard → Email notifications**. The box at the top says whether email is ready; an administrator can **Send test** to confirm. If anything is wrong, the error shown (and saved in the history) says what to fix, e.g. wrong password, wrong port, or server unreachable.

Emails sent automatically: report received (with the reference code), every status change (only when the status actually changes), matching item found (the **Send email** button, with the found item's photo), claim received / approved / rejected, and match assignment (to the assigned staff member, if they have an email). Every attempt is recorded as **Sent** or **Failed**.

## Features at a glance

- **Photos:** reporters can attach a photo (resized in the browser, re-encoded on the server). Click any photo to open the viewer with zoom (buttons, mouse wheel, pinch, double-tap) and drag. Photos of unpublished reports and claim proof photos are only shown to staff.
- **Reference image:** after submitting, people get a code like `LF-7KQ2M9XA` and a **Save reference image** button (PNG download; **Share / Save to Photos** on phones that support it). **Track my report** (`#/track`) shows the current status for a code and lists codes saved on that device.
- **Status label:** the final status is now shown as **Found** everywhere (the stored value is still `resolved`, so old records need no changes). To change wording, edit `STATUS_LABELS` in both `api/bootstrap.php` and `app.js`.
- **Matches:** suggested pairs (same category, shared words, similar place and date), manual matches, staff assignment, progress (Assigned → In progress → Completed), notes, confirm/dismiss.
- **Claims:** **Claim this item** on found items. Claims start as **Pending**; staff approve or reject with an optional note, which emails the claimant. Approving can also mark the item as Found. Every change is kept in the claim's history.
- **Analytics:** administrator-only, live report counts for verified recovery, lost/found category comparisons, campus location activity and unclaimed found items, with data-based insights.

## How it works

| File | Purpose |
| --- | --- |
| `api/reports.php` | **Public.** Published reports (no emails or reference codes), report look-up by reference code, and submitting a report with an optional photo. |
| `api/claims.php` | **Public** claim submission; **staff** list and review of claims. |
| `api/image.php` | Serves photos after checking who may see them. |
| `api/auth.php` | Staff sign-in, session check and sign-out. |
| `api/admin.php` | **Staff only.** All reports, details and history, status changes (with email). Delete is admin-only. |
| `api/analytics.php` | **Administrators only.** Date-filtered recovery, category, location and insight data. |
| `api/matches.php` | **Staff only.** Matches, suggestions and staff assignment. |
| `api/notifications.php` | **Staff only.** Email history, Send email for matches, resend, test email. |
| `api/mailer.php` | Email templates, SMTP client and delivery log. |
| `api/bootstrap.php` | Shared database, validation, roles, session, upload and rate-limit code. |
| `api/config.php` | Database, email and site settings. |
| `storage/` | Uploaded photos (not directly reachable from the web). |
| `tools/create-admin.php` | Command-line tool to create or update staff and admin accounts. |

## What changed in version 2

**Bugs fixed**
- Length checks counted bytes against limits that did not match the database columns, so valid-looking requests could fail with a generic 500. Validation now counts characters using the real limits (70 / 90 / 500 / 120) and returns field-level messages.
- Report IDs were generated in the browser with `crypto.randomUUID()`, which fails on non-HTTPS addresses other than `localhost`. The server now generates IDs.
- Timestamps were labelled UTC (`Z`) but MySQL returned server-local time. The connection now uses UTC.
- A non-JSON response (PHP error page, Apache down) caused a confusing parse error. The client now reports what actually went wrong.
- The "could not connect" message was written into the report form, which was immediately hidden. It now appears on the board with a **Try again** button.
- Submitting a report used to jump a public visitor into the admin dashboard. They now see a confirmation.
- Dates in the future are rejected.
- Clicking the padding inside the report dialog, or finishing a text selection outside it, could close the form. Fixed.

**Security**
- Contact emails and unpublished reports are no longer returned by the public API.
- Staff area requires a login: bcrypt/`password_hash` passwords, `HttpOnly` + `SameSite=Strict` session cookie (`Secure` over HTTPS), session ID regenerated on login, 30-minute idle and 8-hour absolute timeouts.
- Changes (publish, delete, sign-out) require a CSRF token; JSON content type is required on all writes.
- Sign-in is rate limited (5 failures per username+IP per 15 minutes, plus a per-IP cap). Public submissions are limited to 5 per IP per hour and include a honeypot field.
- Request body size cap, strict ID format checks, prepared statements throughout, and no database error details sent to the browser.
- `.htaccess` files block direct access to `config.php`, `bootstrap.php`, `tools/`, `*.sql` and `*.md`, and add a Content-Security-Policy and other security headers.

**UI**
- Larger, higher-contrast text (the old version used 8–11px type), visible keyboard focus, skip link, proper labels and `aria` states.
- Inline field errors, character counter, loading skeletons, retry on error, "Show more" paging, clear-filters button, relative "reported 2 days ago" times.
- Staff dashboard: sign-in screen, search, refresh, unpublish, and a proper delete confirmation dialog.

## Before putting this on a real server

- Serve it over **HTTPS**.
- Replace the MySQL `root` account with a limited user (instructions are in `api/config.php`) and set a random `rate_limit_salt`.
- Set `display_errors = Off` in `php.ini` (the API already turns it off for its own responses).
- If the app sits behind a reverse proxy, rate limiting will see the proxy's IP. Adjust `clientIp()` in `api/bootstrap.php` to read the proxy's forwarded header, but only if you trust that proxy.
- Decide how long to keep reports containing contact emails and delete old ones on a schedule.
- If staff see "The photo is too large", raise `upload_max_filesize` and `post_max_size` in `php.ini` (the browser already shrinks photos, so 8M is plenty).
"# Campus-Lost-and-Found-System" 
"# Campus-Lost-and-Found-System" 
"# Campus-Lost-and-Found-System" 
