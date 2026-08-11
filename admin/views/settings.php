<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap tkvault-wrap">
	<h1><?php esc_html_e( 'Settings', 'takumi-vault' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'takumi-vault' ); ?></p></div>
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
						value="<?php echo esc_attr( tkvault_get_backup_dir() ); ?>"
						class="large-text">
					<p class="description">
						<?php esc_html_e( 'Choose a directory your web server cannot serve. Takumi Vault checks the destination before writing to it.', 'takumi-vault' ); ?>
					</p>
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
