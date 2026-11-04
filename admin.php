<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/** Host pattern => tracking parameter pattern; '/^/' matches all hosts. */
const TRACKING_PARAMS = [
    '/^/' => '/^(utm_.*|fbclid|gclid|gclsrc|dclid|gbraid|wbraid|gad_source|gad_campaignid|msclkid|yclid|twclid'
        . '|ttclid|igshid|li_fat_id|mc_cid|mc_eid|_ga|_gl|_hsenc|_hsmi|mkt_tok|srsltid)$/i',
    '/(^|\.)amazon\.[a-z.]+$/i' => '/^(ref|ref_|pd_rd_.*|pf_rd_.*|content-id|crid|dib|dib_tag|keywords|qid|sprefix|sr'
        . '|_encoding|tag|linkcode|linkid|ascsubtag)$/i',
    '/(^|\.)nalda\.ch$/i' => '/^(tm|ap|aaid)$/i',
];
const CURRENCIES = ['CHF', 'EUR', 'USD'];

/** Removes tracking query parameters (docs/technical_specification.md, 2.1). */
function clean_url(string $url): string
{
    if (preg_match('/^([^?#]*)\?([^#]*)(.*)$/s', $url, $m) !== 1) {
        return $url;
    }
    $host = (string)parse_url($url, PHP_URL_HOST);
    $patterns = array_filter(TRACKING_PARAMS, fn (string $hostPattern): bool => preg_match($hostPattern, $host) === 1,
        ARRAY_FILTER_USE_KEY);
    $params = array_filter(explode('&', $m[2]), function (string $param) use ($patterns): bool {
        $name = rawurldecode(explode('=', $param, 2)[0]);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return false;
            }
        }
        return $name !== '';
    });
    return $m[1] . ($params === [] ? '' : '?' . implode('&', $params)) . $m[3];
}

/** Splits a stored price into [amount, currency]; the first currency is the default. */
function split_price(string $price): array
{
    $currencies = implode('|', CURRENCIES);
    if (preg_match("/^($currencies)(?![a-z])\\s*(.*)$/i", $price, $m) === 1) {
        return [$m[2], strtoupper($m[1])];
    }
    if (preg_match("/^(.*?)\\s*(?<![a-z])($currencies)$/i", $price, $m) === 1) {
        return [$m[1], strtoupper($m[2])];
    }
    return [$price, CURRENCIES[0]];
}

function valid_url(string $url): bool
{
    return $url === '' || (preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false);
}

function count_rows(string $sql, int $id): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
}

/** Two-step submit button against accidental clicks (docs/technical_specification.md, 2.8). */
function confirm_button(string $label, string $confirmLabel, string $action): string
{
    return '<details class="confirm"><summary>' . e($label) . '</summary>'
        . '<button type="submit" name="action" value="' . e($action) . '" class="secondary" formnovalidate>'
        . e($confirmLabel) . '</button></details>';
}

function wish_confirm_form(int $id, string $label, string $confirmLabel, string $action): void
{
    ?>
    <form method="post" action="/admin">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <?= confirm_button($label, $confirmLabel, $action) ?>
    </form>
    <?php
}

