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
- **Frontend:** HTML5, Pico.css v2 (CDN) with own `style.css`, Alpine.js CSP build (CDN)
- **Email Delivery:** PHPMailer 7 (committed, no Composer) via SMTP
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
├── style.css            # Theme and layout on top of Pico.css
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

## Setup & Deployment

See [README.md](../README.md#setup--deployment).

---
*For in-depth architectural details, data schema definitions, and logic flows, see [technical_specification.md](technical_specification.md).*