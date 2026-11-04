<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';
if (isset($_GET['key'])) {
    $key = is_string($_GET['key']) ? $_GET['key'] : '';
    if (login_throttled()) {
        $error = 'Zu viele Fehlversuche. Bitte versuche es später nochmals.';
    } elseif ($key !== '' && hash_equals((string)config('access_key'), $key)) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['access_key_hash'] = access_key_hash();
        redirect('/');
    } else {
        record_login_failure();
        $error = 'Dieser Zugangsschlüssel ist ungültig.';
    }
}

$title = (string)config('site_title');
if ($error !== '' || !has_access()) {
    page_start($title);
    ?>
<article class="narrow">
    <h1><?= e($title) ?></h1>
    <p class="lead">Gib den Zugangsschlüssel ein, den du erhalten hast.</p>
    <?php if ($error !== ''): ?><p class="flash warning" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="get" action="/">
        <label>Zugangsschlüssel <input type="password" name="key" required autocomplete="current-password"></label>
        <button type="submit">Öffnen</button>
    </form>
</article>
<?php
    page_end();
    exit;
}

$recipients = db()->query('SELECT id, name, color FROM recipients WHERE id IN (SELECT recipient_id FROM wish_recipient)
    ORDER BY name')->fetchAll();
$wishes = load_wishes();

$script = <<<'JS'
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) event.target.hidden = true;
}, true);
document.addEventListener('alpine:init', () => {
    Alpine.data('wishlist', () => ({
        recipients: [],
        status: 'available',
        wishId: '',
        wishTitle: '',
        shows(el) {
            const data = el.dataset;
            return (this.status === 'all' || (this.status === 'reserved') === (data.reserved === '1'))
                && (this.recipients.length === 0 || data.recipients.split(',').some((id) => this.recipients.includes(id)));
        },
        get noneShown() {
            return ![...this.$refs.list.children].some((el) => this.shows(el));
        },
        openReserve(event) {
            this.wishId = event.currentTarget.dataset.id;
            this.wishTitle = event.currentTarget.dataset.title;
            this.$refs.dialog.showModal();
        },
        closeReserve() {
            this.$refs.dialog.close();
        },
    }));
});
JS;

page_start($title, $script);
?>
<div x-data="wishlist">
<h1><?= e($title) ?></h1>
<p class="lead">Such dir einen Wunsch aus und reserviere ihn, damit nichts doppelt geschenkt wird.</p>

<div class="filters">
<?php if ($recipients): ?>
<fieldset class="chips">
    <legend>Für</legend>
    <?php foreach ($recipients as $recipient): ?>
        <label class="recipient color-<?= e($recipient['color']) ?>"><input type="checkbox" value="<?= e($recipient['id']) ?>" x-model="recipients"> <?= e($recipient['name']) ?></label>
    <?php endforeach; ?>
</fieldset>
<?php endif; ?>
<label>Status
    <select x-model="status">
        <option value="available">Nur verfügbare</option>
        <option value="reserved">Nur reservierte</option>
        <option value="all">Alle</option>
    </select>
</label>
</div>

<div class="wish-grid" x-ref="list">
<?php foreach ($wishes as $wish): ?>
    <article class="wish<?= $wish['is_reserved'] ? ' reserved' : '' ?><?= $wish['is_priority'] ? ' priority' : '' ?>"
        data-recipients="<?= e(implode(',', array_column($wish['recipients'], 'id'))) ?>"
        data-reserved="<?= e($wish['is_reserved']) ?>"
        x-show="shows($el)">
        <div class="picture">
            <?php if ($wish['image_url']): ?>
                <img src="<?= e($wish['image_url']) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <?php if ($wish['is_priority']): ?><span class="badge priority">★ Grosser Wunsch</span><?php endif; ?>
        </div>
        <?= recipient_tags($wish['recipients']) ?>
        <h2><?= e($wish['title']) ?></h2>
        <?php if ($wish['description']): ?>
            <p class="pre-line"><?= e($wish['description']) ?></p>
        <?php endif; ?>
        <?php if ($wish['price']): ?>
            <p>Preis: <strong>ca. <?= e($wish['price']) ?></strong></p>
        <?php endif; ?>
        <footer>
            <?php if ($wish['url']): ?>
                <a href="<?= e($wish['url']) ?>" target="_blank" rel="noopener noreferrer" role="button" class="secondary outline">Zum Shop ↗</a>
            <?php endif; ?>
            <?php if ($wish['is_reserved']): ?>
                <span class="status">✓ Bereits reserviert</span>
            <?php else: ?>
                <button type="button" data-id="<?= e($wish['id']) ?>" data-title="<?= e($wish['title']) ?>" @click="openReserve($event)">Reservieren</button>
            <?php endif; ?>
        </footer>
    </article>
<?php endforeach; ?>
</div>
<p class="empty" x-show="noneShown">Keine passenden Wünsche gefunden.</p>

<dialog x-ref="dialog">
    <article>
        <header>
            <button type="button" rel="prev" aria-label="Schliessen" @click="closeReserve"></button>
            <h2 x-text="wishTitle"></h2>
        </header>
        <form method="post" action="/reserve">
            <?= csrf_field() ?>
            <input type="hidden" name="wish_id" :value="wishId">
            <input type="text" name="website_url" style="display:none;" tabindex="-1" autocomplete="off">
            <label>Dein Name <input type="text" name="name" required autocomplete="name"></label>
            <label>Deine E-Mail <input type="email" name="email" required autocomplete="email"></label>
            <small>Du erhältst eine Bestätigung mit einem Link, um die Reservation aufzuheben.</small>
            <button type="submit">Reservieren</button>
        </form>
    </article>
</dialog>
</div>
<?php
page_end();
