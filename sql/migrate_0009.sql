-- Failed login attempts, to slow down password guessing
CREATE TABLE IF NOT EXISTS auth_failures (
	scope TEXT NOT NULL,
	time INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS auth_failures_scope ON auth_failures (scope, time);
