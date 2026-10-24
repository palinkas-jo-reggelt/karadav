<?php
// Re-index the files of all users. Run this after copying files into data/<login>/ by hand:
//     php bin/reindex.php
$_SERVER['SERVER_NAME'] ??= 'localhost';
$_SERVER['SERVER_PORT'] ??= 80;

require __DIR__ . '/../init.php';

(new KaraDAV\Users)->indexAllFiles();
echo "Done.\n";