/** Least used color, preselected for new recipients. */
function next_color(): string
{
    $used = db()->query('SELECT color, COUNT(*) FROM recipients GROUP BY color')->fetchAll(PDO::FETCH_KEY_PAIR);
    $counts = [];
    foreach (array_keys(RECIPIENT_COLORS) as $color) {
        $counts[$color] = $used[$color] ?? 0;
    }
    return (string)array_search(min($counts), $counts, true);
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
        <fieldset class="chips">
            <legend>Farbe</legend>
            <?php foreach (RECIPIENT_COLORS as $color => $label): ?>
                <label class="recipient color-<?= e($color) ?>"><input type="radio" name="color" value="<?= e($color) ?>"<?= $color === $recipient['color'] ? ' checked' : '' ?> required> <?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" name="action" value="recipient_save"><?= $recipient['id'] ? 'Speichern' : 'Hinzufügen' ?></button>
        <?php if ($recipient['id']): ?>
            <?= confirm_button('Löschen', 'Wirklich löschen', 'recipient_delete') ?>
        <?php endif; ?>
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
            $url = clean_url(post('url'));
            $imageUrl = clean_url(post('image_url'));
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
            // "ca." is added on display (docs/technical_specification.md, 2.1)
            $amount = split_price(preg_replace('/^ca(\.\s*|\s+)/i', '', post('price')))[0];
            $currency = post('currency');
            if ($amount !== '' && !in_array($currency, CURRENCIES, true)) {
                return ['warning', 'Bitte wähle eine Währung.'];
            }
            $values = [$title, post('description') ?: null, $amount === '' ? null : "$amount $currency",
                $url ?: null, $imageUrl ?: null, isset($_POST['is_priority']) ? 1 : 0];
            $db->beginTransaction();
            try {
                if ($id > 0) {
                    $db->prepare('UPDATE wishes SET title = ?, description = ?, price = ?, url = ?,
                        image_url = ?, is_priority = ? WHERE id = ?')->execute([...$values, $id]);
                    $db->prepare('DELETE FROM wish_recipient WHERE wish_id = ?')->execute([$id]);
                } else {
                    $db->prepare('INSERT INTO wishes (title, description, price, url, image_url, is_priority)
                        VALUES (?, ?, ?, ?, ?, ?)')->execute($values);
                    $id = (int)$db->lastInsertId();
                }
                $stmt = $db->prepare('INSERT INTO wish_recipient (wish_id, recipient_id) VALUES (?, ?)');
                foreach ($recipientIds as $recipientId) {
                    $stmt->execute([$id, $recipientId]);
                }
                $db->commit();
            } catch (PDOException) {
                // Foreign key violation: unknown wish or recipient
                $db->rollBack();
                return ['warning', 'Ungültiger Wunsch oder Empfänger.'];
            }
            return ['success', 'Wunsch gespeichert.'];

        case 'wish_delete':
            $db->prepare('DELETE FROM wishes WHERE id = ?')->execute([$id]);
            return ['success', 'Wunsch gelöscht.'];

        case 'wish_reserve':
            $name = post('giver_name');
            $email = post('giver_email');
            $note = post_giver_note('giver_note');
            if ($name === '' || !valid_optional_email($email)) {
                return ['warning', 'Bitte gib einen Namen und optional eine gültige E-Mail-Adresse an.'];
            }
            if ($note === false) {
                return ['warning', 'Die Notiz darf höchstens ' . GIVER_NOTE_MAX_LENGTH . ' Zeichen lang sein.'];
            }
            return insert_reservation($id, $name, $email, $note, null)
                ? ['success', 'Wunsch reserviert.']
                : ['warning', 'Dieser Wunsch ist bereits reserviert.'];

        case 'wish_unreserve':
            $db->prepare('DELETE FROM reservations WHERE wish_id = ?')->execute([$id]);
            return ['success', 'Reservation entfernt.'];

        case 'recipient_save':
            $name = post('name');
            $email = post('email');
            $color = post('color');
            if ($name === '' || !valid_optional_email($email)) {
                return ['warning', 'Bitte gib einen Namen und optional eine gültige E-Mail-Adresse an.'];
            }
            if (!array_key_exists($color, RECIPIENT_COLORS)) {
                return ['warning', 'Bitte wähle eine Farbe.'];
            }
            $values = [$name, $email ?: null, isset($_POST['is_parent']) ? 1 : 0, $color];
            if ($id > 0) {
                $db->prepare('UPDATE recipients SET name = ?, email = ?, is_parent = ?, color = ? WHERE id = ?')->execute([...$values, $id]);
            } else {
                $db->prepare('INSERT INTO recipients (name, email, is_parent, color) VALUES (?, ?, ?, ?)')->execute($values);
            }
            return ['success', 'Empfänger gespeichert.'];

        case 'recipient_delete':
            if (count_rows('SELECT COUNT(*) FROM wish_recipient WHERE recipient_id = ?', $id) > 0) {
                return ['warning', 'Dieser Empfänger hat noch Wünsche und kann nicht gelöscht werden.'];
            }
            $db->prepare('DELETE FROM recipients WHERE id = ?')->execute([$id]);
            return ['success', 'Empfänger gelöscht.'];
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
            record_login_attempt('admin', true);
        } else {
            record_login_attempt('admin', false);
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
<article class="narrow">
    <h1>Verwaltung</h1>
    <form method="post" action="/admin">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <label>Passwort <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">Anmelden</button>
    </form>
</article>
<?php
    page_end();
    exit;
}

$recipients = db()->query('SELECT * FROM recipients ORDER BY name')->fetchAll();
$wishes = load_wishes();

$editId = (int)($_GET['edit'] ?? 0);
$edit = $wishes[$editId] ?? ['id' => 0, 'title' => '', 'description' => '', 'price' => '', 'url' => '',
    'image_url' => '', 'is_priority' => 0, 'recipients' => []];
$editRecipientIds = array_column($edit['recipients'], 'id');
[$editAmount, $editCurrency] = split_price((string)$edit['price']);
?>
<h1>Verwaltung</h1>
<form method="post" action="/admin">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="logout">
    <div role="group">
        <a href="/" role="button" class="secondary outline">Zum Wunschzettel</a>
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
        <label>Beschreibung (optional) <textarea name="description" rows="3"><?= e($edit['description']) ?></textarea></label>
        <label for="price">Ungefährer Preis (optional)</label>
        <div role="group" class="price">
            <input type="text" id="price" name="price" value="<?= e($editAmount) ?>" placeholder="30">
            <select name="currency" aria-label="Währung">
                <?php foreach (CURRENCIES as $currency): ?>
                    <option<?= $currency === $editCurrency ? ' selected' : '' ?>><?= e($currency) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label>Shop-Link (optional) <input type="url" name="url" value="<?= e($edit['url']) ?>" placeholder="https://"></label>
        <label>Bild-Link (optional) <input type="url" name="image_url" value="<?= e($edit['image_url']) ?>" placeholder="https://"></label>
        <fieldset>
            <legend>Für</legend>
            <?php foreach ($recipients as $recipient): ?>
                <label><input type="checkbox" name="recipients[]" value="<?= e($recipient['id']) ?>"<?= in_array($recipient['id'], $editRecipientIds, true) ? ' checked' : '' ?>> <?= e($recipient['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label><input type="checkbox" name="is_priority" role="switch"<?= $edit['is_priority'] ? ' checked' : '' ?>> Grosser Wunsch</label>
        <button type="submit">Speichern</button>
        <?php if ($edit['id']): ?><a href="/admin" role="button" class="secondary outline">Abbrechen</a><?php endif; ?>
    </form>
</section>

<section>
    <h2>Wünsche</h2>
    <?php foreach ($wishes as $wish): ?>
        <article>
            <?php if ($wish['is_priority']): ?><p><span class="badge priority"><span aria-hidden="true">★</span> Grosser Wunsch</span></p><?php endif; ?>
            <h3><?= e($wish['title']) ?></h3>
            <?= recipient_tags($wish['recipients']) ?>
            <?php if ($wish['is_reserved']):
                $giver = $wish['giver_name'] . ($wish['giver_email'] ? ' (' . $wish['giver_email'] . ')' : '')
                    . ', ' . format_date($wish['reserved_at'])
                    . ($wish['giver_note'] !== null ? "\nNotiz: " . $wish['giver_note'] : '');
                ?>
                <?php if (in_array(1, array_column($wish['recipients'], 'is_parent'), true)): ?>
                    <details><summary>Reserviert (Schenkende anzeigen)</summary><p class="pre-line"><?= e($giver) ?></p></details>
                <?php else: ?>
                    <p class="pre-line">Reserviert von <?= e($giver) ?></p>
                <?php endif; ?>
                <?php wish_confirm_form($wish['id'], 'Reservation entfernen', 'Wirklich entfernen', 'wish_unreserve'); ?>
            <?php else: ?>
                <details>
                    <summary>Manuell reservieren</summary>
                    <form method="post" action="/admin">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="wish_reserve">
                        <input type="hidden" name="id" value="<?= e($wish['id']) ?>">
                        <label>Name <input type="text" name="giver_name" required></label>
                        <label>E-Mail (optional) <input type="email" name="giver_email"></label>
                        <label>Notiz (optional) <textarea name="giver_note" rows="2" maxlength="<?= GIVER_NOTE_MAX_LENGTH ?>"></textarea></label>
                        <button type="submit">Reservieren</button>
                    </form>
                </details>
            <?php endif; ?>
            <footer>
                <p><a href="/admin?edit=<?= e($wish['id']) ?>#wish-form" role="button" class="outline">Bearbeiten</a></p>
                <?php wish_confirm_form($wish['id'], 'Löschen', 'Wirklich löschen', 'wish_delete'); ?>
            </footer>
        </article>
    <?php endforeach; ?>
</section>

<section>
    <h2>Empfänger</h2>
    <?php foreach ($recipients as $recipient): ?>
        <article>
            <p><span class="recipient color-<?= e($recipient['color']) ?>"><?= e($recipient['name']) ?></span><br>
                <small><?= e($recipient['email'] ?: 'Keine E-Mail') ?><?= $recipient['is_parent'] ? ' · Elternteil' : '' ?></small></p>
            <details>
                <summary role="button" class="outline">Bearbeiten</summary>
                <?php recipient_form($recipient); ?>
            </details>
        </article>
    <?php endforeach; ?>
    <h3>Neuer Empfänger</h3>
    <?php recipient_form(['id' => 0, 'name' => '', 'email' => '', 'is_parent' => 0, 'color' => next_color()]); ?>
</section>

<section>
    <h2>Protokoll</h2>
    <p><a href="/audit">Anmeldungen und Fehlversuche ansehen</a></p>
</section>
<?php
page_end();
