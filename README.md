# Takumi Vault — Backup & Restore Manager

**Back up a WordPress database and files, and restore them in one click — no command line, no third-party account.**

Built to hand to a non-technical client: one admin menu, one button, backups stored outside the web root by default.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat&logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat&logo=wordpress&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat)
![Version](https://img.shields.io/badge/version-1.1.0-green?style=flat)

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
- **Stored outside the web root by default**, so archives aren't publicly fetchable — and the destination is verified by fetching a canary file over HTTP before anything is written to it.
- **Incremental file backups**, so the second backup only archives what changed.
- **Undo** — the database a restore replaced is kept, and the files it overwrote are kept, so a restore can be put back.
- **WP-CLI commands**, so a backup can run from a real cron entry or a deployment script.
- **Runs where other backup plugins can't** — no `exec()`, no `mysqldump`, no shelling out of any kind. Long jobs are split across requests and resume where they stopped.

## Architecture

```
takumi-vault.php                   Bootstrap, activation, admin menu
includes/
  class-tkvault-storage.php        Destination resolution, exposure probe, permissions
  class-tkvault-preflight.php      What this host can and cannot do
  class-tkvault-jobs.php           Job records, atomic claim, heartbeat
  class-tkvault-runner.php         Chunked execution and continuation
  class-tkvault-db.php             Backup records
  class-tkvault-db-dump.php        Chunked database dump
  class-tkvault-sql-reader.php     Statement reader for the dump format
  class-tkvault-db-restore.php     Chunked restore, safety copy, atomic swap
  class-tkvault-file-backup.php    Chunked, resumable, incremental file backup
  class-tkvault-file-restore.php   File restore and undo
  class-tkvault-scheduler.php      Scheduled backups and pruning
  class-tkvault-admin.php          Admin screens and actions
  class-tkvault-cli.php            WP-CLI commands
admin/views/                       Dashboard, backup list, settings, diagnostics
uninstall.php                      Teardown
```

Long-running work is split into chunks by `TKVault_Runner`, which spends a
fraction of whatever execution time the host allows and hands the job to the
next request. Nothing assumes a single request can finish anything.

Admin actions are capability- and nonce-checked; paths are validated before any filesystem write.

## Installation

1. Upload the `takumi-vault` folder to `/wp-content/plugins/`.
2. Activate via **Plugins › Installed Plugins**.
3. Open **Takumi Vault** in the admin menu.
4. Optionally adjust the backup directory and schedule under **Takumi Vault › Settings**.

## Requirements & FAQ

**Where are backups stored?** By default in a `_backup` directory one level above the WordPress install — outside the web root. Configurable in Settings.

**Does it need `mysqldump`, SSH or WP-CLI?** No. Everything is PHP and the
WordPress API. `exec()`, `shell_exec()` and friends are never called, so the
plugin behaves the same on shared hosting where they are switched off.

**Can I drive it from the command line?** Yes, and it is the most reliable way
to run it:

```
wp takumi-vault check && wp takumi-vault backup --type=all
```

`check` exits non-zero if anything would stop a backup, so it can gate a
deployment. The command line has no execution time limit, needs no loopback
request and does not depend on WP-Cron, which only fires when somebody visits
the site.

**Is it safe on a live site?** Yes. Work is chunked, so no request is ever
asked to do more than it can finish.

## Changelog

**1.1.0** — WP-CLI commands: `backup`, `restore`, `undo`, `list`, `check`.

**1.0.0** — Initial release: manual and scheduled backups, one-click restore, history with notes, downloads, pruning, email notifications.

## License

GPLv2 or later. Developed and maintained by [Yoshiro Moriyama](https://github.com/moriyama-dev), founder of [Takumi Web Services](https://www.takumi.ca) — a WordPress development studio in Toronto, Canada.
