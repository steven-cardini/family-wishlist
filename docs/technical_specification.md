# Technical Specification & Architecture

This document details the functional requirements, data model relationships, security architecture, and business logic for the Family Wishlist application.

UI and email language: **German** (Swiss spelling without "ß", informal "du").

---

## 1. Roles & Permissions

### 1.1 Recipients (`recipients`)
- Family members (e.g., children, parents) for whom wishes are created.
- Stored with a name, an optional email address, an `is_parent` flag and a color (fixed palette `RECIPIENT_COLORS` in `bootstrap.php`; the least used color is preselected for new recipients).
- Parents (`is_parent = 1`) with an email address receive notifications (see Section 5).

### 1.2 Givers
- Relatives or friends viewing the wishlist and reserving items.
- Require **no user account or registration**.
- Provide their name, optionally their email address and a note to the parents at the moment of reservation (stored in the reservation, no giver table).

### 1.3 Administrators (Parents)
- Access `/admin`, authenticated via a central admin password (stored as hash in `config.php`).
- Full CRUD permissions for recipients and wishes.
- Ability to manually mark wishes as reserved on behalf of others (e.g., phone calls from grandparents).

---

## 2. Detailed Requirements

### 2.1 Wish Attributes
Each wish entry contains:
- **Title:** Name of the item (required).
- **Description (optional):** Additional details like size, color, condition, or specifics (e.g., "Grösse 134", "Second-hand OK").
- **Price (optional):** Freeform string (e.g., "30 CHF", "20–30 CHF"), always displayed with a "ca." prefix. A leading "ca." entered by the admin is removed on save.
- **Webshop URL (optional):** Direct link to the online store item (`http(s)://` only).
- **Image URL (optional):** Direct link to an image hosted on the webshop (`http(s)://` only). Without an image, or if it fails to load, a gift icon is shown.
- **Priority Flag (`is_priority`):** Shown as "Grosser Wunsch" (badge and highlighted card).
- **Recipients:** At least one family member, via junction table (`wish_recipient`), shown as name tags in the recipient's color.

### 2.2 Frontend & Filtering (`/`)
- Filtering happens client-side (Alpine.js).
- **Filter Controls:**
  - **Recipient(s):** Checkbox filter, listing only recipients linked to at least one wish; a wish matches if it belongs to any selected recipient (none selected: all).
  - **Status:** "Available only" (default), "Reserved only", "Show all".
- A summary above the wishes shows the number of shown wishes (screen-reader live region). If a recipient filter or "Reserved only" is active, it is highlighted, names the active filters ("Filter aktiv (für Anna, nur verfügbare): 1 von 5 Wünschen") and offers "Filter zurücksetzen" (back to defaults), so no wishes are hidden by mistake.
- Filters are kept in `sessionStorage` (survive the reload after a reservation, reset when the tab is closed).
- Sort order: available before reserved; within each, priority wishes first, then newest first.
- Reserved wishes are rendered at the bottom, grayed out, with their reservation date ("Reserviert am 24.12.2026"). Giver details are never shown.
- A "Link teilen" button at the bottom shares the access link (`base_url` + `/?key=...`) via the device's share dialog, or copies it to the clipboard if sharing is not supported. If both fail, the link is shown as text.

### 2.3 Reservation Workflow (`/reserve`)
1. Clicking "Reserve Gift" opens a modal.
2. The giver submits their **Name**, optionally their **Email Address** and optionally a **Note to the parents** (max. 500 characters, e.g. "Wir bringen es an die Weihnachtsfeier mit."). Name and email of the last successful reservation are stored in the session and prefilled.
3. Upon submission:
   - A row in `reservations` is inserted with `giver_name`, `giver_email`, `giver_note`, `reserved_at` and, with email only, a `cancel_token` (`bin2hex(random_bytes(16))`). Its primary key `wish_id` rejects a second reservation; then (or for an unknown wish) an error is shown.
   - With email: a confirmation email is sent to the giver, containing the cancellation link `/cancel?token=XYZ`. The success message asks to check the spam folder if it does not arrive.
   - Without email: no confirmation and no cancellation link; only admins can remove the reservation (the form says so).
   - Parents are notified according to Section 5.

### 2.4 Cancellation Workflow (`/cancel`)
- Requires no access key: the secret `cancel_token` is the authorization.
- `GET /cancel?token=XYZ` shows the wish and a "Confirm cancellation" button. It never changes data (email link scanners open links automatically).
- `POST /cancel` with a valid token deletes the reservation and shows a confirmation.
- Parents are notified according to Section 5.

