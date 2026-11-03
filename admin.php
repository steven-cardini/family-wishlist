<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function valid_url(string $url): bool
{
    return $url === '' || (preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false);
}

function valid_optional_email(string $email): bool
{
    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function count_rows(string $sql, int $id): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
}

function recipient_form(array $recipient): void
{
    ?>
    <form method="post" action="/admin">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($recipient['id']) ?>">
        <label>Name <input type="text" name="name" value="<?= e($recipient['name']) ?>" required></label>
        <label>E-Mail (optional) <input type="email" name="email" value="<?= e($recipient['email']) ?>"></label>
        <label><input type="checkbox" name="is_parent" role="switch"<?= $recipient['is_parent'] ? ' checked' : '' ?>> Elternteil (erhält Benachrichtigungen)</label>
        <div role="group">
            <button type="submit" name="action" value="recipient_save"><?= $recipient['id'] ? 'Speichern' : 'Hinzufügen' ?></button>
            <?php if ($recipient['id']): ?>
                <button type="submit" name="action" value="recipient_delete" class="secondary" formnovalidate>Löschen</button>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

function category_form(array $category): void
{
    ?>
    <form method="post" action="/admin">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($category['id']) ?>">
        <input type="text" name="name" value="<?= e($category['name']) ?>" aria-label="Name" required>
        <div role="group">
            <button type="submit" name="action" value="category_save"><?= $category['id'] ? 'Speichern' : 'Hinzufügen' ?></button>
            <?php if ($category['id']): ?>
                <button type="submit" name="action" value="category_delete" class="secondary" formnovalidate>Löschen</button>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/** Returns [flash type, message]. */
function handle_action(string $action, int $id): array
{
    $db = db();
    switch ($action) {
        case 'logout':
            unset($_SESSION['is_admin'], $_SESSION['admin_since']);
            return ['success', 'Du bist abgemeldet.'];

        case 'wish_save':
            $title = post('title');
            $url = post('url');
            $imageUrl = post('image_url');
            $recipientIds = array_unique(array_map('intval', array_filter((array)($_POST['recipients'] ?? []), 'is_string')));
            if ($title === '') {
                return ['warning', 'Bitte gib einen Titel an.'];
            }
            if ($recipientIds === []) {
                return ['warning', 'Bitte wähle mindestens einen Empfänger.'];
            }
            if (!valid_url($url) || !valid_url($imageUrl)) {
                return ['warning', 'Links müssen mit http:// oder https:// beginnen.'];
            }
            $values = [(int)post('category_id'), $title, post('description') ?: null, post('price') ?: null,
                $url ?: null, $imageUrl ?: null, isset($_POST['is_priority']) ? 1 : 0];
            $db->beginTransaction();
            try {
                if ($id > 0) {
                    $db->prepare('UPDATE wishes SET category_id = ?, title = ?, description = ?, price = ?, url = ?,
                        image_url = ?, is_priority = ? WHERE id = ?')->execute([...$values, $id]);
                    $db->prepare('DELETE FROM wish_recipient WHERE wish_id = ?')->execute([$id]);
                } else {
                    $db->prepare('INSERT INTO wishes (category_id, title, description, price, url, image_url, is_priority)
                        VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($values);
                    $id = (int)$db->lastInsertId();
                }
                $stmt = $db->prepare('INSERT INTO wish_recipient (wish_id, recipient_id) VALUES (?, ?)');
                foreach ($recipientIds as $recipientId) {
                    $stmt->execute([$id, $recipientId]);
                }
                $db->commit();
            } catch (PDOException) {
                // Foreign key violation: unknown wish, category or recipient
                $db->rollBack();
                return ['warning', 'Ungültige Kategorie oder ungültiger Empfänger.'];
            }
            return ['success', 'Wunsch gespeichert.'];

        case 'wish_delete':
            $db->prepare('DELETE FROM wishes WHERE id = ?')->execute([$id]);
            return ['success', 'Wunsch gelöscht.'];

        case 'wish_reserve':
            $name = post('giver_name');
            $email = post('giver_email');
            if ($name === '' || !valid_optional_email($email)) {
                return ['warning', 'Bitte gib einen Namen und optional eine gültige E-Mail-Adresse an.'];
            }
            $stmt = $db->prepare('UPDATE wishes SET is_reserved = 1, giver_name = ?, giver_email = ?, reserved_at = ?,
                cancel_token = NULL WHERE id = ? AND is_reserved = 0');
            $stmt->execute([$name, $email ?: null, gmdate('Y-m-d H:i:s'), $id]);
            return $stmt->rowCount() === 1
                ? ['success', 'Wunsch reserviert.']
                : ['warning', 'Dieser Wunsch ist bereits reserviert.'];

        case 'wish_unreserve':
            $db->prepare('UPDATE wishes SET is_reserved = 0, giver_name = NULL, giver_email = NULL, reserved_at = NULL,
                cancel_token = NULL WHERE id = ?')->execute([$id]);
            return ['success', 'Reservation entfernt.'];

        case 'recipient_save':
            $name = post('name');
            $email = post('email');
            if ($name === '' || !valid_optional_email($email)) {
                return ['warning', 'Bitte gib einen Namen und optional eine gültige E-Mail-Adresse an.'];
            }
            $values = [$name, $email ?: null, isset($_POST['is_parent']) ? 1 : 0];
            if ($id > 0) {
                $db->prepare('UPDATE recipients SET name = ?, email = ?, is_parent = ? WHERE id = ?')->execute([...$values, $id]);
            } else {
                $db->prepare('INSERT INTO recipients (name, email, is_parent) VALUES (?, ?, ?)')->execute($values);
            }
            return ['success', 'Empfänger gespeichert.'];

        case 'recipient_delete':
            if (count_rows('SELECT COUNT(*) FROM wish_recipient WHERE recipient_id = ?', $id) > 0) {
                return ['warning', 'Dieser Empfänger hat noch Wünsche und kann nicht gelöscht werden.'];
            }
            $db->prepare('DELETE FROM recipients WHERE id = ?')->execute([$id]);
            return ['success', 'Empfänger gelöscht.'];

        case 'category_save':
            $name = post('name');
            if ($name === '') {
                return ['warning', 'Bitte gib einen Namen an.'];
            }
            if ($id > 0) {
                $db->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            } else {
                $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
            }
            return ['success', 'Kategorie gespeichert.'];

        case 'category_delete':
            if (count_rows('SELECT COUNT(*) FROM wishes WHERE category_id = ?', $id) > 0) {
                return ['warning', 'Diese Kategorie wird noch verwendet und kann nicht gelöscht werden.'];
            }
            $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            return ['success', 'Kategorie gelöscht.'];
    }
    return ['warning', 'Unbekannte Aktion.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        if (login_throttled()) {
            flash('warning', 'Zu viele Fehlversuche. Bitte versuche es später nochmals.');
        } elseif (is_string($password) && password_verify($password, (string)config('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_since'] = time();
        } else {
            record_login_failure();
            flash('warning', 'Falsches Passwort.');
        }
        redirect('/admin');
    }
    if (!is_admin()) {
        flash('warning', 'Bitte melde dich erneut an.');
        redirect('/admin');
    }
    [$type, $message] = handle_action($action, (int)post('id'));
    flash($type, $message);
    redirect('/admin');
}

start_session();
page_start('Verwaltung');

if (!is_admin()) {
    ?>
<h1>Verwaltung</h1>
<form method="post" action="/admin">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <label>Passwort <input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit">Anmelden</button>
</form>
<?php
    page_end();
    exit;
}

$recipients = db()->query('SELECT * FROM recipients ORDER BY name')->fetchAll();
$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$wishes = load_wishes();

$editId = (int)($_GET['edit'] ?? 0);
$edit = $wishes[$editId] ?? ['id' => 0, 'title' => '', 'description' => '', 'price' => '', 'url' => '',
    'image_url' => '', 'category_id' => 0, 'is_priority' => 0, 'recipients' => []];
$editRecipientIds = array_column($edit['recipients'], 'id');
?>
<h1>Verwaltung</h1>
<form method="post" action="/admin">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="logout">
    <div role="group">
        <a href="/" role="button" class="secondary outline">Zur Wunschliste</a>
        <button type="submit" class="secondary">Abmelden</button>
    </div>
</form>

<section id="wish-form">
    <h2><?= $edit['id'] ? 'Wunsch bearbeiten' : 'Neuer Wunsch' ?></h2>
    <form method="post" action="/admin">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="wish_save">
        <input type="hidden" name="id" value="<?= e($edit['id']) ?>">
        <label>Titel <input type="text" name="title" value="<?= e($edit['title']) ?>" required></label>
        <label>Beschreibung <textarea name="description" rows="3"><?= e($edit['description']) ?></textarea></label>
        <label>Preis <input type="text" name="price" value="<?= e($edit['price']) ?>" placeholder="ca. 30 CHF"></label>
        <label>Shop-Link <input type="url" name="url" value="<?= e($edit['url']) ?>" placeholder="https://"></label>
        <label>Bild-Link <input type="url" name="image_url" value="<?= e($edit['image_url']) ?>" placeholder="https://"></label>
        <label>Kategorie
            <select name="category_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category['id']) ?>"<?= $category['id'] === $edit['category_id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset>
            <legend>Für</legend>
            <?php foreach ($recipients as $recipient): ?>
                <label><input type="checkbox" name="recipients[]" value="<?= e($recipient['id']) ?>"<?= in_array($recipient['id'], $editRecipientIds, true) ? ' checked' : '' ?>> <?= e($recipient['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label><input type="checkbox" name="is_priority" role="switch"<?= $edit['is_priority'] ? ' checked' : '' ?>> Favorit</label>
        <button type="submit">Speichern</button>
        <?php if ($edit['id']): ?><a href="/admin" role="button" class="secondary outline">Abbrechen</a><?php endif; ?>
    </form>
</section>

<section>
    <h2>Wünsche</h2>
    <?php foreach ($wishes as $wish): ?>
        <article>
            <h3><?= $wish['is_priority'] ? '<mark>Favorit</mark> ' : '' ?><?= e($wish['title']) ?></h3>
            <p><small>Für <?= e(implode(', ', array_column($wish['recipients'], 'name'))) ?> · <?= e($wish['category']) ?></small></p>
            <?php if ($wish['is_reserved']):
                $giver = $wish['giver_name'] . ($wish['giver_email'] ? ' (' . $wish['giver_email'] . ')' : '')
                    . ', ' . format_date($wish['reserved_at']) . ($wish['cancel_token'] ? '' : ', manuell');
                ?>
                <?php if (in_array(1, array_column($wish['recipients'], 'is_parent'), true)): ?>
                    <details><summary>Reserviert (Schenkende anzeigen)</summary><p><?= e($giver) ?></p></details>
                <?php else: ?>
                    <p>Reserviert von <?= e($giver) ?></p>
                <?php endif; ?>
                <form method="post" action="/admin">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="wish_unreserve">
                    <input type="hidden" name="id" value="<?= e($wish['id']) ?>">
                    <button type="submit" class="secondary outline">Reservation entfernen</button>
                </form>
            <?php else: ?>
                <details>
                    <summary>Manuell reservieren</summary>
                    <form method="post" action="/admin">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="wish_reserve">
                        <input type="hidden" name="id" value="<?= e($wish['id']) ?>">
                        <label>Name <input type="text" name="giver_name" required></label>
                        <label>E-Mail (optional) <input type="email" name="giver_email"></label>
                        <button type="submit">Reservieren</button>
                    </form>
                </details>
            <?php endif; ?>
            <footer>
                <p><a href="/admin?edit=<?= e($wish['id']) ?>#wish-form" role="button" class="outline">Bearbeiten</a></p>
                <details>
                    <summary>Löschen</summary>
                    <form method="post" action="/admin">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="wish_delete">
                        <input type="hidden" name="id" value="<?= e($wish['id']) ?>">
                        <button type="submit" class="secondary">Wirklich löschen</button>
                    </form>
                </details>
            </footer>
        </article>
    <?php endforeach; ?>
</section>

<section>
    <h2>Empfänger</h2>
    <?php foreach ($recipients as $recipient): ?>
        <article>
            <p><strong><?= e($recipient['name']) ?></strong><br>
                <small><?= e($recipient['email'] ?: 'Keine E-Mail') ?><?= $recipient['is_parent'] ? ' · Elternteil' : '' ?></small></p>
            <details>
                <summary role="button" class="outline">Bearbeiten</summary>
                <?php recipient_form($recipient); ?>
            </details>
        </article>
    <?php endforeach; ?>
    <h3>Neuer Empfänger</h3>
    <?php recipient_form(['id' => 0, 'name' => '', 'email' => '', 'is_parent' => 0]); ?>
</section>

<section>
    <h2>Kategorien</h2>
    <?php foreach ($categories as $category): ?>
        <article>
            <p><strong><?= e($category['name']) ?></strong></p>
            <details>
                <summary role="button" class="outline">Bearbeiten</summary>
                <?php category_form($category); ?>
            </details>
        </article>
    <?php endforeach; ?>
    <h3>Neue Kategorie</h3>
    <?php category_form(['id' => 0, 'name' => '']); ?>
</section>
<?php
page_end();
