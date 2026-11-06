-- Version 1 -> 2 (see docs/technical_specification.md, 7.3)
ALTER TABLE login_attempts ADD COLUMN input TEXT;
ALTER TABLE login_attempts ADD COLUMN blocked INTEGER NOT NULL DEFAULT 0;

PRAGMA user_version = 2;
