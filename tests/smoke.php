<?php

declare(strict_types=1);

// End-to-end tests, see docs/technical_specification.md, 8

const ADMIN_PASSWORD = 'test-admin-password';
const SITE_TITLE = 'Wunschzettel der Familie Test';
const SINK = <<<'PHP'
<?php
declare(strict_types=1);
[, $port, $dir] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port");
$count = 0;
while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if ($conn === false) {
        continue;
    }
    fwrite($conn, "220 sink\r\n");
    $to = [];
    while (($line = fgets($conn)) !== false) {
        $command = strtoupper(substr($line, 0, 4));
        if ($command === 'EHLO') {
            fwrite($conn, "250-sink\r\n250 8BITMIME\r\n");
        } elseif ($command === 'RCPT') {
            preg_match('/<([^>]*)>/', $line, $m);
            $to[] = $m[1];
            fwrite($conn, "250 ok\r\n");
        } elseif ($command === 'DATA') {
            fwrite($conn, "354 go\r\n");
            $data = '';
            while (($line = fgets($conn)) !== false && $line !== ".\r\n") {
                $data .= $line;
            }
            $file = sprintf('%s/%04d.json', $dir, ++$count);
            file_put_contents("$file.tmp", json_encode(['to' => $to, 'data' => $data]));
            rename("$file.tmp", $file);
            $to = [];
            fwrite($conn, "250 ok\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($conn, "221 bye\r\n");
            break;
        } else {
            fwrite($conn, "250 ok\r\n");
        }
    }
    fclose($conn);
}
PHP;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/wishlist-test-' . bin2hex(random_bytes(4));
$app = "$tmp/app";
$mailDir = "$tmp/mails";
$processes = [];
$failures = 0;

register_shutdown_function(function () use (&$processes, $tmp): void {
    foreach ($processes as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    remove_dir($tmp);
});

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copy_app(string $from, string $to): void
{
    mkdir($to, 0700, true);
    foreach (new DirectoryIterator($from) as $item) {
        $name = $item->getFilename();
        if ($item->isDot() || in_array($name, ['.git', 'data', 'config.php'], true)) {
            continue;
        }
        if ($item->isDir()) {
            copy_app($item->getPathname(), "$to/$name");
        } else {
            copy($item->getPathname(), "$to/$name");
        }
    }
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

function start(array $command, string $cwd, string $log): mixed
{
    return proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd);
}

function wait_for_port(int $port): void
{
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket !== false) {
            fclose($socket);
            return;
        }
        usleep(100_000);
    }
    fwrite(STDERR, "Port $port not reachable\n");
    exit(1);
}

