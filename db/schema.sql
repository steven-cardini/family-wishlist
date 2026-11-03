-- Runs once on database creation (see docs/technical_specification.md, 7.3)

-- Family members receiving gifts
CREATE TABLE recipients (
id INTEGER PRIMARY KEY AUTOINCREMENT,
name TEXT NOT NULL,
email TEXT,
is_parent INTEGER NOT NULL DEFAULT 0 -- Parents with email get reservation notifications
);

-- Gift categories
CREATE TABLE categories (
id INTEGER PRIMARY KEY AUTOINCREMENT,
name TEXT NOT NULL
);

-- Wishlist entries
CREATE TABLE wishes (
id INTEGER PRIMARY KEY AUTOINCREMENT,
category_id INTEGER NOT NULL,
title TEXT NOT NULL,
description TEXT,
price TEXT,
url TEXT,
image_url TEXT,
is_priority INTEGER NOT NULL DEFAULT 0,
is_reserved INTEGER NOT NULL DEFAULT 0,
giver_name TEXT,
giver_email TEXT,
reserved_at DATETIME,
cancel_token TEXT UNIQUE,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

-- Many-to-Many: Wishes <-> Recipients
CREATE TABLE wish_recipient (
wish_id INTEGER NOT NULL,
recipient_id INTEGER NOT NULL,
PRIMARY KEY (wish_id, recipient_id),
FOREIGN KEY (wish_id) REFERENCES wishes(id) ON DELETE CASCADE,
FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE RESTRICT
);

-- Failed login attempts (access key & admin password throttling)
CREATE TABLE login_failures (
ip TEXT NOT NULL,
failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Initial Category Seeding
INSERT INTO categories (name) VALUES
('Erlebnisse & Gutscheine'),
('Spielzeug & Spiele'),
('Bücher & Medien'),
('Basteln & Hobbys'),
('Sport & Outdoor'),
('Kleidung & Accessoires'),
('Wohnen & Zimmer'),
('Sonstiges');

PRAGMA user_version = 1;
