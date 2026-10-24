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
