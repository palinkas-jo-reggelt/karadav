# KaraDAV Shares

A fork of [KaraDAV](https://github.com/kd2org/karadav), the lightweight PHP/SQLite WebDAV server that is compatible with Nextcloud and ownCloud clients. For installation, configuration and everything not listed below, see the original project and its documentation.

This fork turns KaraDAV into a self-hosted file sharing site (a replacement for FileRun or a small Dropbox for a team).

## What is different

### Sharing

- **Public share links** (`/s/<token>`) for files and folders, with optional password and expiry date. Links can be downloaded as a single file, a ZIP of a folder, or browsed folder by folder.
- **Nextcloud client support for sharing:** the desktop and mobile apps show "Sharing options" and can create, edit and remove share links.
- **User-to-user folder sharing**, read-only or read/write. A shared folder appears in the recipient's root, so a team can use one "company-docs" folder without seeing each other's personal folders. Managed on the new **Sharing** page and through the Nextcloud sharing API; the Nextcloud client sees changes in shared folders.

### Previews

- **Share pages and the web file manager** preview images, PDF, video, audio, text, `docx` and `xlsx` (text preview only, rendered in the browser with mammoth and SheetJS, no external service).
- **Image lightbox** with left/right arrows for shared folders, and previous/next image browsing in the file manager.
- **Spreadsheet previews** keep a restricted page width, and switch to full width automatically when the sheet is wider than the page.
- A note on share pages marks documents that are shown as a text preview.

### File manager (My files)

- **Folder tree** on the left that expands automatically to the folder you are in, with the current folder highlighted.
- **Share button** on each row to create, copy, open or remove the `/s/` link of that file or folder.
- **File name search** over your own files and folders shared with you (accent and case insensitive, several words allowed).
- Compact rows and smaller text, working layout on mobile.

### Interface and branding

- Minimalist black and white theme with light and dark modes (follows the system setting), applied to the whole site.
- **Banner** on share pages (30 px) with a custom site name and an optional logo, set in `config.local.php`:

  ```php
  const SHARE_SITE_NAME = 'My Files';
  const SHARE_LOGO_URL  = 'https://example.com/logo.png';
  ```

- **Display names:** users have a login and a separate full name shown to others (for example "shared by John Smith").

### Performance

Tuned for large data sets (tens of thousands of files):

- Folder sizes and ETags use indexed range queries instead of `LIKE` scans.
- Used space is read from the file index, instead of walking the whole data folder on every request.

Run `php bin/reindex.php` once after moving files into the data folder by hand, and again whenever files are added outside KaraDAV.

### Database

Automatic migrations on the first request after upgrading:

- `0005` public shares
- `0006` display names
- `0007` user-to-user shares

## Requirements

- **PHP 8.0 or newer** (developed and run on PHP 8.5)
- PHP extensions: `sqlite3`, `simplexml`, `mbstring`, `fileinfo`
- Optional: `gd` or `imagick` for image thumbnails
- A web server with HTTPS: Apache, nginx, or PHP's built-in server for testing (`make server`)
  - Apache with `mod_fcgid` needs `FcgidPassHeader Authorization`, otherwise Nextcloud and WebDAV clients cannot log in
  - Set `WWW_URL` in `config.local.php` to the full public URL of the site (with the trailing slash)
- A modern browser for the web interface
- No database server and no Linux-only components: it runs on Windows (for example XAMPP) as well as Linux

## Installation

1. Copy the files to the server, with the web root pointing at the `www` folder.
2. Copy `config.dist.php` to `config.local.php` and set at least `WWW_URL`. Keep `config.local.php` and the `data/` folder out of version control.
3. Open the site once. The default admin account is created on first access (`demo` / `karadavdemo`): change the password immediately.
4. Optionally set `SHARE_SITE_NAME` and `SHARE_LOGO_URL`.

Do not run `make deps`: `www/browser.min.js` and `lib/KD2/WebDAV/NextCloud.php` are modified in this fork and would be overwritten.

## Keeping up with upstream

When merging changes from the original repository, expect conflicts in `www/browser.min.js` (minified), `lib/KD2/WebDAV/NextCloud.php`, `lib/KaraDAV/DB.php` (migration version), `init.php`, `lib/KaraDAV/Storage.php`, `lib/KaraDAV/NextCloud.php` and `lib/KaraDAV/Server.php`. Renumber the migrations in `sql/` if upstream adds its own.

## License

AGPL-3.0, same as KaraDAV. See `LICENSE`.
