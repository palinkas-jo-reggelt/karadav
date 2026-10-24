-- Public share links (Nextcloud-style "share by link")
CREATE TABLE IF NOT EXISTS shares (
	id INTEGER PRIMARY KEY NOT NULL,
	user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	path TEXT NOT NULL,          -- relative to the user's root, no leading slash
	token TEXT NOT NULL,
	password TEXT NULL,          -- password_hash() or NULL
	expiry TEXT NULL,            -- YYYY-MM-DD (valid through the end of that day, UTC) or NULL
	permissions INTEGER NOT NULL DEFAULT 1,
	label TEXT NULL,
	created INTEGER NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS shares_token ON shares (token);
CREATE INDEX IF NOT EXISTS shares_user_path ON shares (user, path);