### 2.5 Admin Manual Reservation
- Giver name is required, email and note are optional.
- No emails are sent and no `cancel_token` is created.
- Admins can also remove any reservation (no emails sent, confirmation required, see 2.8).

### 2.6 Admin Surprise Protection
- In `/admin`, giver details (name, email, reservation date, note) of a wish linked to any recipient with `is_parent = 1` are hidden by default and shown only after an explicit click (`<details>` element).

### 2.7 Layout
- Mobile-first: all pages, including `/admin`, work on phones (from 360 px width) and on larger screens, without horizontal scrolling and with touch-friendly controls.
- Theme: follows the device's light/dark setting. A toggle on every page overrides it; the choice is stored in the `theme` cookie (`light` or `dark`, 1 year) and rendered server-side as `<html data-theme>`, so there is no flash of the wrong theme. Choosing the device's theme deletes the cookie (follows the device again).
- Accessibility: text keeps WCAG AA contrast (4.5:1) in both themes, including reserved wishes. Decorative symbols (★, ✓) are hidden from screen readers; shop links announce that they open in a new tab.

### 2.8 General Behavior
- Form submissions redirect back with a success or warning message (Post/Redirect/Get).
- In `/admin`, existing recipients are shown read-only. Their edit form opens via "Bearbeiten"; new entries are added in a separate form below.
- In `/admin`, deleting (wishes, recipients) and "Reservation entfernen" need a second click ("Wirklich ...") to prevent accidental changes.
- Editing a reserved wish keeps the reservation. Deleting a reserved wish sends no emails.
- An invalid or already used cancel token shows "link no longer valid".
- Dates are stored in UTC and displayed in the `timezone` from `config.php`.

---

## 3. Security, Anti-Spam & Privacy Architecture

### 3.1 URL Key Protection (Access Key)
- Relatives access the site via a shared URL `https://example.com/?key=YOUR_SECRET_KEY`, or by entering the key (called "Code" in the UI) on the start page. It may be a memorable phrase so it can be passed on by phone.
- The key is trimmed (phone keyboards add spaces) and compared with `hash_equals()`. On success: `session_regenerate_id(true)`, `$_SESSION['access_key_hash'] = hash('sha256', access_key)`, redirect to `/` (removes the key from the URL).
- A session is authorized only while its `access_key_hash` matches the current `access_key`. Changing the key in `config.php` therefore revokes all existing access.
- Unauthenticated requests display a simple key entry form with the hint to ask the parents for the code or link.
- An admin session (`$_SESSION['is_admin']`) also counts as authorized.

### 3.2 Sessions
- Long-lived session: cookie and server-side lifetime of 1 year.
- Admin login expires after 4 hours (see 7.2).
- Session files are stored in `data/sessions/` (shared hosts may otherwise delete them early).
- A session is only started when needed (login, form, message) or when a session cookie exists, so anonymous requests create no session files.
- Cookie flags: `HttpOnly`, `SameSite=Lax`, `Secure` when served via HTTPS.

### 3.3 Search Engine Indexing Prevention
- **`robots.txt`:**
  ```text
  User-agent: *
  Disallow: /
  ```
- **HTML Header:** `<meta name="robots" content="noindex, nofollow">` across all views.

### 3.4 Bot, Spam & Brute-Force Protection
- **Honeypot:** Reservation forms include a hidden input field:
  ```html
  <input type="text" name="website_url" style="display:none;" tabindex="-1" autocomplete="off">
  ```
  Backend rule: If `!empty($_POST['website_url'])`, processing halts silently.
- **CSRF:** All POST forms (except `/cancel`, protected by its token) include a per-session CSRF token, checked with `hash_equals()`.
- **Login throttling:** Failed access key and admin password attempts are stored per IP in `login_failures`. After 10 failures within 15 minutes, further attempts from that IP are rejected.

