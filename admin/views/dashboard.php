<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap tkvault-wrap">
	<h1><?php esc_html_e( 'Takumi Vault', 'takumi-vault' ); ?></h1>

	<div class="tkvault-dashboard-grid">

		<div class="tkvault-card">
			<h2><?php esc_html_e( 'Last backup', 'takumi-vault' ); ?></h2>
			<?php
			$tkvault_backups = TKVault_DB::get_all_backups( 1, 1 );
			if ( ! empty( $tkvault_backups ) ) {
				$tkvault_last = $tkvault_backups[0];
				echo '<p>' . esc_html(
					wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						strtotime( $tkvault_last->created_at )
					)
				) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'No backups yet.', 'takumi-vault' ) . '</p>';
			}
			?>
		</div>

		<div class="tkvault-card">
			<h2><?php esc_html_e( 'Next scheduled run', 'takumi-vault' ); ?></h2>
			<?php
			$tkvault_next = wp_next_scheduled( 'tkvault_scheduled_backup' );
			if ( $tkvault_next ) {
				echo '<p>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tkvault_next ) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Not scheduled', 'takumi-vault' ) . '</p>';
			}
			?>
		</div>

		<div class="tkvault-card">
			<h2><?php esc_html_e( 'Backup destination', 'takumi-vault' ); ?></h2>
			<p><code><?php echo esc_html( tkvault_get_backup_dir() ); ?></code></p>
			<?php
			// Report what the backups themselves occupy. Server-wide disk usage
			// says nothing useful on shared hosting, where the figure covers
			// every account on the machine.
			$tkvault_used = (int) TKVault_DB::get_total_size();
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable file size, e.g. "24 MB" */
						__( 'Backups on disk: %s', 'takumi-vault' ),
						size_format( $tkvault_used )
					)
				)
			);

			$tkvault_dir  = tkvault_get_backup_dir();
			$tkvault_free = ( is_dir( $tkvault_dir ) && function_exists( 'disk_free_space' ) ) ? @disk_free_space( $tkvault_dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $tkvault_free ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: human-readable free space, e.g. "3.4 GB" */
							__( 'Free space: %s', 'takumi-vault' ),
							size_format( $tkvault_free )
						)
					)
				);
			} else {
				echo '<p>' . esc_html__( 'Free space: unavailable on this host.', 'takumi-vault' ) . '</p>';
			}
			?>
		</div>

	</div>

	<div class="tkvault-card tkvault-backup-actions tkvault-job"
		data-job="0"
		data-start-action="tkvault_start_backup"
		data-note-field="#tkvault-backup-note"
		data-type-field="#tkvault-backup-type">
		<h2><?php esc_html_e( 'Back up now', 'takumi-vault' ); ?></h2>
		<div class="tkvault-action-row">
			<label for="tkvault-backup-type"><?php esc_html_e( 'What to back up', 'takumi-vault' ); ?></label>
			<select id="tkvault-backup-type">
				<option value="db"><?php esc_html_e( 'Database', 'takumi-vault' ); ?></option>
				<option value="files"><?php esc_html_e( 'Files (wp-content)', 'takumi-vault' ); ?></option>
			</select>
			<label for="tkvault-backup-note"><?php esc_html_e( 'Note (optional)', 'takumi-vault' ); ?></label>
			<input type="text" id="tkvault-backup-note" placeholder="<?php esc_attr_e( 'e.g. before the theme change', 'takumi-vault' ); ?>">
			<button class="button button-primary tkvault-job-start">
				<?php esc_html_e( 'Start backup', 'takumi-vault' ); ?>
			</button>
			<button class="button tkvault-job-cancel" style="display:none;">
				<?php esc_html_e( 'Cancel', 'takumi-vault' ); ?>
			</button>
		</div>
		<div class="tkvault-progress" style="display:none;">
			<div class="tkvault-progress-bar"><span style="width:0%"></span></div>
			<p class="tkvault-progress-text"></p>
		</div>
	</div>
</div>
