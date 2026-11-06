<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const AUDIT_LIMIT = 200;

if (!is_admin()) {
    redirect('/admin');
}

function attempt_label(string $target, bool $success, bool $blocked): string
{
    return match (true) {
        $blocked => $target === 'admin' ? 'Anmeldung gesperrt (Verwaltung)' : 'Anmeldung gesperrt (Code)',
        $target === 'admin' && $success => 'Anmeldung Verwaltung',
        $target === 'admin' => 'Falsches Passwort',
        default => 'Falscher Code',
    };
}

$showAll = ($_GET['view'] ?? '') === 'all';

purge_login_attempts();
$counts = db()->query('SELECT success, COUNT(*) FROM login_attempts GROUP BY success')->fetchAll(PDO::FETCH_KEY_PAIR);
$tabs = ['/audit' => 'Fehlversuche (' . ($counts[0] ?? 0) . ')', '/audit?view=all' => 'Alle (' . array_sum($counts) . ')'];
$stmt = db()->prepare('SELECT * FROM login_attempts WHERE success = 0 OR ? ORDER BY attempted_at DESC, rowid DESC LIMIT ?');
$stmt->bindValue(1, (int)$showAll, PDO::PARAM_INT);
$stmt->bindValue(2, AUDIT_LIMIT, PDO::PARAM_INT);
$stmt->execute();
$attempts = $stmt->fetchAll();

page_start('Protokoll');
?>
<h1>Protokoll</h1>
<p><a href="/admin" role="button" class="secondary outline">Zur Verwaltung</a></p>
<nav class="tabs" aria-label="Ansicht">
    <?php foreach ($tabs as $url => $label): ?>
        <a href="<?= e($url) ?>"<?= ($url === '/audit?view=all') === $showAll ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<p><?= $showAll ? 'Anmeldungen in der Verwaltung und Fehlversuche' : 'Fehlversuche' ?> der letzten <?= LOGIN_RETENTION_DAYS ?> Tage, neueste zuerst (max. <?= AUDIT_LIMIT ?>).</p>
<?php if ($attempts === []): ?>
    <p><?= $showAll ? 'Keine Einträge.' : 'Keine Fehlversuche.' ?></p>
<?php else: ?>
    <table class="audit">
        <thead><tr><th>Zeit</th><th>Ereignis</th></tr></thead>
        <tbody>
        <?php foreach ($attempts as $attempt): ?>
            <tr<?= $attempt['success'] ? '' : ' class="failed"' ?>>
                <td><?= e(format_date($attempt['attempted_at'])) ?></td>
                <td><?= e(attempt_label($attempt['target'], (bool)$attempt['success'], (bool)$attempt['blocked'])) ?><?php if ($attempt['input'] !== null): ?> <?= $attempt['input'] === '' ? '(leer)' : '<code>' . e($attempt['input']) . '</code>' ?><?php endif; ?><br><small>IP <?= e($attempt['ip']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
page_end();
