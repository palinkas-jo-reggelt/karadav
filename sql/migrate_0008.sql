-- A public link can be created by a user who only has access to a folder shared with them
ALTER TABLE shares ADD COLUMN creator INTEGER NULL;
