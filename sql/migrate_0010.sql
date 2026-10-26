-- "Stay logged in" tokens are now stored hashed: forget the old ones, which were plain session identifiers
UPDATE users SET session_id = NULL;
