<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap tkvault-wrap">
	<h1><?php esc_html_e( 'Backups', 'takumi-vault' ); ?></h1>

	<?php
	$tkvault_per_page = 20;
	$tkvault_page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tkvault_backups  = TKVault_DB::get_all_backups( $tkvault_per_page, $tkvault_page );
	$tkvault_total    = TKVault_DB::get_total_count();
	$tkvault_pages    = (int) ceil( $tkvault_total / $tkvault_per_page );
	?>

	<?php if ( empty( $tkvault_backups ) ) : ?>
		<p><?php esc_html_e( 'There are no backups yet.', 'takumi-vault' ); ?></p>
	<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'File', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'Type', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'Size', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'Note', 'takumi-vault' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'takumi-vault' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $tkvault_backups as $tkvault_backup ) : ?>
			<tr data-id="<?php echo esc_attr( $tkvault_backup->id ); ?>">
				<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $tkvault_backup->created_at ) ) ); ?></td>
				<td><code><?php echo esc_html( $tkvault_backup->filename ); ?></code></td>
				<td><?php echo esc_html( strtoupper( $tkvault_backup->type ) ); ?></td>
				<td><?php echo esc_html( size_format( (int) $tkvault_backup->size ) ); ?></td>
				<td><?php echo esc_html( $tkvault_backup->note ); ?></td>
				<td class="tkvault-actions">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tkvault_download_backup&backup_id=' . $tkvault_backup->id ), 'tkvault_download_' . $tkvault_backup->id ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Download', 'takumi-vault' ); ?>
					</a>
					<button class="button button-small tkvault-restore-btn" data-id="<?php echo esc_attr( $tkvault_backup->id ); ?>">
						<?php esc_html_e( 'Restore', 'takumi-vault' ); ?>
					</button>
					<button class="button button-small button-link-delete tkvault-delete-btn" data-id="<?php echo esc_attr( $tkvault_backup->id ); ?>">
						<?php esc_html_e( 'Delete', 'takumi-vault' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $tkvault_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $tkvault_pages,
						'current'   => $tkvault_page,
					)
				)
			);
			?>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<div id="tkvault-list-result" class="tkvault-notice" style="display:none;"></div>
</div>
