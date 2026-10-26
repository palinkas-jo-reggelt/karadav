# Public share links (fork addition)

This fork adds Nextcloud-style **public link sharing** to KaraDAV, so that the Nextcloud/ownCloud
desktop client's *Share* dialog (right-click a file or folder in the file manager) works.

## What is supported

* Public **link** shares for files and folders (read-only downloads)
* Optional **password** and **expiration date** per link, several links per item
* Public page `/s/<token>`: file info + download, or folder browsing, per-file download and
  "Download all as ZIP" (needs the PHP `zip` extension)
* Resumable downloads (HTTP Range)
* The OCS share API used by the clients: `GET/POST/PUT/DELETE /ocs/v2.php/apps/files_sharing/api/v1/shares`
  (and `/sharees`, which returns an empty list), plus the matching capabilities
* Shares follow files when renamed/moved, and are removed when the file or folder is deleted

## What is NOT supported

* Public *upload* / file drop, sharing with other users or groups, e-mail shares, reshare
* Editing share permissions (links are always read-only)
* A web UI to list your shares (use the desktop client's share dialog)

## Files

* `lib/KaraDAV/Shares.php` – storage and helpers (table `shares`, see `sql/migrate_0005.sql`)
* `lib/KaraDAV/PublicShare.php` – the public `/s/<token>` pages and downloads
* `lib/KaraDAV/NextCloud.php` – OCS share endpoints and capabilities
* `lib/compat.php`, `init.php` – Windows / FastCGI compatibility helpers

## Moving files in from elsewhere

KaraDAV indexes files when a user is created, or when you run:

    php bin/reindex.php

Copy your files into `data/<login>/` first, then run the command above.

## Recommended settings (config.local.php)

    const ERRORS_SHOW = false;     // default: error details go to error.log in the KaraDAV root folder, not to visitors
    const WWW_URL = 'https://files.example.com/';   // set it explicitly behind a proxy / on a subfolder