function write_config(string $accessKey): void
{
    global $app, $smtpPort, $base;
    $config = [
        'base_url' => $base,
        'timezone' => 'Europe/Zurich',
        'site_title' => SITE_TITLE,
        'access_key' => $accessKey,
        'admin_password_hash' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT),
        'smtp' => [
            'host' => '127.0.0.1',
            'port' => $smtpPort,
            'encryption' => '',
            'username' => '',
            'password' => '',
            'from_email' => 'wishlist@example.com',
        ],
    ];
    file_put_contents("$app/config.php", "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
}

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** HTTP request with a cookie jar per client. */
function http(string $client, string $method, string $path, array $data = []): array
{
    global $base, $tmp;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => "$tmp/$client.cookies",
        CURLOPT_COOKIEJAR => "$tmp/$client.cookies",
    ]);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = (string)curl_exec($curl);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return [
        'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function csrf(string $client, string $path): string
{
    preg_match('/name="csrf" value="([0-9a-f]+)"/', http($client, 'GET', $path)['body'], $m);
    return $m[1] ?? '';
}

/** Posts a form and returns the page it redirects to. */
function submit(string $client, string $path, array $data): string
{
    $response = http($client, 'POST', $path, $data);
    preg_match('/^Location: (\S+)/mi', $response['headers'], $m);
    return isset($m[1]) ? http($client, 'GET', $m[1])['body'] : "status {$response['status']}";
}

function admin(array $data): string
{
    global $adminCsrf;
    return submit('admin', '/admin', $data + ['csrf' => $adminCsrf]);
}

function reserve(int $wishId, string $name, string $email, array $extra = []): string
{
    global $relativeCsrf;
    return submit('relative', '/reserve', $extra + ['csrf' => $relativeCsrf, 'wish_id' => $wishId, 'name' => $name, 'email' => $email]);
}

function q(string $sql, array $params = []): array
{
    global $app;
    $pdo = new PDO("sqlite:$app/data/db.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wish(int $id): array
{
    return q('SELECT w.*, r.*, r.wish_id IS NOT NULL AS is_reserved FROM wishes w
        LEFT JOIN reservations r ON r.wish_id = w.id WHERE w.id = ?', [$id])[0] ?? [];
}

/** Returns and removes received mails: [['to' => [...], 'from' => ..., 'subject' => ..., 'body' => ...]]. */
function take_mails(): array
{
    global $mailDir;
    $mails = [];
    foreach (glob("$mailDir/*.json") as $file) {
        $raw = json_decode((string)file_get_contents($file), true);
        unlink($file);
        [$head, $body] = explode("\r\n\r\n", $raw['data'], 2);
        $headers = iconv_mime_decode_headers($head, 0, 'UTF-8');
        if (stripos($head, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            $body = quoted_printable_decode($body);
        }
        $mails[] = ['to' => $raw['to'], 'from' => $headers['From'] ?? '', 'subject' => $headers['Subject'] ?? '', 'body' => $body];
    }
    return $mails;
}

function recipients_of(array $mails): array
{
    $to = array_merge(...array_column($mails, 'to'));
    sort($to);
    return $to;
}

// Setup
copy_app($root, $app);
mkdir($mailDir);
$httpPort = free_port();
$smtpPort = free_port();
$base = "http://127.0.0.1:$httpPort";
$accessKey = bin2hex(random_bytes(16));
write_config($accessKey);
file_put_contents("$tmp/sink.php", SINK);
$processes[] = start([PHP_BINARY, "$tmp/sink.php", (string)$smtpPort, $mailDir], $tmp, "$tmp/sink.log");
$processes[] = start([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$httpPort", 'router.php'], $app, "$tmp/server.log");
wait_for_port($smtpPort);
wait_for_port($httpPort);

// 3.3, 3.5, 3.6: headers, indexing, file protection
$r = http('anonymous', 'GET', '/');
check('CSP header', str_contains($r['headers'], "Content-Security-Policy: default-src 'none'; script-src 'nonce-")
    && str_contains($r['headers'], "frame-ancestors 'none'"));
check('nosniff, no-referrer, X-Robots-Tag headers', str_contains($r['headers'], 'X-Content-Type-Options: nosniff')
    && str_contains($r['headers'], 'Referrer-Policy: no-referrer') && str_contains($r['headers'], 'X-Robots-Tag: noindex, nofollow'));
check('no HSTS over HTTP', !str_contains($r['headers'], 'Strict-Transport-Security'));
check('meta robots noindex', str_contains($r['body'], '<meta name="robots" content="noindex, nofollow">'));
check('site title on key form', str_contains($r['body'], '<title>' . SITE_TITLE . '</title>') && str_contains($r['body'], '<h1>' . SITE_TITLE . '</h1>'));
check('anonymous visitor gets key form and no session', str_contains($r['body'], 'name="key"')
    && !str_contains($r['body'], 'x-data') && !str_contains($r['headers'], 'Set-Cookie'));
check('robots.txt disallows all', http('anonymous', 'GET', '/robots.txt')['body'] === "User-agent: *\nDisallow: /\n");
foreach (['/data/db.sqlite', '/db/schema.sql', '/docs/technical_specification.md', '/lib/PHPMailer/PHPMailer.php',
    '/tests/smoke.php', '/.htaccess', '/config.php', '/bootstrap.php', '/db.php', '/router.php'] as $path) {
    check("$path is forbidden", http('anonymous', 'GET', $path)['status'] === 403);
}
check('reserve without access is forbidden', http('anonymous', 'POST', '/reserve', ['wish_id' => 1])['status'] === 403);

// 3.1, 3.2: access key and session
$r = http('relative', 'GET', '/?key=wrong');
check('wrong key is rejected', str_contains($r['body'], 'ungültig') && !str_contains($r['body'], 'x-data'));
$r = http('relative', 'GET', '/?key=' . $accessKey);
check('correct key redirects to /', $r['status'] === 303 && str_contains($r['headers'], "Location: /\r\n"));
check('session cookie: 1 year, HttpOnly, SameSite=Lax, not Secure over HTTP',
    preg_match('/^Set-Cookie: PHPSESSID=[^\r]*Max-Age=31536000[^\r]*; HttpOnly; SameSite=Lax\r$/mi', $r['headers']) === 1
    && !preg_match('/^Set-Cookie:[^\r]*Secure/mi', $r['headers']));
check('relative sees wishlist', str_contains(http('relative', 'GET', '/')['body'], 'x-data="wishlist"'));
$relativeCsrf = csrf('relative', '/');

// 7.2: admin authentication
check('admin shows login form', str_contains(http('admin', 'GET', '/admin')['body'], 'name="password"'));
$adminCsrf = csrf('admin', '/admin');
check('admin login without CSRF token fails', http('admin', 'POST', '/admin', ['action' => 'login', 'password' => ADMIN_PASSWORD])['status'] === 400);
check('wrong admin password is rejected', str_contains(admin(['action' => 'login', 'password' => 'wrong']), 'Falsches Passwort'));
check('admin login works', str_contains(admin(['action' => 'login', 'password' => ADMIN_PASSWORD]), 'Neuer Wunsch'));
check('admin session counts as access', str_contains(http('admin', 'GET', '/')['body'], 'x-data="wishlist"'));
check('admin action without CSRF token fails', http('admin', 'POST', '/admin', ['action' => 'recipient_save', 'name' => 'X', 'color' => 'blue'])['status'] === 400);

// 1.1: recipients
check('new recipient form preselects first color', str_contains(http('admin', 'GET', '/admin')['body'], 'value="blue" checked'));
admin(['action' => 'recipient_save', 'name' => 'Kind', 'color' => 'blue']);
admin(['action' => 'recipient_save', 'name' => 'Mama', 'email' => 'mama@example.com', 'is_parent' => 'on', 'color' => 'green']);
admin(['action' => 'recipient_save', 'name' => 'Papa', 'email' => 'papa@example.com', 'is_parent' => 'on', 'color' => 'orange']);
admin(['action' => 'recipient_save', 'name' => 'Oma', 'color' => 'blue']);
check('invalid recipient email is rejected', str_contains(admin(['action' => 'recipient_save', 'name' => 'X', 'email' => 'nope', 'color' => 'blue']), 'gültige E-Mail'));
check('invalid recipient color is rejected', str_contains(admin(['action' => 'recipient_save', 'name' => 'X', 'color' => 'x"><b>']), 'Bitte wähle eine Farbe'));
$ids = array_column(q('SELECT id, name FROM recipients'), 'id', 'name');
check('recipients created', array_keys($ids) === ['Kind', 'Mama', 'Papa', 'Oma']);
admin(['action' => 'recipient_save', 'id' => $ids['Oma'], 'name' => 'Grosi', 'color' => 'red']);
check('recipient renamed and recolored', q('SELECT name, color FROM recipients WHERE id = ?', [$ids['Oma']])[0] === ['name' => 'Grosi', 'color' => 'red']);
$page = http('admin', 'GET', '/admin')['body'];
check('new recipient form preselects least used color', str_contains($page, 'value="violet" checked'));
check('recipient delete needs confirmation', str_contains($page, '<details class="confirm"><summary>Löschen</summary><button type="submit" name="action" value="recipient_delete"'));

// 2.1: wishes
$wishForm = ['action' => 'wish_save', 'description' => '', 'price' => '', 'url' => '', 'image_url' => ''];
check('wish without recipient is rejected', str_contains(admin($wishForm + ['title' => 'X']), 'mindestens einen Empfänger'));
check('javascript: URL is rejected', str_contains(admin(['url' => 'javascript:alert(1)', 'title' => 'X', 'recipients' => [$ids['Kind']]] + $wishForm), 'http://'));
check('unknown recipient is rejected', str_contains(admin(['title' => 'X', 'recipients' => [999]] + $wishForm), 'Ungültiger Wunsch oder Empfänger'));
check('no invalid wish was stored', q('SELECT id FROM wishes') === []);
admin(['title' => 'Lego <b>Set</b>', 'recipients' => [$ids['Kind']], 'is_priority' => 'on', 'description' => "Grösse 134\nblau",
    'price' => 'ca. 30 CHF', 'url' => 'https://shop.example.com/lego', 'image_url' => 'https://shop.example.com/lego.jpg'] + $wishForm);
admin(['title' => 'Buch für Mama', 'recipients' => [$ids['Mama']], 'price' => 'Ca.20 CHF'] + $wishForm);
admin(['title' => 'Velo', 'recipients' => [$ids['Kind']]] + $wishForm);
$wishIds = array_column(q('SELECT id, title FROM wishes'), 'id', 'title');
[$lego, $book, $bike] = [$wishIds['Lego <b>Set</b>'], $wishIds['Buch für Mama'], $wishIds['Velo']];
check('wishes created with recipients', count($wishIds) === 3 && count(q('SELECT * FROM wish_recipient')) === 3);
check('leading "ca." is removed from price', wish($lego)['price'] === '30 CHF' && wish($book)['price'] === '20 CHF');
check('optional fields stored as NULL', wish($bike)['description'] === null && wish($bike)['price'] === null
    && wish($bike)['url'] === null && wish($bike)['image_url'] === null);

// 2.2: wishlist page
$page = http('relative', 'GET', '/')['body'];
check('site title on wishlist', str_contains($page, '<title>' . SITE_TITLE . '</title>') && str_contains($page, '<h1>' . SITE_TITLE . '</h1>'));
check('wish title is HTML-escaped', str_contains($page, 'Lego &lt;b&gt;Set&lt;/b&gt;') && !str_contains($page, '<b>Set</b>'));
check('priority first, then newest first', strpos($page, 'Lego') < strpos($page, 'Velo') && strpos($page, 'Velo') < strpos($page, 'Buch für Mama'));
check('wish data attributes for filtering', str_contains($page, 'data-recipients="' . $ids['Kind'] . '"'));
check('filter lists only recipients with wishes', str_contains($page, 'value="' . $ids['Kind'] . '" x-model')
    && str_contains($page, 'value="' . $ids['Mama'] . '" x-model') && !str_contains($page, 'value="' . $ids['Papa'] . '" x-model')
    && !str_contains($page, 'value="' . $ids['Oma'] . '" x-model'));
check('filter chip in recipient color', str_contains($page, '<label class="recipient color-green"><input type="checkbox" value="' . $ids['Mama'] . '"'));
check('recipient tags in color', str_contains($page, '<p class="recipients">Für <span class="recipient color-blue">Kind</span></p>'));
check('priority wish shown as "Grosser Wunsch"', substr_count($page, '★ Grosser Wunsch') === 1 && str_contains($page, 'class="wish priority"'));
check('price shown with "ca."', str_contains($page, 'Preis: <strong>ca. 30 CHF</strong>') && !str_contains($page, 'ca. ca.'));
check('gift placeholder without image', substr_count($page, '<div class="picture">') === 3 && substr_count($page, '<img ') === 1);
check('no category left', stripos($page, 'categor') === false && stripos($page, 'Kategorie') === false);
check('honeypot field in reservation form', str_contains($page, '<input type="text" name="website_url" style="display:none;" tabindex="-1" autocomplete="off">'));
check('reservation form: email optional, note field', str_contains($page, 'Deine E-Mail (optional) <input type="email" name="email" autocomplete="email">')
    && str_contains($page, '<textarea name="note" rows="2" maxlength="500"'));

// 2.3, 5: reservation
$page = reserve($lego, 'Tante Erika', 'erika@example.com', ['note' => "Kommt per Post.\r\nErika"]);
check('reservation succeeds with spam folder hint', str_contains($page, 'Der Wunsch ist für dich reserviert') && str_contains($page, 'Spam-Ordner'));
$w = wish($lego);
check('reservation is stored', $w['is_reserved'] === 1 && $w['giver_name'] === 'Tante Erika' && $w['giver_email'] === 'erika@example.com'
    && preg_match('/^[0-9a-f]{32}$/', (string)$w['cancel_token']) === 1 && $w['reserved_at'] !== null);
check('note is stored with "\n" line breaks', $w['giver_note'] === "Kommt per Post.\nErika");
$mails = take_mails();
check('mails to giver and both parents', recipients_of($mails) === ['erika@example.com', 'mama@example.com', 'papa@example.com']);
$giverMail = array_values(array_filter($mails, fn ($m) => $m['to'] === ['erika@example.com']))[0] ?? ['from' => '', 'subject' => '', 'body' => ''];
check('site title as sender name and signature', $giverMail['from'] === SITE_TITLE . ' <wishlist@example.com>'
    && str_ends_with(rtrim($giverMail['body']), SITE_TITLE));
check('giver mail contains wish and cancel link', str_contains($giverMail['body'], "$base/cancel?token={$w['cancel_token']}")
    && str_contains($giverMail['body'], 'Grösse 134') && str_contains($giverMail['body'], 'Preis: ca. 30 CHF') && str_contains($giverMail['subject'], 'Lego <b>Set</b>'));
check('giver mail contains note', str_contains(str_replace("\r\n", "\n", $giverMail['body']), "Notiz an die Eltern:\nKommt per Post.\nErika"));
$parentMail = array_values(array_filter($mails, fn ($m) => $m['to'] === ['mama@example.com']))[0] ?? ['body' => ''];
check('parent mail names giver, wish, recipients and note', str_contains(str_replace("\r\n", "\n", $parentMail['body']),
    "Tante Erika (erika@example.com) hat den Wunsch «Lego <b>Set</b>» für Kind reserviert.\n\nNotiz an die Eltern:\nKommt per Post.\nErika"));

check('reserving a reserved wish fails', str_contains(reserve($lego, 'Onkel Max', 'max@example.com'), 'nicht mehr verfügbar'));
check('first reservation is kept, no mails', wish($lego)['giver_name'] === 'Tante Erika' && take_mails() === []);
check('reserving an unknown wish fails', str_contains(reserve(999, 'Onkel Max', 'max@example.com'), 'nicht mehr verfügbar')
    && q('SELECT * FROM reservations WHERE wish_id = 999') === [] && take_mails() === []);

reserve($book, 'Onkel Max', 'max@example.com', ['note' => 'Bringe ich mit']);
check('parent own wish: no notification to that parent', recipients_of(take_mails()) === ['max@example.com', 'papa@example.com']);

reserve($bike, 'Bot', 'bot@example.com', ['website_url' => 'https://spam.example.com']);
check('honeypot blocks reservation silently', wish($bike)['is_reserved'] === 0 && take_mails() === []);
check('invalid giver email is rejected', str_contains(reserve($bike, 'X', 'nope'), 'gültige E-Mail') && wish($bike)['is_reserved'] === 0);
check('too long note is rejected', str_contains(reserve($bike, 'X', '', ['note' => str_repeat('ä', 501)]), 'höchstens 500 Zeichen')
    && wish($bike)['is_reserved'] === 0 && take_mails() === []);
check('reservation without CSRF token fails', http('relative', 'POST', '/reserve', ['wish_id' => $bike, 'name' => 'X', 'email' => 'x@example.com'])['status'] === 400);

$page = http('relative', 'GET', '/')['body'];
$reservedDay = (new DateTimeImmutable($w['reserved_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y');
check('reserved wishes at the bottom with date, giver and note hidden', strpos($page, 'Velo') < strpos($page, 'Lego')
    && str_contains($page, "✓ Reserviert am $reservedDay</span>") && !str_contains($page, 'Tante Erika') && !str_contains($page, 'erika@')
    && !str_contains($page, 'Kommt per Post'));

// 2.4: cancellation
$token = $w['cancel_token'];
$r = http('scanner', 'GET', "/cancel?token=$token");
check('cancel GET shows confirmation, changes nothing', str_contains($r['body'], 'name="token" value="' . $token . '"') && wish($lego)['is_reserved'] === 1);
check('cancel confirmation', str_contains(submit('giver', '/cancel', ['token' => $token]), 'Die Reservation ist aufgehoben.'));
$w = wish($lego);
check('reservation is removed', $w['is_reserved'] === 0 && $w['giver_name'] === null && $w['giver_email'] === null
    && $w['giver_note'] === null && $w['cancel_token'] === null && $w['reserved_at'] === null);
$mails = take_mails();
check('cancel notifies both parents', recipients_of($mails) === ['mama@example.com', 'papa@example.com']
    && str_contains($mails[0]['body'], 'Tante Erika (erika@example.com) hat die Reservation des Wunsches «Lego <b>Set</b>» für Kind aufgehoben'));
check('used cancel link is invalid', str_contains(http('giver', 'GET', "/cancel?token=$token")['body'], 'nicht mehr gültig'));
check('used cancel link cannot cancel again', str_contains(submit('giver', '/cancel', ['token' => $token]), 'nicht mehr gültig') && take_mails() === []);
check('malformed cancel token is invalid', str_contains(http('giver', 'GET', '/cancel?token=abc')['body'], 'nicht mehr gültig'));

// 2.3: reservation without email, longest note ("\r\n" counts as one character)
$longNote = str_repeat('ä', 498) . "\r\nä";
$page = reserve($lego, 'Nachbarin Lea', '', ['note' => $longNote]);
check('reservation without email succeeds, no spam hint', str_contains($page, 'Danke! Der Wunsch ist für dich reserviert.') && !str_contains($page, 'Spam-Ordner'));
$w = wish($lego);
check('reservation without email has no token', $w['is_reserved'] === 1 && $w['giver_email'] === null && $w['cancel_token'] === null
    && $w['giver_note'] === str_replace("\r\n", "\n", $longNote));
$mails = take_mails();
check('without email only parents are notified', recipients_of($mails) === ['mama@example.com', 'papa@example.com']
    && str_contains($mails[0]['body'], 'Nachbarin Lea hat den Wunsch «Lego <b>Set</b>» für Kind reserviert.'));

// 2.5, 2.6: admin reservations and surprise protection
check('manual reservation with too long note fails', str_contains(admin(['action' => 'wish_reserve', 'id' => $bike, 'giver_name' => 'Grosi',
    'giver_note' => str_repeat('a', 501)]), 'höchstens 500 Zeichen') && wish($bike)['is_reserved'] === 0);
admin(['action' => 'wish_reserve', 'id' => $bike, 'giver_name' => 'Grosi', 'giver_email' => '', 'giver_note' => 'Per <b>Telefon</b>']);
$w = wish($bike);
check('manual reservation without email, token or mails', $w['is_reserved'] === 1 && $w['giver_name'] === 'Grosi'
    && $w['giver_email'] === null && $w['giver_note'] === 'Per <b>Telefon</b>' && $w['cancel_token'] === null && take_mails() === []);
check('manual reservation of reserved wish fails', str_contains(admin(['action' => 'wish_reserve', 'id' => $bike, 'giver_name' => 'X']), 'bereits reserviert'));
$page = http('admin', 'GET', '/admin')['body'];
$reservedAt = (new DateTimeImmutable($w['reserved_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i');
check('giver of child wish shown with local time and escaped note', str_contains($page, "<p class=\"pre-line\">Reserviert von Grosi, $reservedAt\nNotiz: Per &lt;b&gt;Telefon&lt;/b&gt;</p>"));
check('reservation removal needs confirmation', str_contains($page, '<details class="confirm"><summary>Reservation entfernen</summary><button type="submit" name="action" value="wish_unreserve"'));
check('wish delete needs confirmation', str_contains($page, '<details class="confirm"><summary>Löschen</summary><button type="submit" name="action" value="wish_delete"'));
check('giver and note of parent wish hidden in details', preg_match('#<details><summary>Reserviert \(Schenkende anzeigen\)</summary><p class="pre-line">Onkel Max \(max@example\.com\), [^<]*\nNotiz: Bringe ich mit</p></details>#', $page) === 1
    && !str_contains($page, 'Reserviert von Onkel Max'));
admin(['action' => 'wish_unreserve', 'id' => $bike]);
check('admin removes reservation and note without mails', wish($bike)['is_reserved'] === 0 && wish($bike)['giver_note'] === null && take_mails() === []);
check('manual reservation form has note field', str_contains(http('admin', 'GET', '/admin')['body'], '<textarea name="giver_note" rows="2" maxlength="500"></textarea>'));

$tokenBefore = wish($book)['cancel_token'];
admin(['id' => $book, 'title' => 'Krimi für Mama', 'recipients' => [$ids['Mama'], $ids['Papa']]] + $wishForm);
$w = wish($book);
check('editing keeps reservation', $w['title'] === 'Krimi für Mama' && $w['is_reserved'] === 1 && $w['cancel_token'] === $tokenBefore
    && count(q('SELECT * FROM wish_recipient WHERE wish_id = ?', [$book])) === 2);
check('linked recipient cannot be deleted', str_contains(admin(['action' => 'recipient_delete', 'id' => $ids['Kind']]), 'kann nicht gelöscht'));
admin(['action' => 'recipient_delete', 'id' => $ids['Oma']]);
check('unlinked recipient deleted', count(q('SELECT * FROM recipients')) === 3);
admin(['action' => 'wish_delete', 'id' => $book]);
check('reserved wish deleted with its reservation, without mails', wish($book) === [] && q('SELECT * FROM wish_recipient WHERE wish_id = ?', [$book]) === []
    && q('SELECT * FROM reservations WHERE wish_id = ?', [$book]) === [] && take_mails() === []);

// 7.2: logout keeps access key authorization, admin login expires after 4 hours
$csrf = csrf('relative', '/');
submit('relative', '/admin', ['csrf' => $csrf, 'action' => 'login', 'password' => ADMIN_PASSWORD]);
check('logout ends admin session', str_contains(submit('relative', '/admin', ['csrf' => $csrf, 'action' => 'logout']), 'name="password"'));
check('logout keeps key access', str_contains(http('relative', 'GET', '/')['body'], 'x-data="wishlist"'));
preg_match('/PHPSESSID\s+(\S+)/', (string)file_get_contents("$tmp/admin.cookies"), $m);
$sessionFile = "$app/data/sessions/sess_{$m[1]}";
file_put_contents($sessionFile, preg_replace('/admin_since\|i:\d+;/', 'admin_since|i:' . (time() - 4 * 3600) . ';', (string)file_get_contents($sessionFile)));
check('admin login expires after 4 hours', str_contains(http('admin', 'GET', '/admin')['body'], 'name="password"'));

// 7.4: SMTP failure
proc_terminate($processes[0]);
proc_close(array_shift($processes));
check('reservation is stored even if mail fails', str_contains(reserve($bike, 'Tante Erika', 'erika@example.com'), 'E-Mail-Versand ist fehlgeschlagen')
    && wish($bike)['is_reserved'] === 1);

// 3.1: changing the key revokes access
$newKey = bin2hex(random_bytes(16));
write_config($newKey);
check('changed key revokes access', str_contains(http('relative', 'GET', '/')['body'], 'name="key"'));

// 3.4: throttling after 10 failures (2 so far: wrong key, wrong password)
for ($i = 0; $i < 8; $i++) {
    http('throttled', 'GET', '/?key=wrong');
}
$r = http('throttled', 'GET', "/?key=$newKey");
check('correct key is rejected after 10 failures', $r['status'] === 200 && str_contains($r['body'], 'Zu viele Fehlversuche'));
$csrf = csrf('throttled', '/admin');
check('admin login is rejected after 10 failures', str_contains(submit('throttled', '/admin', ['csrf' => $csrf, 'action' => 'login', 'password' => ADMIN_PASSWORD]), 'Zu viele Fehlversuche'));

$serverLog = (string)file_get_contents("$tmp/server.log");
check('no PHP warnings or errors', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $serverLog));

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
