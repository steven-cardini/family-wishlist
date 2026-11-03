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
// Hash of the honeypot's inline style "display:none;"
const HONEYPOT_STYLE_HASH = 'sha256-0EZqoz+oBhx7gF4nvY2bSqoGyy4zLjNF+SDQXGp/ZrY=';

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

function login_throttled(): bool
{
    db()->exec("DELETE FROM login_failures WHERE failed_at < datetime('now', '-15 minutes')");
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_failures WHERE ip = ?');
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    return (int)$stmt->fetchColumn() >= 10;
}

function record_login_failure(): void
{
    db()->prepare('INSERT INTO login_failures (ip) VALUES (?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
}

function format_date(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone((string)config('timezone')))
        ->format('d.m.Y H:i');
}

/** Wishes in display order, each with 'recipients' (id, name, is_parent). */
function load_wishes(): array
{
    $wishes = [];
    $rows = db()->query('SELECT w.*, c.name AS category FROM wishes w JOIN categories c ON c.id = w.category_id
        ORDER BY w.is_reserved, w.is_priority DESC, w.created_at DESC, w.id DESC');
    foreach ($rows as $wish) {
        $wish['recipients'] = [];
        $wishes[$wish['id']] = $wish;
    }
    $rows = db()->query('SELECT wr.wish_id, r.id, r.name, r.is_parent FROM wish_recipient wr
        JOIN recipients r ON r.id = wr.recipient_id ORDER BY r.name');
    foreach ($rows as $recipient) {
        $wishes[$recipient['wish_id']]['recipients'][] = $recipient;
    }
    return $wishes;
}

function recipient_names(int $wishId): string
{
    $stmt = db()->prepare('SELECT r.name FROM wish_recipient wr JOIN recipients r ON r.id = wr.recipient_id
        WHERE wr.wish_id = ? ORDER BY r.name');
    $stmt->execute([$wishId]);
    return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function send_mail(string $to, string $subject, string $body): bool
{
    require_once __DIR__ . '/lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
    $smtp = config('smtp');
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPSecure = $smtp['encryption'];
        $mail->SMTPAutoTLS = false;
        $mail->SMTPAuth = $smtp['username'] !== '';
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body . "\n\n" . $smtp['from_name'] . "\n";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Mail failed: ' . $e->getMessage());
        return false;
    }
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
    echo '<!doctype html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . e($title) . '</title>
<link rel="stylesheet" href="' . PICO_CSS . '" integrity="' . PICO_CSS_SRI . '" crossorigin="anonymous" nonce="' . $nonce . '">
<style nonce="' . $nonce . '">
.flash{padding:.75rem 1rem;border-radius:var(--pico-border-radius);color:#1b1b1b}
.success{background:#c9f0d3}
.warning{background:#fbd9b4}
.reserved{opacity:.5}
.wish img{display:block;max-width:100%;max-height:12rem;margin:0 auto 1rem;object-fit:contain}
.pre-line{white-space:pre-line}
</style>
';
    if ($script !== '') {
        echo '<script nonce="' . $nonce . '">' . $script . '</script>
<script defer src="' . ALPINE_JS . '" integrity="' . ALPINE_JS_SRI . '" crossorigin="anonymous" nonce="' . $nonce . '"></script>
';
    }
    echo '</head>
<body>
<main class="container">
' . flash_html();
}

function page_end(): void
{
    echo '</main>
</body>
</html>
';
}
