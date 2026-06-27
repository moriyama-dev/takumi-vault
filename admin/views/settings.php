<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpvault-wrap">
	<h1><?php esc_html_e( '設定', 'wp-vault' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '設定を保存しました。', 'wp-vault' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wpvault_settings' ); ?>
		<input type="hidden" name="action" value="wpvault_save_settings">

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="wpvault_backup_dir"><?php esc_html_e( 'バックアップ保存先', 'wp-vault' ); ?></label>
				</th>
				<td>
					<input type="text" id="wpvault_backup_dir" name="wpvault_backup_dir"
						value="<?php echo esc_attr( wpvault_get_backup_dir() ); ?>"
						class="large-text">
					<p class="description">
						<?php esc_html_e( 'デフォルトはWebルート外（dirname(ABSPATH)/_backup）。Webサーバーから直接アクセスできない場所を推奨します。', 'wp-vault' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpvault_schedule"><?php esc_html_e( '自動バックアップ', 'wp-vault' ); ?></label>
				</th>
				<td>
					<select id="wpvault_schedule" name="wpvault_schedule">
						<?php
						$current = get_option( 'wpvault_schedule', 'none' );
						$options = array(
							'none'             => __( '無効', 'wp-vault' ),
							'daily'            => __( '毎日', 'wp-vault' ),
							'wpvault_weekly'   => __( '毎週', 'wp-vault' ),
							'wpvault_monthly'  => __( '毎月', 'wp-vault' ),
						);
						foreach ( $options as $val => $label ) {
							printf(
								'<option value="%s"%s>%s</option>',
								esc_attr( $val ),
								selected( $current, $val, false ),
								esc_html( $label )
							);
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpvault_keep_generations"><?php esc_html_e( '世代管理（保持する件数）', 'wp-vault' ); ?></label>
				</th>
				<td>
					<input type="number" id="wpvault_keep_generations" name="wpvault_keep_generations"
						value="<?php echo esc_attr( get_option( 'wpvault_keep_generations', 10 ) ); ?>"
						min="1" max="100" class="small-text">
					<p class="description"><?php esc_html_e( 'この件数を超えた古いバックアップは自動削除されます。', 'wp-vault' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpvault_notify_email"><?php esc_html_e( '通知メールアドレス', 'wp-vault' ); ?></label>
				</th>
				<td>
					<input type="email" id="wpvault_notify_email" name="wpvault_notify_email"
						value="<?php echo esc_attr( get_option( 'wpvault_notify_email', get_option( 'admin_email' ) ) ); ?>"
						class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '完了通知', 'wp-vault' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wpvault_notify_on_success" value="1"
							<?php checked( get_option( 'wpvault_notify_on_success', 1 ), 1 ); ?>>
						<?php esc_html_e( 'バックアップ完了時にメールを送信する', 'wp-vault' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( '設定を保存', 'wp-vault' ) ); ?>
	</form>
</div>
