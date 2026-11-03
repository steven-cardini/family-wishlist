# Family Wishlist

A lightweight, privacy-conscious, self-hosted web application for managing and sharing family wishlists with relatives and friends. Designed for easy deployment, zero build steps, and straightforward reusability.

## Key Features

- **Public Wishlist:** Clean view of gifts requested by family members (`recipients`).
- **Filtering:** Filter wishes by recipient, category, and reservation status (available vs. reserved).
- **Frictionless Reservations:** Relatives (givers) can reserve gifts without registering or creating an account.
- **Easy Cancellation:** Confirmation emails include a unique cancellation link to undo a reservation.
- **Surprise Protection:** Parents are emailed about reservations and cancellations, except for wishes that belong to them. In the admin dashboard, givers of parents' wishes are hidden until clicked.
- **Admin Dashboard:** Password-protected panel for parents to add, edit, delete, or manually mark wishes as reserved (e.g., for offline reservations).
- **No Passwords for Relatives:** Frictionless access control using a secret URL key (`?key=...`) and session cookies.
- **Mobile-First:** Works on phones and larger screens.

## Tech Stack

- **Backend:** Native PHP 8.5 (no frameworks or compilation required)
- **Database:** SQLite 3 (zero configuration, single file `data/db.sqlite`)
- **Frontend:** HTML5, Pico.css v2 (CDN), Alpine.js (CDN)
- **Email Delivery:** PHPMailer (committed, no Composer) via SMTP
- **Hosting Requirements:** PHP 8.5 with PDO SQLite, Apache with `mod_rewrite`, HTTPS, app at the domain root.
- **Language:** German UI and emails.

## Project Structure

```text
.
├── config.example.php   # Configuration template (committed to Git)
├── config.php           # Local environment settings (ignored by Git)
├── bootstrap.php        # Shared setup: config, session, security headers, helpers
├── db.php               # PDO database connection & one-time initialization
├── index.php            # /        Wishlist page for relatives (filters & modal)
├── admin.php            # /admin   Password-protected management dashboard
├── reserve.php          # /reserve Handles reservations & emails
├── cancel.php           # /cancel  Handles token-based cancellations
├── router.php           # Local dev only: URL rewrites for `php -S`
├── robots.txt           # Disallows search engine indexing
├── .htaccess            # URL rewrites & file access protection
├── data/                # SQLite database & sessions (ignored by Git, auto-created)
├── db/schema.sql        # Database schema & seed data
├── lib/PHPMailer/       # PHPMailer source files (third-party, LGPL)
├── tests/smoke.php      # Automated end-to-end tests
└── docs/                # Documentation (private_deployment.md: own deployment notes, ignored by Git)
```

## Security & Reusability Principles

1. **No Sensitive Data Committed:** Secrets, database files, and personal or deployment details (domain, hosting provider) live only in `config.php` and `docs/private_deployment.md`, both excluded via `.gitignore`.
2. **Easy Self-Hosting & Reusability:** Anyone can fork this repository, copy `config.example.php` to `config.php`, configure their SMTP and secret keys, and run their own family wishlist in minutes.

## Quick Start (Setup & Deployment)

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/your-username/family-wishlist.git
   cd family-wishlist
   ```

2. **Create Local Configuration:**
   Copy the example configuration file:
   ```bash
   cp config.example.php config.php
   ```

3. **Configure Settings:**
   Open `config.php` and set:
   - `base_url`: Public URL of the app (used in email links).
   - `access_key`: Long random secret for the relatives' link (`/?key=...`).
   - `admin_password_hash`: Hash of the admin password, generated with
     `php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'`
   - `smtp`: Your web host's SMTP server settings for outgoing emails.

4. **Run Locally (optional):**
   ```bash
   php -S localhost:8000 router.php
   php tests/smoke.php   # run the automated tests
   ```

5. **Deploy:**
   - Upload all files to your PHP web host directory (e.g., `/html` or `/httpdocs`).
   - Ensure `config.php` exists on the server with your production credentials.
   - The database (`data/db.sqlite`) is created automatically on the first visit. To reset it, delete that file.
   - Optional: keep your own deployment notes in `docs/private_deployment.md` (ignored by Git).

---
*For in-depth architectural details, data schema definitions, and logic flows, see [technical_specification.md](technical_specification.md).*