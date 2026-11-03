<?php

declare(strict_types=1);

// Local dev only: emulates .htaccess for `php -S localhost:8000 router.php`
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|db|docs|lib|tests)(/|$)|/\.|^/(router|config|bootstrap|db)\.php$#', $path)) {
    http_response_code(403);
    exit;
}
if (preg_match('#^/(admin|reserve|cancel)$#', $path)) {
    require __DIR__ . $path . '.php';
    exit;
}
return false;
