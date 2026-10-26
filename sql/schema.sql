CREATE TABLE users (
	id INTEGER PRIMARY KEY NOT NULL,
	login TEXT NOT NULL,
	password TEXT NOT NULL,
	quota INTEGER NULL,
	is_admin INTEGER NOT NULL DEFAULT 0,
	session_id TEXT NULL,
	display_name TEXT NULL
);

CREATE UNIQUE INDEX users_login ON users(login);
CREATE UNIQUE INDEX users_session_id ON users (session_id);

CREATE TABLE locks (
	user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	uri TEXT NOT NULL,
	token TEXT NOT NULL,
	scope TEXT NOT NULL,
	expiry TEXT NOT NULL
);

CREATE INDEX locks_uri ON locks (user, uri);

CREATE UNIQUE INDEX locks_unique ON locks (user, uri, token);

CREATE TABLE app_sessions (
	user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	token TEXT NULL, -- Temporary token, exchanged for an app password
	user_agent TEXT NULL,
	password TEXT NULL,
	expiry TEXT NOT NULL
);

CREATE INDEX app_sessions_idx ON app_sessions (user);
CREATE UNIQUE INDEX app_sessions_token ON app_sessions (token);

-- Files properties stored using PROPPATCH
-- We are not using this currently, this is just to get test coverage from litmus
CREATE TABLE properties (
	user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	uri TEXT NOT NULL,
	name TEXT NOT NULL,
	attributes TEXT NULL,
	xml TEXT NULL
);

CREATE UNIQUE INDEX properties_unique ON properties (user, uri, name);

CREATE TABLE files (
	id INTEGER PRIMARY KEY NOT NULL,
	user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	path TEXT NOT NULL,
	modified INTEGER NOT NULL,
	size INTEGER NOT NULL
);

CREATE UNIQUE INDEX files_path ON files (user, path);
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
	created INTEGER NOT NULL,
	creator INTEGER NULL         -- user who made the link, if not the owner (folder shared with them)
);

CREATE UNIQUE INDEX IF NOT EXISTS shares_token ON shares (token);
CREATE INDEX IF NOT EXISTS shares_user_path ON shares (user, path);
-- Folder shares between users (the shared folder appears in the recipient's root)
CREATE TABLE IF NOT EXISTS user_shares (
	id INTEGER PRIMARY KEY NOT NULL,
	owner INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	path TEXT NOT NULL,          -- folder, relative to the owner's root, no leading slash
	recipient INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	mount TEXT NOT NULL,         -- name of the folder in the recipient's root
	permissions INTEGER NOT NULL DEFAULT 1,  -- 1 = read, 2 = write (read + create/edit/delete)
	created INTEGER NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS user_shares_mount ON user_shares (recipient, mount);
CREATE UNIQUE INDEX IF NOT EXISTS user_shares_target ON user_shares (owner, path, recipient);
