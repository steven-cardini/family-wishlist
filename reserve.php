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
$note = post_giver_note('note');
if ($name === '' || !valid_optional_email($email)) {
    flash('warning', 'Bitte gib deinen Namen und optional eine gültige E-Mail-Adresse an.');
    redirect('/');
}
if ($note === false) {
    flash('warning', 'Die Notiz darf höchstens ' . GIVER_NOTE_MAX_LENGTH . ' Zeichen lang sein.');
    redirect('/');
}

// Without email there is no cancellation link (docs/technical_specification.md, 2.3)
$token = $email !== '' ? bin2hex(random_bytes(16)) : null;
if (!insert_reservation($wishId, $name, $email, $note, $token)) {
    flash('warning', 'Dieser Wunsch ist leider nicht mehr verfügbar.');
    redirect('/');
}
// Prefills the next reservation form
$_SESSION['giver'] = ['name' => $name, 'email' => $email];

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

$noteText = $note !== '' ? "\n\nNotiz an die Eltern:\n$note" : '';

$sent = $email === '' || send_mail(
    $email,
    'Reservation bestätigt: ' . $wish['title'],
    "Hallo $name\n\nDu hast diesen Wunsch reserviert:\n\n$details$noteText\n\n"
        . "Falls du die Reservation aufheben möchtest, öffne diesen Link:\n"
        . config('base_url') . '/cancel?token=' . $token
);
$giver = $email !== '' ? "$name ($email)" : $name;
$sent = notify_parents(
    $wishId,
    "$name hat «{$wish['title']}» reserviert",
    "$giver hat den Wunsch «{$wish['title']}» für $for reserviert.$noteText"
) && $sent;

if (!$sent) {
    flash('warning', 'Der Wunsch ist für dich reserviert, aber der E-Mail-Versand ist fehlgeschlagen.');
} elseif ($email !== '') {
    flash('success', 'Danke! Der Wunsch ist für dich reserviert. Du erhältst eine Bestätigung per E-Mail (falls nicht, schau im Spam-Ordner nach).');
} else {
    flash('success', 'Danke! Der Wunsch ist für dich reserviert.');
}
redirect('/');
