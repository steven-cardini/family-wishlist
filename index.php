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

if ($error !== '' || !has_access()) {
    page_start('Wunschliste');
    ?>
<h1>Wunschliste</h1>
<?php if ($error !== ''): ?><p class="flash warning" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="get" action="/">
    <label>Zugangsschlüssel <input type="password" name="key" required autocomplete="current-password"></label>
    <button type="submit">Öffnen</button>
</form>
<?php
    page_end();
    exit;
}

$recipients = db()->query('SELECT id, name FROM recipients ORDER BY name')->fetchAll();
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$wishes = load_wishes();

$script = <<<'JS'
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) event.target.hidden = true;
}, true);
document.addEventListener('alpine:init', () => {
    Alpine.data('wishlist', () => ({
        recipients: [],
        category: '',
        status: 'available',
        wishId: '',
        wishTitle: '',
        shows(el) {
            const data = el.dataset;
            return (this.status === 'all' || (this.status === 'reserved') === (data.reserved === '1'))
                && (this.category === '' || this.category === data.category)
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

page_start('Wunschliste', $script);
?>
<div x-data="wishlist">
<h1>Wunschliste</h1>

<fieldset>
    <legend>Für</legend>
    <?php foreach ($recipients as $recipient): ?>
        <label><input type="checkbox" value="<?= e($recipient['id']) ?>" x-model="recipients"> <?= e($recipient['name']) ?></label>
    <?php endforeach; ?>
</fieldset>
<div class="grid">
    <label>Kategorie
        <select x-model="category">
            <option value="">Alle Kategorien</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select x-model="status">
            <option value="available">Nur verfügbare</option>
            <option value="reserved">Nur reservierte</option>
            <option value="all">Alle</option>
        </select>
    </label>
</div>

<div x-ref="list">
<?php foreach ($wishes as $wish): ?>
    <article class="wish<?= $wish['is_reserved'] ? ' reserved' : '' ?>"
        data-category="<?= e($wish['category_id']) ?>"
        data-recipients="<?= e(implode(',', array_column($wish['recipients'], 'id'))) ?>"
        data-reserved="<?= e($wish['is_reserved']) ?>"
        x-show="shows($el)">
        <?php if ($wish['image_url']): ?>
            <img src="<?= e($wish['image_url']) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <h2><?= $wish['is_priority'] ? '<mark>Favorit</mark> ' : '' ?><?= e($wish['title']) ?></h2>
        <p><small>Für <?= e(implode(', ', array_column($wish['recipients'], 'name'))) ?> · <?= e($wish['category']) ?></small></p>
        <?php if ($wish['description']): ?>
            <p class="pre-line"><?= e($wish['description']) ?></p>
        <?php endif; ?>
        <?php if ($wish['price']): ?>
            <p>Preis: <?= e($wish['price']) ?></p>
        <?php endif; ?>
        <?php if ($wish['url']): ?>
            <p><a href="<?= e($wish['url']) ?>" target="_blank" rel="noopener noreferrer">Zum Shop</a></p>
        <?php endif; ?>
        <?php if ($wish['is_reserved']): ?>
            <p><strong>Bereits reserviert</strong></p>
        <?php else: ?>
            <button type="button" data-id="<?= e($wish['id']) ?>" data-title="<?= e($wish['title']) ?>" @click="openReserve($event)">Reservieren</button>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<p x-show="noneShown">Keine passenden Wünsche gefunden.</p>

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
