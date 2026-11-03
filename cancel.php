<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = $isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
$wish = false;
if (is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token)) {
    $stmt = db()->prepare('SELECT id, title, giver_name, giver_email FROM wishes WHERE cancel_token = ?');
    $stmt->execute([$token]);
    $wish = $stmt->fetch();
}

if ($isPost) {
    if ($wish) {
        $stmt = db()->prepare('UPDATE wishes SET is_reserved = 0, giver_name = NULL, giver_email = NULL, reserved_at = NULL,
            cancel_token = NULL WHERE id = ? AND cancel_token = ?');
        $stmt->execute([$wish['id'], $token]);
        if ($stmt->rowCount() === 1) {
            $giver = $wish['giver_name'];
            $sent = notify_parents(
                $wish['id'],
                "$giver hat die Reservation von «{$wish['title']}» aufgehoben",
                "$giver ({$wish['giver_email']}) hat die Reservation des Wunsches «{$wish['title']}» für "
                    . recipient_names($wish['id']) . ' aufgehoben.'
            );
            flash(
                $sent ? 'success' : 'warning',
                'Die Reservation ist aufgehoben.' . ($sent ? '' : ' Der E-Mail-Versand ist jedoch fehlgeschlagen.')
            );
            redirect('/cancel');
        }
    }
    flash('warning', 'Dieser Link ist nicht mehr gültig.');
    redirect('/cancel');
}

$hasFlash = isset($_SESSION['flash']);
page_start('Reservation aufheben');
?>
<article class="narrow">
    <h1>Reservation aufheben</h1>
    <?php if ($wish): ?>
        <p>Möchtest du deine Reservation für «<?= e($wish['title']) ?>» aufheben?</p>
        <form method="post" action="/cancel">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button type="submit">Reservation aufheben</button>
        </form>
    <?php elseif (!$hasFlash): ?>
        <p class="flash warning">Dieser Link ist nicht mehr gültig.</p>
    <?php endif; ?>
</article>
<?php
page_end();
