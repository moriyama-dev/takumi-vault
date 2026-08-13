<?php
defined( 'ABSPATH' ) || exit;

$tkvault_error     = get_option( 'tkvault_settings_error' );
$tkvault_is_public = is_array( $tkvault_error ) && 'tkvault_dir_public' === $tkvault_error['code'];
$tkvault_value     = $tkvault_error && ! empty( $tkvault_error['attempted'] ) ? $tkvault_error['attempted'] : tkvault_get_backup_dir();
?>
<div class="wrap tkvault-wrap">
	<h1><?php esc_html_e( 'Settings', 'takumi-vault' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'takumi-vault' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $tkvault_error && ! $tkvault_is_public ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $tkvault_error['message'] ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'tkvault_settings' ); ?>
		<input type="hidden" name="action" value="tkvault_save_settings">

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="tkvault_backup_dir"><?php esc_html_e( 'Backup destination', 'takumi-vault' ); ?></label>
				</th>
				<td>
					<input type="text" id="tkvault_backup_dir" name="tkvault_backup_dir"
						value="<?php echo esc_attr( $tkvault_value ); ?>"
						class="large-text">
					<p class="description">
						<?php esc_html_e( 'Choose a directory your web server cannot serve. Takumi Vault writes a test file there and tries to download it before storing anything.', 'takumi-vault' ); ?>
					</p>

					<?php if ( $tkvault_is_public ) : ?>
						<div class="notice notice-error inline tkvault-public-warning" style="margin:12px 0;padding:10px 12px;">
							<p><strong><?php esc_html_e( 'This directory is downloadable over the web.', 'takumi-vault' ); ?></strong></p>
							<p><?php echo esc_html( $tkvault_error['message'] ); ?></p>
							<p><?php esc_html_e( 'Nothing has been saved. Choose one:', 'takumi-vault' ); ?></p>
							<p>
								<label>
									<input type="radio" name="tkvault_public_choice" value="change" checked>
									<?php esc_html_e( 'Enter a different path above (recommended)', 'takumi-vault' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="radio" name="tkvault_public_choice" value="accept" id="tkvault-choice-accept">
									<?php esc_html_e( 'Use this directory anyway', 'takumi-vault' ); ?>
								</label>
							</p>
							<p style="margin-left:24px;">
								<label>
									<input type="checkbox" name="tkvault_accept_public" value="1" id="tkvault-accept-public">
									<?php esc_html_e( 'I understand that backup files in this location may be publicly downloadable, including a full copy of my database.', 'takumi-vault' ); ?>
								</label>
							</p>
						</div>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="tkvault_schedule"><?php esc_html_e( 'Automatic backups', 'takumi-vault' ); ?></label>
				</th>
				<td>
					<select id="tkvault_schedule" name="tkvault_schedule">
						<?php
						$tkvault_current = get_option( 'tkvault_schedule', 'none' );
						$tkvault_options = array(
							'none'            => __( 'Off', 'takumi-vault' ),
							'daily'           => __( 'Daily', 'takumi-vault' ),
							'tkvault_weekly'  => __( 'Weekly', 'takumi-vault' ),
							'tkvault_monthly' => __( 'Monthly', 'takumi-vault' ),
						);
						foreach ( $tkvault_options as $tkvault_val => $tkvault_label ) {
							printf(
								'<option value="%s"%s>%s</option>',
								esc_attr( $tkvault_val ),
								selected( $tkvault_current, $tkvault_val, false ),
								esc_html( $tkvault_label )
							);
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="tkvault_keep_generations"><?php esc_html_e( 'Backups to keep', 'takumi-vault' ); ?></label>
				</th>
				<td>
					<input type="number" id="tkvault_keep_generations" name="tkvault_keep_generations"
						value="<?php echo esc_attr( get_option( 'tkvault_keep_generations', 10 ) ); ?>"
						min="1" max="100" class="small-text">
					<p class="description"><?php esc_html_e( 'Older backups beyond this count are deleted automatically.', 'takumi-vault' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="tkvault_old_table_retention_days"><?php esc_html_e( 'Keep the previous database for', 'takumi-vault' ); ?></label>
				</th>
				<td>
					<input type="number" id="tkvault_old_table_retention_days" name="tkvault_old_table_retention_days"
						value="<?php echo esc_attr( TKVault_DB_Restore::retention_days() ); ?>"
						min="-1" max="365" class="small-text">
					<?php esc_html_e( 'days', 'takumi-vault' ); ?>
					<p class="description">
						<?php esc_html_e( 'After a restore, the database it replaced is kept so the restore can be undone. It is a second full copy, so it roughly doubles the size of the database until it is removed. Use 0 to remove it immediately, or -1 to keep it indefinitely.', 'takumi-vault' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="tkvault_notify_email"><?php esc_html_e( 'Notification email', 'takumi-vault' ); ?></label>
				</th>
				<td>
					<input type="email" id="tkvault_notify_email" name="tkvault_notify_email"
						value="<?php echo esc_attr( get_option( 'tkvault_notify_email', get_option( 'admin_email' ) ) ); ?>"
						class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Success notices', 'takumi-vault' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="tkvault_notify_on_success" value="1"
							<?php checked( get_option( 'tkvault_notify_on_success', 1 ), 1 ); ?>>
						<?php esc_html_e( 'Email me when a backup finishes', 'takumi-vault' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'takumi-vault' ) ); ?>
	</form>
</div>