### 3.5 HTTP Security Headers
Sent by `bootstrap.php` on every response:
- `Content-Security-Policy`: scripts and styles only with a per-request nonce (no `'unsafe-eval'`, hence Alpine's CSP build `@alpinejs/csp`), plus the hash of the honeypot's inline style; `https:` and `data:` images (Pico uses `data:` icons); forms to own origin only; `frame-ancestors 'none'`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer` (prevents leaking key or cancel token)
- `X-Robots-Tag: noindex, nofollow`
- `Strict-Transport-Security: max-age=31536000` (HTTPS only)

CDN assets are version-pinned and loaded with Subresource Integrity (`integrity` attribute).

### 3.6 File Access Protection (`.htaccess`)
- Redirects HTTP to HTTPS.
- Denies HTTP access to `data/` (database, sessions), `db/`, `docs/`, `lib/`, `tests/`, dotfiles (e.g., `.git`; except `.well-known/` for TLS certificate renewal), and `router.php`, `config.php`, `bootstrap.php`, `db.php`.
- Rewrites `/admin`, `/reserve` and `/cancel` to their PHP files. The app runs at the domain root.
- Requires Apache with `mod_rewrite`. Locally, `router.php` emulates the rewrites for `php -S`.

---

## 4. Data Model Schema (Single Source of Truth)

The SQLite database schema and foreign key relations are defined exclusively in:
👉 **`db/schema.sql`**

### Key Entities & Relations
- `recipients`: Family members who receive gifts. A recipient still linked to a wish cannot be deleted.
- `wishes`: Main wishlist items.
- `reservations`: At most one per wish (primary key `wish_id`); a wish is reserved if it has one. Deleted with its wish.
- `wish_recipient`: Junction table mapping multi-recipient relationships to `wishes`.
- `login_failures`: Failed login attempts for throttling.

---

## 5. Email Notification & Surprise Protection Matrix

On every reservation (2.3) and cancellation (2.4) by a giver:

1. **Giver Confirmation Email (reservation with email only):**
   - Sent to: `giver_email`
   - Content: Wish details, note, confirmation, and cancellation link (`/cancel?token=...`).

2. **Parent Notification:**
   - Candidates: all `recipients` with `is_parent = 1` and a non-empty `email`.
   - For each candidate: **Is this recipient linked to the wish in `wish_recipient`?**
     - **NO:** Notification sent (*"Giver X reserved / cancelled Wish Y for Z"*, Z = all recipients of the wish; on reservation including the note).
     - **YES:** **No notification sent!** (Preserves the surprise for parents' own wishes.)

Admin actions (2.5) send no emails. All emails are plain text. The sender (`smtp.from_email`) should belong to the SMTP account to avoid spam filtering.

---

## 6. Open Source & Reusability Best Practices

- **Configuration Decoupling:** Credentials, passwords, and tokens reside solely in `config.php` (copied from `config.example.php`).
- **No Personal or Deployment Data:** No secrets, personal names, domains, or hosting details exist in tracked files. They belong in `config.php` or `docs/private_deployment.md` (both gitignored).
- **Zero Build Dependencies:** CDN links for Pico.css and Alpine.js. PHPMailer source files are committed in `lib/PHPMailer/` (no Composer).

---

## 7. Implementation Details & Edge Cases

### 7.1 Configuration Loading
`config.php` returns a key-value PHP array. Refer to `config.example.php` for expected structure. `base_url` is used for links in emails (never derived from the request's `Host` header). `site_title` is the wishlist heading and browser title, and the sender name and signature of emails.

### 7.2 Admin Authentication Flow (`/admin`)
- Accessing `/admin` validates `!empty($_SESSION['is_admin'])`.
- Unauthenticated requests display a password input form.
- Upon POST submission where `password_verify()` matches `admin_password_hash`: `session_regenerate_id(true)`, set `$_SESSION['is_admin'] = true`, `$_SESSION['admin_since'] = time()` and redirect.
- `is_admin` is only valid while `time() - admin_since < 4 * 3600`.
- Logout removes `is_admin` and `admin_since` (access key authorization remains).

### 7.3 Database Initialization (`db.php`)
- `db.php` opens the SQLite PDO connection to `data/db.sqlite` (creating `data/` if missing) and sets `PRAGMA foreign_keys = ON` on every connection.
- If `PRAGMA user_version` is `0`, `db/schema.sql` is executed once inside a `BEGIN IMMEDIATE` transaction (version is re-checked inside the transaction). The schema sets `user_version = 1`.
- **Reset:** Delete `data/db.sqlite` (e.g., via FTP). It is recreated empty on the next request.
- Future schema changes: add migration steps keyed on `user_version`.

### 7.4 Email Error Handling
- Database reservation state updates take priority.
- If SMTP email dispatch fails due to connectivity or credentials, the reservation or cancellation MUST still be recorded in SQLite, and a graceful warning message is presented to the user.
- SMTP timeout is 15 seconds; failures are written to the PHP error log.

---

## 8. Testing (`tests/smoke.php`)
- Run: `php tests/smoke.php`. Exit code `0` means all checks passed.
- Copies the app to a temporary directory with a generated test `config.php`, starts `php -S` with `router.php`, and checks the workflows of Sections 2, 3 and 5 via HTTP.
- Needs no network: emails go to a local SMTP sink started by the test (`encryption => ''`).
- Client-side behavior (Alpine filters, modal) is not covered; verify it in a browser.
- Never touches the real `config.php` or `data/`.
