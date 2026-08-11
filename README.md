# Takumi Vault — Backup & Restore Manager

**Back up a WordPress database and files, and restore them in one click — no command line, no third-party account.**

Built to hand to a non-technical client: one admin menu, one button, backups stored outside the web root by default.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat&logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat&logo=wordpress&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat)
![Version](https://img.shields.io/badge/version-1.0.0-green?style=flat)

---

## Why this exists

Most backup plugins either push you toward a paid cloud tier or expose enough options to be dangerous in a client's hands. Takumi Vault does the boring, essential 90%: take a backup, keep a sensible number of them, restore one, and tell someone if it failed.

## Features

- **One-click manual backup** — database, files, or both.
- **One-click restore** from any saved backup.
- **Scheduled automatic backups** — daily, weekly or monthly, via WP-Cron.
- **Backup history** with optional per-backup notes, so "before the theme change" is findable later.
- **Download** any backup to your own machine.
- **Generation management** — old backups pruned automatically to a configured count.
- **Email notification** on completion or failure.
- **Stored outside the web root by default** (`_backup/` one level above the WordPress install), so archives aren't publicly fetchable.

## Architecture

```
takumi-vault.php                      Bootstrap, activation, admin menu
includes/
  class-takumi-vault-backup.php       Backup orchestration (DB + files, zip, pruning)
  class-takumi-vault-restore.php      Restore from an archive
  class-takumi-vault-db.php           mysqldump handling and DB import
  class-takumi-vault-scheduler.php    WP-Cron scheduling for automatic backups
  class-takumi-vault-admin.php        Settings API screens and actions
admin/views/                      Dashboard, backup list, settings templates
uninstall.php                     Teardown
```

Admin actions are capability- and nonce-checked; paths are validated before any filesystem write.

## Installation

1. Upload the `takumi-vault` folder to `/wp-content/plugins/`.
2. Activate via **Plugins › Installed Plugins**.
3. Open **Takumi Vault** in the admin menu.
4. Optionally adjust the backup directory and schedule under **Takumi Vault › Settings**.

## Requirements & FAQ

**Where are backups stored?** By default in a `_backup` directory one level above the WordPress install — outside the web root. Configurable in Settings.

**Does it need WP-CLI?** No. Takumi Vault calls `mysqldump` directly when available; if it isn't present on the server, the plugin reports that clearly rather than writing a partial dump.

**Is it safe on a live site?** Yes. Files are zipped after the copy, keeping the maintenance window short.

## Changelog

**1.0.0** — Initial release: manual and scheduled backups, one-click restore, history with notes, downloads, pruning, email notifications.

## License

GPLv2 or later. Developed and maintained by [Yoshiro Moriyama](https://github.com/moriyama-dev), founder of [Takumi Web Services](https://www.takumi.ca) — a WordPress development studio in Toronto, Canada.
