=== WP Vault - Backup & Restore Manager ===
Contributors: yoshiromoriyama
Tags: backup, restore, database, files, schedule
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, client-friendly backup & restore manager for WordPress.

== Description ==

WP Vault lets you back up your WordPress database and files, and restore them with a single click — no command line required.

**Key Features:**

* One-click manual backup (database, files, or both)
* Restore from any saved backup
* Scheduled automatic backups (daily, weekly, monthly)
* Backup history with optional notes
* Download backups to your computer
* Automatic old-backup pruning (generation management)
* Email notifications on completion or failure
* Backups stored outside the web root by default (secure)

Developed and maintained by Yoshiro Moriyama, founder of Takumi Web Services
— a WordPress development studio based in Toronto, Canada.

== Installation ==

1. Upload the `wp-vault` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Navigate to **WP Vault** in the admin menu.
4. (Optional) Adjust the backup directory and schedule under **WP Vault > Settings**.

== Frequently Asked Questions ==

= Where are backups stored? =

By default, backups are saved to a `_backup` directory one level above your WordPress installation (outside the web root). You can change this in **WP Vault > Settings**.

= Does this plugin require WP-CLI? =

No. WP Vault uses `mysqldump` directly if available. If `mysqldump` is not found on your server, an error message will be displayed.

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
