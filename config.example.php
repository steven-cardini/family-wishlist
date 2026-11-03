<?php

declare(strict_types=1);

// Copy to config.php and adjust. Never commit config.php.
return [
    // Public URL without trailing slash, used for links in emails
    'base_url' => 'https://example.com',

    // Used to display dates (stored in UTC)
    'timezone' => 'Europe/Zurich',

    // Secret for the relatives' link: https://example.com/?key=...
    // Generate: php -r 'echo bin2hex(random_bytes(24)), "\n";'
    'access_key' => 'change-me',

    // Generate: php -r 'echo password_hash(rtrim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'
    // then type the password and press Enter. Keep the single quotes (the hash contains "$").
    'admin_password_hash' => '',

    'smtp' => [
        'host' => 'mail.example.com',
        'port' => 587,
        'encryption' => 'tls', // 'tls' (STARTTLS, port 587), 'ssl' (port 465) or '' (none, local tests only)
        'username' => 'wishlist@example.com',
        'password' => '',
        'from_email' => 'wishlist@example.com',
        'from_name' => 'Familien-Wunschliste',
    ],
];
