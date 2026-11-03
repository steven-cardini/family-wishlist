# Family Wishlist

A small, self-hosted web app for sharing family wishlists. Relatives browse wishes and reserve a gift without an account, so nobody buys the same thing twice.

## How it works

- **Parents** maintain recipients, categories and wishes in a password-protected admin area.
- **Relatives** open a private link, filter wishes by person, category or availability, and reserve a gift with just their name and email.
- **Reservations** trigger a confirmation email with a cancellation link. Parents are notified of reservations and cancellations, except for wishes that are their own, so surprises stay intact.

## Built with

PHP, SQLite, Pico.css and Alpine.js. There is no build step. It runs on any basic PHP web hosting.

## Setup & deployment

**Requirements:** PHP 8.5 with PDO SQLite, Apache with `mod_rewrite` and `.htaccess` enabled (`AllowOverride` with at least `FileInfo` and `Options`), HTTPS. The app runs at the domain root: the document root must be the repository folder.

### 1. Create `config.php` (the only file not in Git)

Copy `config.example.php` to `config.php`, set the values below and put it next to `index.php` on the server (e.g. via SFTP). Never commit it.

| Key | Value |
| --- | --- |
| `base_url` | Public URL without trailing slash, e.g. `https://wishlist.example.com`. Used for the cancellation links in emails. |
| `timezone` | Timezone for displayed dates, e.g. `Europe/Zurich`. |
| `access_key` | Long random secret: `php -r 'echo bin2hex(random_bytes(24)), "\n";'`. Share `https://wishlist.example.com/?key=<access_key>` with relatives. Changing it revokes all existing access. |
| `admin_password_hash` | Run `php -r 'echo password_hash(rtrim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'`, type the admin password, press Enter. Keep the single quotes (the hash contains `$`). |
| `smtp` | Mailbox used for sending: `host`, `port` + `encryption` (`587` + `'tls'` or `465` + `'ssl'`), `username` (usually the full email address), `password`, `from_email` (the same mailbox, to avoid spam filters), `from_name`. |

### 2. Database

Nothing to do: `data/db.sqlite` and `data/sessions/` are created on the first request. PHP must be allowed to write to the app folder; if not, create `data/` yourself and make it writable for PHP. To back up, download `data/db.sqlite`; to reset, delete it.

If your host deploys by pulling from Git, make sure it keeps files not in Git (`config.php`, `data/`). Check once: add a wish, redeploy, confirm the wish is still there.

### 3. `.htaccess`

Included in the repository, nothing to add. It redirects HTTP to HTTPS (so a valid TLS certificate must exist before the first visit), maps `/admin`, `/reserve` and `/cancel` to their PHP files and blocks private files (`config.php`, `data/`, `.git/`, ...).

### 4. Check after deploying

- `https://<domain>/?key=<access_key>` shows the wishlist, `/admin` shows the login.
- `/config.php`, `/data/db.sqlite` and `/.git/config` return `403 Forbidden`.
- `http://<domain>/` redirects to `https://`. A redirect loop means your host terminates HTTPS before Apache: remove the HTTPS redirect from `.htaccess` and enable it in your hosting panel instead.
- Reserve a test wish: the confirmation email arrives (check spam) and its cancellation link works.

### Local development

```bash
cp config.example.php config.php   # set access_key and admin_password_hash
php -S localhost:8000 router.php   # open http://localhost:8000/?key=<access_key>
php tests/smoke.php                # automated tests
```

## Documentation

- [docs/project_documentation.md](docs/project_documentation.md): features, tech stack, project structure
- [docs/technical_specification.md](docs/technical_specification.md): requirements, security and business rules
- [AGENTS.md](AGENTS.md): setup commands and contribution rules

## License

See [LICENSE](LICENSE).
