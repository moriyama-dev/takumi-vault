<?php
/**
 * Environment diagnostics screen.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

$tkvault_probe  = isset( $_GET['recheck'] ) && check_admin_referer( 'tkvault_recheck' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tkvault_checks = TKVault_Preflight::run( $tkvault_probe );
$tkvault_stops  = TKVault_Preflight::blockers( $tkvault_checks );

$tkvault_labels = array(
	TKVault_Preflight::OK   => __( 'OK', 'takumi-vault' ),
	TKVault_Preflight::WARN => __( 'Falls back', 'takumi-vault' ),
	TKVault_Preflight::STOP => __( 'Blocks backups', 'takumi-vault' ),
);
?>
<div class="wrap tkvault-wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'takumi-vault' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'A missing feature is not usually a problem: Takumi Vault switches to another way of doing the same job. Only the rows marked as blocking will stop a backup from starting.', 'takumi-vault' ); ?>
	</p>

	<?php if ( $tkvault_stops ) : ?>
		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of blocking problems */
					esc_html( _n( '%d problem must be resolved before backups can run.', '%d problems must be resolved before backups can run.', count( $tkvault_stops ), 'takumi-vault' ) ),
					count( $tkvault_stops )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Nothing is blocking backups.', 'takumi-vault' ); ?></p>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th style="width:22%"><?php esc_html_e( 'Check', 'takumi-vault' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Result', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'takumi-vault' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $tkvault_checks as $tkvault_check ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $tkvault_check['label'] ); ?></strong></td>
				<td>
					<span class="tkvault-status tkvault-status-<?php echo esc_attr( $tkvault_check['status'] ); ?>">
						<?php echo esc_html( $tkvault_labels[ $tkvault_check['status'] ] ); ?>
					</span>
				</td>
				<td><?php echo esc_html( $tkvault_check['detail'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Public exposure check', 'takumi-vault' ); ?></h2>
	<p>
		<?php esc_html_e( 'Takumi Vault writes a file with a random name into the backup directory and then tries to download it over HTTP. If the download succeeds, the directory is definitely reachable from the web and backups will not be written there.', 'takumi-vault' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'The result is not symmetrical.', 'takumi-vault' ); ?></strong>
		<?php esc_html_e( 'A successful download proves the directory is exposed. A failed one only means this particular attempt did not reach it: behind a reverse proxy, a CDN or an aliased document root the guessed URL can be wrong while the directory is still served. That is why the directory name is randomised and the deny rules are written even when the check comes back clean.', 'takumi-vault' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'The check is repeated weekly, before every backup, after a plugin update, and whenever the site address changes. A site that was safe at install time can stop being safe later.', 'takumi-vault' ); ?>
	</p>
	<p>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'takumi-vault-preflight', 'recheck' => '1' ), admin_url( 'admin.php' ) ), 'tkvault_recheck' ) ); ?>">
			<?php esc_html_e( 'Run the check now', 'takumi-vault' ); ?>
		</a>
	</p>

	<h2><?php esc_html_e( 'Background processing self-test', 'takumi-vault' ); ?></h2>
	<p>
		<?php esc_html_e( 'Backups are too big for one request, so they are split into chunks that hand over to each other. This runs a job that does nothing but count, to confirm the mechanism completes on this host.', 'takumi-vault' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'If loopback requests are blocked, the job advances while this page is open instead. Either way it should reach 100%.', 'takumi-vault' ); ?>
	</p>

	<div class="tkvault-job" data-job="0">
		<p>
			<button class="button button-primary" id="tkvault-start-selftest"><?php esc_html_e( 'Run self-test', 'takumi-vault' ); ?></button>
			<button class="button" id="tkvault-cancel-job" style="display:none;"><?php esc_html_e( 'Cancel', 'takumi-vault' ); ?></button>
		</p>
		<div class="tkvault-progress" style="display:none;">
			<div class="tkvault-progress-bar"><span style="width:0%"></span></div>
			<p class="tkvault-progress-text"></p>
		</div>
	</div>
</div>
