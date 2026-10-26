-- Login flow v2 (applications connecting to an account): started by the application,
-- approved by the user in the browser, collected by the application with its own token.
-- Both tokens are stored hashed.
CREATE TABLE IF NOT EXISTS login_flows (
	id INTEGER PRIMARY KEY NOT NULL,
	login_token TEXT NOT NULL,   -- in the URL opened in the browser
	poll_token TEXT NOT NULL,    -- secret of the application
	user_agent TEXT NULL,
	ip TEXT NOT NULL,
	expiry TEXT NOT NULL,
	user INTEGER NULL REFERENCES users(id) ON DELETE CASCADE  -- set when the user approved
);

CREATE UNIQUE INDEX IF NOT EXISTS login_flows_login_token ON login_flows (login_token);
CREATE UNIQUE INDEX IF NOT EXISTS login_flows_poll_token ON login_flows (poll_token);
CREATE INDEX IF NOT EXISTS login_flows_ip ON login_flows (ip);

-- Pending login tokens of the previous flow, chosen by the caller: they cannot be used any more
DELETE FROM app_sessions WHERE password IS NULL;
