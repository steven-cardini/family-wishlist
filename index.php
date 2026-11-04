<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';
if (isset($_GET['key'])) {
    $key = is_string($_GET['key']) ? trim($_GET['key']) : '';
    if (login_throttled()) {
        $error = 'Zu viele Fehlversuche. Bitte versuche es später nochmals.';
    } elseif ($key !== '' && hash_equals((string)config('access_key'), $key)) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['access_key_hash'] = access_key_hash();
        redirect('/');
    } else {
        record_login_failure();
        $error = 'Dieser Code ist ungültig.';
    }
}

$title = (string)config('site_title');
if ($error !== '' || !has_access()) {
    page_start($title);
    ?>
<article class="narrow">
    <h1><?= e($title) ?></h1>
    <p class="lead">Gib den Code ein. Falls du keinen hast, frag die Eltern nach dem Code oder Link zum Wunschzettel.</p>
    <?php if ($error !== ''): ?><p class="flash warning" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="get" action="/">
        <label>Code <input type="text" name="key" required autocomplete="off" autocapitalize="none" spellcheck="false"></label>
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
$giver = $_SESSION['giver'] ?? ['name' => '', 'email' => ''];
$shareUrl = config('base_url') . '/?key=' . rawurlencode((string)config('access_key'));

$script = <<<'JS'
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) event.target.hidden = true;
}, true);
document.addEventListener('alpine:init', () => {
    Alpine.data('wishlist', () => ({
        recipients: [],
        status: 'available',
        recipientNames: [],
        wishId: '',
        wishTitle: '',
        shareStatus: '',
        init() {
            this.recipientNames = [...this.$el.querySelectorAll('.chips input')]
                .map((input) => ({ id: input.value, name: input.parentElement.textContent.trim() }));
            // Filters survive the reload after a reservation (sessionStorage may be unavailable)
            try {
                const saved = JSON.parse(sessionStorage.getItem('filters')) ?? {};
                if (['available', 'reserved', 'all'].includes(saved.status)) this.status = saved.status;
                if (Array.isArray(saved.recipients)) {
                    this.recipients = this.recipientNames.map((r) => r.id).filter((id) => saved.recipients.includes(id));
                }
            } catch {}
            const save = () => {
                try {
                    sessionStorage.setItem('filters', JSON.stringify({ recipients: this.recipients, status: this.status }));
                } catch {}
            };
            this.$watch('recipients', save);
            this.$watch('status', save);
        },
        shows(el) {
            const data = el.dataset;
            return (this.status === 'all' || (this.status === 'reserved') === (data.reserved === '1'))
                && (this.recipients.length === 0 || data.recipients.split(',').some((id) => this.recipients.includes(id)));
        },
        get shownCount() {
            return [...this.$refs.list.children].filter((el) => this.shows(el)).length;
        },
        get noneShown() {
            return this.shownCount === 0;
        },
        get filtered() {
            return this.recipients.length > 0 || this.status === 'reserved';
        },
        get summaryClass() {
            return this.filtered ? 'active' : '';
        },
        get summary() {
            const count = this.shownCount;
            if (!this.filtered) {
                return this.status === 'all'
                    ? `${count} ${count === 1 ? 'Wunsch' : 'Wünsche'}`
                    : `${count} ${count === 1 ? 'verfügbarer Wunsch' : 'verfügbare Wünsche'}`;
            }
            const filters = [];
            if (this.recipients.length > 0) {
                filters.push('für ' + this.recipientNames.filter((r) => this.recipients.includes(r.id)).map((r) => r.name).join(' oder '));
            }
            if (this.status !== 'all') {
                filters.push(this.status === 'reserved' ? 'nur reservierte' : 'nur verfügbare');
            }
            const total = this.$refs.list.children.length;
            return `Filter aktiv (${filters.join(', ')}): ${count} von ${total} ${total === 1 ? 'Wunsch' : 'Wünschen'}`;
        },
        resetFilters() {
            this.recipients = [];
            this.status = 'available';
        },
        openReserve(event) {
            this.wishId = event.currentTarget.dataset.id;
            this.wishTitle = event.currentTarget.dataset.title;
            this.$refs.dialog.showModal();
        },
        closeReserve() {
            this.$refs.dialog.close();
        },
        async share(event) {
            const url = event.currentTarget.dataset.url;
            try {
                if (navigator.share) {
                    await navigator.share({ title: document.title, url });
                } else {
                    await navigator.clipboard.writeText(url);
                    this.shareStatus = 'Link kopiert.';
                }
            } catch (error) {
                if (error.name !== 'AbortError') this.shareStatus = `Kopiere diesen Link: ${url}`;
            }
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

<div class="filter-summary" :class="summaryClass">
    <p role="status" x-text="summary"></p>
    <button type="button" class="outline" x-show="filtered" x-cloak @click="resetFilters">Filter zurücksetzen</button>
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
            <?php if ($wish['is_priority']): ?><span class="badge priority"><span aria-hidden="true">★</span> Grosser Wunsch</span><?php endif; ?>
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
                <a href="<?= e($wish['url']) ?>" target="_blank" rel="noopener noreferrer" role="button" class="secondary outline" aria-label="Zum Shop (öffnet in neuem Tab)">Zum Shop ↗</a>
            <?php endif; ?>
            <?php if ($wish['is_reserved']): ?>
                <span class="status"><span aria-hidden="true">✓</span> Reserviert am <?= e(format_date($wish['reserved_at'], 'd.m.Y')) ?></span>
            <?php else: ?>
                <button type="button" data-id="<?= e($wish['id']) ?>" data-title="<?= e($wish['title']) ?>" @click="openReserve($event)">Reservieren</button>
            <?php endif; ?>
        </footer>
    </article>
<?php endforeach; ?>
</div>
<p class="empty" x-show="noneShown" x-cloak>Keine passenden Wünsche gefunden.</p>

<section class="share">
    <p>Möchte noch jemand etwas schenken? Teile den Wunschzettel.</p>
    <button type="button" class="secondary outline" data-url="<?= e($shareUrl) ?>" @click="share($event)">Link teilen</button>
    <p role="status" x-text="shareStatus"></p>
</section>

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
            <label>Dein Name <input type="text" name="name" value="<?= e($giver['name']) ?>" required autocomplete="name"></label>
            <label>Deine E-Mail (optional) <input type="email" name="email" value="<?= e($giver['email']) ?>" autocomplete="email"></label>
            <small>Mit E-Mail erhältst du eine Bestätigung mit Link, falls du es dir anders überlegst. Ohne E-Mail können nur die Eltern die Reservation aufheben.</small>
            <label>Notiz an die Eltern (optional) <textarea name="note" rows="2" maxlength="<?= GIVER_NOTE_MAX_LENGTH ?>" placeholder="z.B. Wir bringen es an die Weihnachtsfeier mit."></textarea></label>
            <button type="submit">Reservieren</button>
        </form>
    </article>
</dialog>
</div>
<?php
page_end();
