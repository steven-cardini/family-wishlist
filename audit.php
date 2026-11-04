<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const AUDIT_LIMIT = 200;

if (!is_admin()) {
    redirect('/admin');
}

function attempt_label(string $target, bool $success): string
{
    return match (true) {
        $target === 'admin' && $success => 'Anmeldung Verwaltung',
        $target === 'admin' => 'Falsches Passwort',
        default => 'Falscher Code',
    };
}

purge_login_attempts();
$stmt = db()->prepare('SELECT * FROM login_attempts ORDER BY attempted_at DESC, rowid DESC LIMIT ?');
$stmt->bindValue(1, AUDIT_LIMIT, PDO::PARAM_INT);
$stmt->execute();
$attempts = $stmt->fetchAll();

page_start('Protokoll');
?>
<h1>Protokoll</h1>
<p><a href="/admin" role="button" class="secondary outline">Zur Verwaltung</a></p>
<p>Anmeldungen in der Verwaltung und Fehlversuche der letzten <?= LOGIN_RETENTION_DAYS ?> Tage, neueste zuerst (max. <?= AUDIT_LIMIT ?>).</p>
<?php if ($attempts === []): ?>
    <p>Keine Einträge.</p>
<?php else: ?>
    <table class="audit">
        <thead><tr><th>Zeit</th><th>Ereignis</th></tr></thead>
        <tbody>
        <?php foreach ($attempts as $attempt): ?>
            <tr>
                <td><?= e(format_date($attempt['attempted_at'])) ?></td>
                <td><?= e(attempt_label($attempt['target'], (bool)$attempt['success'])) ?><br><small>IP <?= e($attempt['ip']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
page_end();
