<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('config.php fehlt (siehe config.example.php).');
}

require __DIR__ . '/db.php';

date_default_timezone_set('UTC');

const SESSION_LIFETIME = 365 * 24 * 3600;
const ADMIN_LIFETIME = 4 * 3600;
const PICO_CSS = 'https://cdn.jsdelivr.net/npm/@picocss/pico@2.1.1/css/pico.min.css';
const PICO_CSS_SRI = 'sha384-L1dWfspMTHU/ApYnFiMz2QID/PlP1xCW9visvBdbEkOLkSSWsP6ZJWhPw6apiXxU';
const ALPINE_JS = 'https://cdn.jsdelivr.net/npm/@alpinejs/csp@3.17.4/dist/cdn.min.js';
const ALPINE_JS_SRI = 'sha384-DQd2BgbtOQrdt/JbxcM+wSb8poOHwsG5W2jbbXskj85r7SWPwqpBFgGGR1devM/D';
// Recipient colors (key => label), styled in style.css
const RECIPIENT_COLORS = [
    'blue' => 'Blau',
    'green' => 'Grün',
    'orange' => 'Orange',
    'violet' => 'Violett',
    'teal' => 'Türkis',
    'red' => 'Rot',
    'pink' => 'Pink',
    'brown' => 'Braun',
];
// Hash of the honeypot's inline style "display:none;"
const HONEYPOT_STYLE_HASH = 'sha256-0EZqoz+oBhx7gF4nvY2bSqoGyy4zLjNF+SDQXGp/ZrY=';
const GIVER_NOTE_MAX_LENGTH = 500;
const LOGIN_RETENTION_DAYS = 90;
const LOGIN_INPUT_MAX_LENGTH = 100;
const LOGIN_MAX_FAILURES = 10;
const FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%23e0608c'/%3E%3Cstop offset='1' stop-color='%23a1214f'/%3E%3C/linearGradient%3E%3Crect width='24' height='24' rx='6' fill='url(%23g)'/%3E%3Cg transform='translate(4.2 4.2) scale(.65)' fill='none' stroke='white' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='4' y='11' width='16' height='9' rx='1.5'/%3E%3Crect x='3' y='7.5' width='18' height='3.5' rx='1'/%3E%3Cpath d='M12 7.5V20M12 7.5C10.5 4.5 7 4 7 6s2.5 1.5 5 1.5zm0 0c1.5-3 5-3.5 5-1.5s-2.5 1.5-5 1.5z'/%3E%3C/g%3E%3C/svg%3E";
// Theme toggle: a cookie only stores a choice that differs from the device setting (docs/technical_specification.md, 2.7)
const THEME_SCRIPT = <<<'JS'
{
    const toggle = document.querySelector('.theme-toggle');
    const root = document.documentElement;
    const deviceDark = matchMedia('(prefers-color-scheme: dark)');
    const isDark = () => (root.dataset.theme ? root.dataset.theme === 'dark' : deviceDark.matches);
    const update = () => toggle.setAttribute('aria-pressed', String(isDark()));
    toggle.addEventListener('click', () => {
        const dark = !isDark();
        if (dark === deviceDark.matches) {
            delete root.dataset.theme;
            document.cookie = 'theme=; path=/; max-age=0';
        } else {
            root.dataset.theme = dark ? 'dark' : 'light';
            document.cookie = `theme=${root.dataset.theme}; path=/; max-age=31536000; samesite=lax`;
        }
        update();
    });
    deviceDark.addEventListener('change', update);
    update();
    toggle.hidden = false;
}
JS;

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . nonce() . "'; "
    . "style-src 'nonce-" . nonce() . "' 'unsafe-hashes' '" . HONEYPOT_STYLE_HASH . "'; "
    . "img-src https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

ini_set('session.save_path', __DIR__ . '/data/sessions');
ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
ini_set('session.gc_probability', '1');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Sessions start only when needed, so anonymous visitors create no session files
if (isset($_COOKIE[session_name()])) {
    start_session();
}

function config(string $key): mixed
{
    static $config = require __DIR__ . '/config.php';
    return $config[$key];
}

