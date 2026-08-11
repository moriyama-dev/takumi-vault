=== Takumi Vault - Backup & Restore Manager ===
Contributors: yoshiromoriyama
Tags: backup, restore, database, files, schedule
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, client-friendly backup & restore manager for WordPress.

== Description ==

Takumi Vault lets you back up your WordPress database and files, and restore them with a single click — no command line required.

**Key Features:**

* One-click manual backup (database, files, or both)
* Restore from any saved backup
* Scheduled automatic backups (daily, weekly, monthly)
* Backup history with optional notes
* Download backups to your computer
* Automatic old-backup pruning (generation management)
* Email notifications on completion or failure
* Backups stored outside the web root by default, with the destination verified before use
* No external commands: works on hosts where exec() and shell_exec() are disabled

Developed and maintained by Yoshiro Moriyama, founder of Takumi Web Services
— a WordPress development studio based in Toronto, Canada.

== Installation ==

1. Upload the `takumi-vault` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Navigate to **Takumi Vault** in the admin menu.
4. (Optional) Adjust the backup directory and schedule under **Takumi Vault > Settings**.

== Frequently Asked Questions ==

= Where are backups stored? =

By default, backups go to a directory with a random suffix one level above your WordPress installation. Before writing anything, Takumi Vault checks whether that directory is actually reachable over the web and refuses to use it if it is. You can set your own path in **Takumi Vault > Settings**, and it is checked the same way.

= Does this plugin require WP-CLI, mysqldump or SSH? =

No. Takumi Vault is written entirely in PHP and the WordPress API. It does not call `exec()`, `shell_exec()` or any external command, so it behaves the same on shared hosting where those functions are disabled.

= Is it safe to use on a live site? =

Yes. File backups are zipped after the fact, and the maintenance window is kept to a minimum.

== Screenshots ==

1. Dashboard with last backup info and one-click backup button.
2. Backup list with download and restore options.
3. Settings page.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
