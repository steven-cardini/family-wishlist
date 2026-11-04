-- Runs once on database creation (see docs/technical_specification.md, 7.3)

-- Family members receiving gifts
CREATE TABLE recipients (
id INTEGER PRIMARY KEY AUTOINCREMENT,
name TEXT NOT NULL,
email TEXT,
is_parent INTEGER NOT NULL DEFAULT 0, -- Parents with email get reservation notifications
color TEXT NOT NULL -- Key of RECIPIENT_COLORS in bootstrap.php
);

-- Wishlist entries
CREATE TABLE wishes (
id INTEGER PRIMARY KEY AUTOINCREMENT,
title TEXT NOT NULL,
description TEXT,
price TEXT,
url TEXT,
image_url TEXT,
is_priority INTEGER NOT NULL DEFAULT 0,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- At most one reservation per wish
CREATE TABLE reservations (
wish_id INTEGER PRIMARY KEY,
giver_name TEXT NOT NULL,
giver_email TEXT,
giver_note TEXT,
reserved_at DATETIME NOT NULL,
cancel_token TEXT UNIQUE, -- Only for givers with email
FOREIGN KEY (wish_id) REFERENCES wishes(id) ON DELETE CASCADE
);

-- Many-to-Many: Wishes <-> Recipients
CREATE TABLE wish_recipient (
wish_id INTEGER NOT NULL,
recipient_id INTEGER NOT NULL,
PRIMARY KEY (wish_id, recipient_id),
FOREIGN KEY (wish_id) REFERENCES wishes(id) ON DELETE CASCADE,
FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE RESTRICT
);

-- Successful admin logins and all failed logins (throttling and /audit)
CREATE TABLE login_attempts (
ip TEXT NOT NULL,
target TEXT NOT NULL, -- 'key' (access key) or 'admin'
success INTEGER NOT NULL,
attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

PRAGMA user_version = 1;