function nonce(): string
{
    static $nonce = base64_encode(random_bytes(16));
    return $nonce;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!is_dir(__DIR__ . '/data/sessions')) {
            mkdir(__DIR__ . '/data/sessions', 0700, true);
        }
        session_start();
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function valid_optional_email(string $email): bool
{
    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Giver note from a textarea, false if too long. Browsers send "\r\n" but count it as one character. */
function post_giver_note(string $key): string|false
{
    $note = str_replace("\r\n", "\n", post($key));
    return mb_strlen($note) <= GIVER_NOTE_MAX_LENGTH ? $note : false;
}

/** False if the wish is already reserved or does not exist. */
function insert_reservation(int $wishId, string $name, string $email, string $note, ?string $cancelToken): bool
{
    try {
        db()->prepare('INSERT INTO reservations (wish_id, giver_name, giver_email, giver_note, reserved_at, cancel_token)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$wishId, $name, $email ?: null, $note !== '' ? $note : null, gmdate('Y-m-d H:i:s'), $cancelToken]);
        return true;
    } catch (PDOException $e) {
        // 23000: constraint violation (primary key or foreign key)
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        return false;
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(string $type, string $text): void
{
    start_session();
    $_SESSION['flash'] = [$type, $text];
}

function flash_html(): string
{
    $flash = $_SESSION['flash'] ?? null;
    if ($flash === null) {
        return '';
    }
    unset($_SESSION['flash']);
    return '<p class="flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . '</p>';
}

function csrf_field(): string
{
    start_session();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? null;
    if (!isset($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte lade die Seite neu.');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['is_admin']) && time() - (int)($_SESSION['admin_since'] ?? 0) < ADMIN_LIFETIME;
}

function access_key_hash(): string
{
    return hash('sha256', (string)config('access_key'));
}

function has_access(): bool
{
    return is_admin() || hash_equals(access_key_hash(), (string)($_SESSION['access_key_hash'] ?? ''));
}

function purge_login_attempts(): void
{
    db()->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', ?)")
        ->execute(['-' . LOGIN_RETENTION_DAYS . ' days']);
}

/** Also records the attempt as blocked if throttled (docs/technical_specification.md, 3.4). */
function login_throttled(string $target, ?string $input = null): bool
{
    purge_login_attempts();
    $stmt = db()->prepare("SELECT TOTAL(blocked = 0), TOTAL(blocked) FROM login_attempts
        WHERE ip = ? AND success = 0 AND attempted_at >= datetime('now', '-15 minutes')");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    [$failures, $blocked] = $stmt->fetch(PDO::FETCH_NUM);
    if ($failures < LOGIN_MAX_FAILURES) {
        return false;
    }
    if ($blocked < LOGIN_MAX_FAILURES) {
        record_login_attempt($target, false, $input, true);
    }
    return true;
}

/** $target: 'key' or 'admin'. $input: entered key of failed key logins only (docs/technical_specification.md, 2.9). */
function record_login_attempt(string $target, bool $success, ?string $input = null, bool $blocked = false): void
{
    if ($input !== null) {
        $input = mb_substr(mb_scrub($input, 'UTF-8'), 0, LOGIN_INPUT_MAX_LENGTH, 'UTF-8');
    }
    db()->prepare('INSERT INTO login_attempts (ip, target, success, input, blocked) VALUES (?, ?, ?, ?, ?)')
        ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $target, (int)$success, $input, (int)$blocked]);
}

function format_date(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone((string)config('timezone')))
        ->format($format);
}

/** Wishes in display order (docs/technical_specification.md, 2.2), each with 'recipients' (id, name, is_parent, color). */
function load_wishes(): array
{
    $wishes = [];
    $rows = db()->query('SELECT w.*, r.wish_id IS NOT NULL AS is_reserved, r.giver_name, r.giver_email, r.giver_note, r.reserved_at
        FROM wishes w LEFT JOIN reservations r ON r.wish_id = w.id
        ORDER BY w.is_priority DESC, w.created_at DESC, w.id DESC');
    foreach ($rows as $wish) {
        $wish['recipients'] = [];
        $wishes[$wish['id']] = $wish;
    }
    $rows = db()->query('SELECT wr.wish_id, r.id, r.name, r.is_parent, r.color FROM wish_recipient wr
        JOIN recipients r ON r.id = wr.recipient_id ORDER BY r.name');
    foreach ($rows as $recipient) {
        $wishes[$recipient['wish_id']]['recipients'][] = $recipient;
    }
    $positions = [];
    $sortKeys = [];
    foreach ($wishes as $id => $wish) {
        $forChild = in_array(0, array_column($wish['recipients'], 'is_parent'), true);
        $turn = PHP_INT_MAX;
        foreach ($wish['recipients'] as $recipient) {
            if (!$forChild || !$recipient['is_parent']) {
                $key = $recipient['id'] . '-' . $wish['is_reserved'] . '-' . $wish['is_priority'];
                $positions[$key] = ($positions[$key] ?? 0) + 1;
                $turn = min($turn, $positions[$key]);
            }
        }
        $sortKeys[$id] = [$wish['is_reserved'], !$forChild, !$wish['is_priority'], $turn];
    }
    uksort($wishes, fn (int $a, int $b): int => $sortKeys[$a] <=> $sortKeys[$b]);
    return $wishes;
}

function recipient_tags(array $recipients): string
{
    $html = '';
    foreach ($recipients as $recipient) {
        $html .= ' <span class="recipient color-' . e($recipient['color']) . '">' . e($recipient['name']) . '</span>';
    }
    return '<p class="recipients">Für' . $html . '</p>';
}

function recipient_names(int $wishId): string
{
    $stmt = db()->prepare('SELECT r.name FROM wish_recipient wr JOIN recipients r ON r.id = wr.recipient_id
        WHERE wr.wish_id = ? ORDER BY r.name');
    $stmt->execute([$wishId]);
    return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Reuses one SMTP connection per request and retries once (docs/technical_specification.md, 7.4). */
function send_mail(string $to, string $subject, string $body): bool
{
    static $mail = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            if ($mail === null) {
                require_once __DIR__ . '/lib/PHPMailer/Exception.php';
                require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
                require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
                $smtp = config('smtp');
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->SMTPKeepAlive = true;
                $mail->Host = $smtp['host'];
                $mail->Port = (int)$smtp['port'];
                $mail->SMTPSecure = $smtp['encryption'];
                $mail->SMTPAutoTLS = false;
                $mail->SMTPAuth = $smtp['username'] !== '';
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'];
                $mail->Timeout = 15;
                $mail->CharSet = PHPMailer::CHARSET_UTF8;
                $mail->setFrom($smtp['from_email'], (string)config('site_title'));
            }
            $mail->clearAddresses();
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body . "\n\n" . config('site_title') . "\n";
            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log("Mail to $to failed (attempt $attempt): " . $e->getMessage());
            $mail?->smtpClose();
        }
    }
    return false;
}

/** Notifies parents not linked to the wish (docs/technical_specification.md, 5). */
function notify_parents(int $wishId, string $subject, string $body): bool
{
    $stmt = db()->prepare("SELECT email FROM recipients WHERE is_parent = 1 AND email IS NOT NULL AND email <> ''
        AND id NOT IN (SELECT recipient_id FROM wish_recipient WHERE wish_id = ?)");
    $stmt->execute([$wishId]);
    $ok = true;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $ok = send_mail($email, $subject, $body) && $ok;
    }
    return $ok;
}

function page_start(string $title, string $script = ''): void
{
    $nonce = e(nonce());
    $theme = $_COOKIE['theme'] ?? '';
    echo '<!doctype html>
<html lang="de-CH"' . (in_array($theme, ['light', 'dark'], true) ? ' data-theme="' . $theme . '"' : '') . '>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#b02a5b">
<title>' . e($title) . '</title>
<link rel="icon" href="' . FAVICON . '">
<link rel="stylesheet" href="' . PICO_CSS . '" integrity="' . PICO_CSS_SRI . '" crossorigin="anonymous" nonce="' . $nonce . '">
<link rel="stylesheet" href="/style.css?v=' . filemtime(__DIR__ . '/style.css') . '" nonce="' . $nonce . '">
';
    if ($script !== '') {
        echo '<script nonce="' . $nonce . '">' . $script . '</script>
<script defer src="' . ALPINE_JS . '" integrity="' . ALPINE_JS_SRI . '" crossorigin="anonymous" nonce="' . $nonce . '"></script>
';
    }
    echo '</head>
<body>
<header class="container">
<button type="button" class="theme-toggle secondary outline" aria-label="Dunkles Design" title="Dunkles Design" hidden>
<svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
<svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
</header>
<main class="container">
' . flash_html();
}

function page_end(): void
{
    echo '</main>
<script nonce="' . e(nonce()) . '">' . THEME_SCRIPT . '</script>
</body>
</html>
';
}
