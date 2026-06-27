<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpvault-wrap">
	<h1><?php esc_html_e( 'WP Vault', 'wp-vault' ); ?></h1>

	<div class="wpvault-dashboard-grid">

		<div class="wpvault-card">
			<h2><?php esc_html_e( '最終バックアップ', 'wp-vault' ); ?></h2>
			<?php
			$backups = WP_Vault_DB::get_all_backups( 1, 1 );
			if ( ! empty( $backups ) ) {
				$last = $backups[0];
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s: date */
						__( '%s に実行', 'wp-vault' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last->created_at ) )
					)
				) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'まだバックアップがありません。', 'wp-vault' ) . '</p>';
			}
			?>
		</div>

		<div class="wpvault-card">
			<h2><?php esc_html_e( '次回スケジュール', 'wp-vault' ); ?></h2>
			<?php
			$next = wp_next_scheduled( 'wpvault_scheduled_backup' );
			if ( $next ) {
				echo '<p>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'スケジュールなし', 'wp-vault' ) . '</p>';
			}
			?>
		</div>

		<div class="wpvault-card">
			<h2><?php esc_html_e( 'バックアップ保存先', 'wp-vault' ); ?></h2>
			<p><code><?php echo esc_html( wpvault_get_backup_dir() ); ?></code></p>
			<?php
			$dir = wpvault_get_backup_dir();
			if ( is_dir( $dir ) ) {
				$total  = disk_total_space( $dir );
				$free   = disk_free_space( $dir );
				$used   = $total - $free;
				$pct    = $total > 0 ? round( ( $used / $total ) * 100 ) : 0;
				printf(
					'<p>' . esc_html__( 'ディスク使用量: %s / %s (%d%%)', 'wp-vault' ) . '</p>',
					esc_html( size_format( $used ) ),
					esc_html( size_format( $total ) ),
					esc_html( $pct )
				);
			}
			?>
		</div>

	</div>

	<div class="wpvault-card wpvault-backup-actions">
		<h2><?php esc_html_e( '今すぐバックアップ', 'wp-vault' ); ?></h2>
		<div class="wpvault-action-row">
			<label for="wpvault-backup-type"><?php esc_html_e( '種類', 'wp-vault' ); ?></label>
			<select id="wpvault-backup-type">
				<option value="full"><?php esc_html_e( 'DB + ファイル（フル）', 'wp-vault' ); ?></option>
				<option value="db"><?php esc_html_e( 'DBのみ', 'wp-vault' ); ?></option>
				<option value="files"><?php esc_html_e( 'ファイルのみ', 'wp-vault' ); ?></option>
			</select>
			<label for="wpvault-backup-note"><?php esc_html_e( 'メモ（任意）', 'wp-vault' ); ?></label>
			<input type="text" id="wpvault-backup-note" placeholder="<?php esc_attr_e( '例: デザイン変更前', 'wp-vault' ); ?>">
			<button id="wpvault-run-backup" class="button button-primary">
				<?php esc_html_e( 'バックアップ開始', 'wp-vault' ); ?>
			</button>
		</div>
		<div id="wpvault-backup-result" class="wpvault-notice" style="display:none;"></div>
	</div>
</div>
