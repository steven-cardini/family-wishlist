# Agent Instructions: Family Wishlist

## 1. Documentation Index & SSOT Locations
Do not duplicate code or schemas. Read the dedicated single-source files in `docs/` and `db/` before implementing logic:

- **Database Schema & Constraints (SSOT):** Read `db/schema.sql`
- **Configuration Array Layout (SSOT):** Read `config.example.php`
- **Business Logic & Surprise Protection Matrix:** See `docs/technical_specification.md` (Section 5)
- **Workflows, Auth & Security (Admin, Key Auth, Sessions, CSRF, Honeypot):** See `docs/technical_specification.md` (Sections 2, 3 & 7)
- **Project Structure:** See `docs/project_documentation.md`
- **Setup & Deployment (config.php values, database, .htaccess):** See `README.md`
- **Private Deployment Details (not committed):** Read `docs/private_deployment.md` if present

## 2. Quick Start & Execution Commands
If `php` is not on the `PATH` (e.g., in agent shells), call it by full path: `/opt/homebrew/bin/php` (Homebrew on macOS).

- **Local Dev Server:** `php -S localhost:8000 router.php`
- **Initialize / Reset SQLite Database:** delete `data/db.sqlite`; it is recreated on the next request (see `db.php`)
- **Automated Tests:** `php tests/smoke.php`
- **PHP Syntax Check:** `find . -name "*.php" -not -path "./lib/*" -exec php -l {} \;`

## 3. Mandatory Policies for you as AI agent

- Whenever you present me a solution, prove that this solution actually works.
- Your priorities are correctness, security, best practices, runtime efficiency, code simplicity and "add only the bare minimum", in this order.
- General language rules:
  - Bare minimum: keep texts as short and concise as possible, while still adding all the information which is absolutely required.
  - Use meaningful, easy to understand names.
  - Be consistent throughout the project with naming
- When you implement something new, modify an existing implementation, or delete an existing modification, make sure the relevant parts of the documentation and specification files are up to date. Keep them as simple and short as possible, but such that they still contains the necessary information.
- Only add comments to implementation files (e.g. `.php` files) if it really helps understand something better. Default is to not add any comments.
  - If you add a comment to an implementation file, make it as short as possible. Add a reference to a documentation file (e.g. `.md` file) for more information, if this is helpful.
- If during code implementation or updating documentation you encounter something misleading or inconsistent, raise it in your answer to me.
- Once more: never be sure about your answers, implementations or updates, unless you have provided proof for its correctness

## 4. Mandatory Coding & Security Guardrails
When writing or editing code, strictly follow these non-negotiable rules:

1. **Strict Typing:** Every PHP file MUST start with `declare(strict_types=1);`.
2. **Database Queries:** NEVER concatenate variables into SQL strings. ALWAYS use PDO Prepared Statements (`$stmt->prepare()` / `$stmt->execute()`).
3. **XSS Prevention:** ALWAYS escape dynamic HTML outputs using `htmlspecialchars((string)$var, ENT_QUOTES, 'UTF-8')`.
4. **Honeypot Validation:** The reservation handler MUST check `empty($_POST['website_url'])`. If filled, reject processing silently.
5. **Zero Build Steps:** Use version-pinned CDN scripts (with SRI) for Pico.css v2 and Alpine.js (CSP build `@alpinejs/csp`, see CSP in `docs/technical_specification.md` 3.5). Do NOT add Node.js, npm, Composer, or bundlers. PHPMailer is committed in `lib/PHPMailer/`.
6. **No Hardcoded Secrets or Personal Data:** Load configuration dynamically from `config.php`. NEVER commit credentials, personal names, email addresses, domains, or hosting details. Put deployment details into `docs/private_deployment.md` (gitignored).
7. **CSRF:** Every POST form (except `/cancel`) MUST include and verify the session CSRF token.
8. **Language:** All UI texts and emails are German (Swiss spelling without "ß", informal "du").
9. **Mobile-First:** Every page MUST work on phones and larger screens.