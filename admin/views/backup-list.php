<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpvault-wrap">
	<h1><?php esc_html_e( 'バックアップ一覧', 'wp-vault' ); ?></h1>

	<?php
	$per_page = 20;
	$page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$backups  = WP_Vault_DB::get_all_backups( $per_page, $page );
	$total    = WP_Vault_DB::get_total_count();
	$pages    = ceil( $total / $per_page );
	?>

	<?php if ( empty( $backups ) ) : ?>
		<p><?php esc_html_e( 'バックアップがありません。', 'wp-vault' ); ?></p>
	<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( '日時', 'wp-vault' ); ?></th>
				<th><?php esc_html_e( 'ファイル名', 'wp-vault' ); ?></th>
				<th><?php esc_html_e( '種類', 'wp-vault' ); ?></th>
				<th><?php esc_html_e( 'サイズ', 'wp-vault' ); ?></th>
				<th><?php esc_html_e( 'メモ', 'wp-vault' ); ?></th>
				<th><?php esc_html_e( '操作', 'wp-vault' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $backups as $backup ) : ?>
			<tr data-id="<?php echo esc_attr( $backup->id ); ?>">
				<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup->created_at ) ) ); ?></td>
				<td><code><?php echo esc_html( $backup->filename ); ?></code></td>
				<td><?php echo esc_html( strtoupper( $backup->type ) ); ?></td>
				<td><?php echo esc_html( size_format( (int) $backup->size ) ); ?></td>
				<td><?php echo esc_html( $backup->note ); ?></td>
				<td class="wpvault-actions">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpvault_download_backup&backup_id=' . $backup->id ), 'wpvault_download_' . $backup->id ) ); ?>" class="button button-small">
						<?php esc_html_e( 'ダウンロード', 'wp-vault' ); ?>
					</a>
					<button class="button button-small wpvault-restore-btn" data-id="<?php echo esc_attr( $backup->id ); ?>">
						<?php esc_html_e( 'リストア', 'wp-vault' ); ?>
					</button>
					<button class="button button-small button-link-delete wpvault-delete-btn" data-id="<?php echo esc_attr( $backup->id ); ?>">
						<?php esc_html_e( '削除', 'wp-vault' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $pages,
					'current'   => $page,
				)
			);
			?>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<div id="wpvault-list-result" class="wpvault-notice" style="display:none;"></div>
</div>
