=== Takumi Vault - Backup & Restore Manager ===
Contributors: yoshiromoriyama
Tags: backup, restore, database, files, wp-cli
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.2.0
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
* WP-CLI commands, so a backup can run from a real cron entry or a deployment script

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

= Can I run backups from the command line? =

Yes. `wp takumi-vault backup --type=all` takes a backup, and `wp takumi-vault restore <id>` puts one back. There is also `wp takumi-vault check`, which reports what the host can and cannot do and exits non-zero if anything would stop a backup, so it can gate a deployment script.

This is worth using where you can. The command line has no browser waiting on it, no execution time limit and no dependency on WP-Cron, which only fires when somebody visits the site. A quiet site with a real cron entry calling `wp takumi-vault backup` is the most reliable way to run this plugin.

= What happens when I uninstall it? =

Its settings, its own tables and any scheduled events are removed, along with the copy of the database that a restore kept so it could be undone.

**Your backups are not deleted.** They live outside the plugin's own directory and are yours; removing a plugin should not destroy the thing you installed it to protect. Delete them yourself when you no longer want them.

= Is it safe to use on a live site? =

Yes. File backups are zipped after the fact, and the maintenance window is kept to a minimum.

== Screenshots ==

1. Dashboard with last backup info and one-click backup button.
2. Backup list with download and restore options.
3. Settings page.

== Changelog ==

= 1.2.0 =
* Automatic backups now run at a time of day you choose, instead of at whatever time the setting was saved.
* The dashboard links straight to the schedule, so it is no longer a screen that reports "Not scheduled" and offers no way to change it.

= 1.1.0 =
* Added WP-CLI commands: backup, restore, undo, list and check.
* Backups run from the command line complete in one process, with no loopback request and no execution time limit.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Scheduled backups can be given a start time, so they can be moved off the middle of the working day.

= 1.1.0 =
Adds WP-CLI support, so backups can run from cron or a deployment script.

= 1.0.0 =
Initial release.
