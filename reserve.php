<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
if (!has_access()) {
    http_response_code(403);
    exit('Kein Zugriff.');
}
require_csrf();
if (!empty($_POST['website_url'])) {
    redirect('/');
}

$wishId = (int)post('wish_id');
$name = post('name');
$email = post('email');
if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    flash('warning', 'Bitte gib deinen Namen und eine gültige E-Mail-Adresse an.');
    redirect('/');
}

$token = bin2hex(random_bytes(16));
$stmt = db()->prepare('UPDATE wishes SET is_reserved = 1, giver_name = ?, giver_email = ?, reserved_at = ?, cancel_token = ?
    WHERE id = ? AND is_reserved = 0');
$stmt->execute([$name, $email, gmdate('Y-m-d H:i:s'), $token, $wishId]);
if ($stmt->rowCount() !== 1) {
    flash('warning', 'Dieser Wunsch ist leider nicht mehr verfügbar.');
    redirect('/');
}

$stmt = db()->prepare('SELECT title, description, price, url FROM wishes WHERE id = ?');
$stmt->execute([$wishId]);
$wish = $stmt->fetch();
$for = recipient_names($wishId);
$details = implode("\n", array_filter([
    $wish['title'],
    'Für: ' . $for,
    $wish['description'],
    $wish['price'] ? 'Preis: ca. ' . $wish['price'] : '',
    $wish['url'],
]));

$sent = send_mail(
    $email,
    'Reservation bestätigt: ' . $wish['title'],
    "Hallo $name\n\nDu hast diesen Wunsch reserviert:\n\n$details\n\n"
        . "Falls du die Reservation aufheben möchtest, öffne diesen Link:\n"
        . config('base_url') . '/cancel?token=' . $token
);
$sent = notify_parents(
    $wishId,
    "$name hat «{$wish['title']}» reserviert",
    "$name ($email) hat den Wunsch «{$wish['title']}» für $for reserviert."
) && $sent;

if ($sent) {
    flash('success', 'Danke! Der Wunsch ist für dich reserviert. Du erhältst eine Bestätigung per E-Mail.');
} else {
    flash('warning', 'Der Wunsch ist für dich reserviert, aber der E-Mail-Versand ist fehlgeschlagen.');
}
redirect('/');
