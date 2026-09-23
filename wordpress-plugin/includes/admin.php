<?php
/**
 * wp-admin: feedbacklijst, detail-metabox, instellingen en Asana-acties.
 */

defined( 'ABSPATH' ) || exit;

function sfb_is_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return ( $screen && SFB_POST_TYPE === $screen->post_type ) || ( isset( $_GET['page'] ) && 'sfb-settings' === $_GET['page'] );
}

function sfb_settings_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SFB_POST_TYPE . '&page=sfb-settings' ) );
}

function sfb_view_on_page_url( $f ) {
	return $f['url'] ? strtok( $f['url'], '#' ) . '#sfb-' . $f['id'] : '';
}

/* -------------------------------------------------------------------------
 * Lijstweergave
 * ---------------------------------------------------------------------- */

add_filter(
	'manage_' . SFB_POST_TYPE . '_posts_columns',
	function ( $cols ) {
		return array(
			'cb'         => $cols['cb'],
			'sfb_shot'   => 'Screenshot',
			'title'      => 'Feedback',
			'sfb_page'   => 'Pagina',
			'sfb_user'   => 'Gemeld door',
			'sfb_status' => 'Status',
			'sfb_asana'  => 'Asana',
			'date'       => 'Datum',
		);
	}
);

add_action(
	'manage_' . SFB_POST_TYPE . '_posts_custom_column',
	function ( $col, $post_id ) {
		$f = sfb_format_feedback( $post_id );
		switch ( $col ) {
			case 'sfb_shot':
				if ( $f['screenshot'] ) {
					printf( '<a href="%1$s" target="_blank"><img src="%1$s" class="sfb-thumb" alt=""></a>', esc_url( $f['screenshot'] ) );
				}
				break;
			case 'sfb_page':
				$path = wp_parse_url( $f['url'], PHP_URL_PATH );
				printf(
					'<a href="%s" target="_blank">%s</a><br><span class="sfb-muted">%s</span>',
					esc_url( sfb_view_on_page_url( $f ) ),
					esc_html( $f['page_title'] ? $f['page_title'] : ( $path ? $path : $f['url'] ) ),
					esc_html( $path ? $path : '/' )
				);
				break;
			case 'sfb_user':
				printf( '<img src="%s" class="sfb-avatar" alt=""> %s', esc_url( $f['user']['avatar'] ), esc_html( $f['user']['name'] ) );
				break;
			case 'sfb_status':
				echo 'resolved' === $f['status'] ? '<span class="sfb-badge sfb-resolved">Opgelost</span>' : '<span class="sfb-badge sfb-open">Open</span>';
				break;
			case 'sfb_asana':
				if ( $f['asana']['url'] ) {
					printf( '<a href="%s" target="_blank">Open taak ↗</a>', esc_url( $f['asana']['url'] ) );
					$bits = array_filter( array( $f['asana']['assignee'] ? '👤 ' . $f['asana']['assignee'] : '', $f['asana']['section'], $f['asana']['comments'] ? '💬 ' . count( $f['asana']['comments'] ) : '' ) );
					if ( $bits ) {
						echo '<br><span class="sfb-muted">' . esc_html( implode( ' · ', $bits ) ) . '</span>';
					}
				} elseif ( SFB_Asana::is_configured() ) {
					if ( $f['asana']['error'] ) {
						printf( '<span class="sfb-asana-error" title="%s">⚠ Mislukt</span><br>', esc_attr( $f['asana']['error'] ) );
					}
					printf( '<a class="button button-small" href="%s">%s</a>', esc_url( sfb_asana_action_url( $post_id ) ), $f['asana']['error'] ? 'Opnieuw proberen' : '+ Asana-taak' );
				} else {
					echo '<span class="sfb-muted">—</span>';
				}
				break;
		}
	},
	10,
	2
);

add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( SFB_POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		$f = sfb_format_feedback( $post );
		if ( $f['url'] ) {
			$actions['sfb_view'] = sprintf( '<a href="%s" target="_blank">Bekijk op pagina</a>', esc_url( sfb_view_on_page_url( $f ) ) );
		}
		return $actions;
	},
	10,
	2
);

// Bij het openen van de feedbacklijst eerst de wijzigingen uit Asana ophalen.
add_action(
	'load-edit.php',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_type'] ) && SFB_POST_TYPE === $_GET['post_type'] ) {
			SFB_Asana::sync_changed( 15 );
		}
	}
);

// Filter op status.
add_action(
	'restrict_manage_posts',
	function ( $post_type ) {
		if ( SFB_POST_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['sfb_status'] ) ? sanitize_key( $_GET['sfb_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="sfb_status"><option value="">Alle statussen</option>';
		foreach ( array( 'open' => 'Open', 'resolved' => 'Opgelost' ) as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || SFB_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$status = isset( $_GET['sfb_status'] ) ? sanitize_key( $_GET['sfb_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $status, array( 'open', 'resolved' ), true ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_sfb_status',
						'value' => $status,
					),
				)
			);
		}
	}
);

// Bulkacties.
add_filter(
	'bulk_actions-edit-' . SFB_POST_TYPE,
	function ( $actions ) {
		unset( $actions['edit'] );
		$actions['sfb_resolve'] = 'Markeer als opgelost';
		$actions['sfb_reopen']  = 'Markeer als open';
		if ( SFB_Asana::is_configured() ) {
			$actions['sfb_asana'] = 'Maak Asana-taken';
		}
		return $actions;
	}
);

add_filter(
	'handle_bulk_actions-edit-' . SFB_POST_TYPE,
	function ( $redirect, $action, $ids ) {
		$errors = 0;
		foreach ( $ids as $id ) {
			if ( 'sfb_resolve' === $action || 'sfb_reopen' === $action ) {
				$errors += is_wp_error( sfb_set_status( $id, 'sfb_resolve' === $action ? 'resolved' : 'open' ) ) ? 1 : 0;
			} elseif ( 'sfb_asana' === $action ) {
				$errors += is_wp_error( SFB_Asana::create_task( $id ) ) ? 1 : 0;
			}
		}
		return add_query_arg( 'sfb_msg', $errors ? 'asana_err' : 'bulk_ok', $redirect );
	},
	10,
	3
);

/* -------------------------------------------------------------------------
 * Detailpagina (metabox)
 * ---------------------------------------------------------------------- */

add_action(
	'add_meta_boxes_' . SFB_POST_TYPE,
	function ( $post ) {
		// Bij openen de laatste stand uit Asana ophalen.
		if ( SFB_Asana::is_configured() ) {
			SFB_Asana::maybe_sync( $post->ID, 60 );
		}
		add_meta_box( 'sfb_details', 'Details', 'sfb_render_details_box', SFB_POST_TYPE, 'normal', 'high' );
		add_meta_box( 'sfb_actions', 'Status & Asana', 'sfb_render_actions_box', SFB_POST_TYPE, 'side', 'high' );
		if ( get_post_meta( $post->ID, '_sfb_asana_gid', true ) ) {
			add_meta_box( 'sfb_asana_comments', 'Reacties in Asana', 'sfb_render_comments_box', SFB_POST_TYPE, 'normal', 'default' );
		}
	}
);

function sfb_render_comments_box( $post ) {
	$f = sfb_format_feedback( $post );
	if ( ! $f['asana']['comments'] ) {
		echo '<p class="sfb-muted">Nog geen reacties op deze taak in Asana.</p>';
	}
	foreach ( $f['asana']['comments'] as $c ) {
		printf(
			'<div class="sfb-comment"><strong>%s</strong> <span class="sfb-muted">%s</span><div>%s</div></div>',
			esc_html( $c['author'] ),
			esc_html( $c['date'] ),
			nl2br( esc_html( $c['text'] ) )
		);
	}
	?>
	<p><label for="sfb_asana_reply"><strong>Reageer</strong> <span class="sfb-muted">(wordt bij opslaan als reactie in Asana geplaatst)</span></label>
		<textarea name="sfb_asana_reply" id="sfb_asana_reply" rows="3" class="large-text"></textarea></p>
	<?php
}

function sfb_render_details_box( $post ) {
	$f = sfb_format_feedback( $post );
	$m = $f['mouse'];
	$v = $f['viewport'];
	$e = $f['element'];

	if ( $f['screenshot'] ) {
		printf( '<p><a href="%1$s" target="_blank"><img src="%1$s" class="sfb-shot" alt="Screenshot"></a></p>', esc_url( $f['screenshot'] ) );
	}
	?>
	<table class="widefat striped sfb-table">
		<tr><th>Pagina</th><td><a href="<?php echo esc_url( sfb_view_on_page_url( $f ) ); ?>" target="_blank"><?php echo esc_html( $f['url'] ); ?></a><?php echo $f['page_title'] ? '<br><span class="sfb-muted">' . esc_html( $f['page_title'] ) . '</span>' : ''; ?></td></tr>
		<tr><th>Gemeld door</th><td><?php echo esc_html( $f['user']['name'] . ' — ' . $f['date_local'] ); ?></td></tr>
		<tr><th>Element</th><td><code><?php echo esc_html( $f['selector'] ); ?></code>
			<?php if ( ! empty( $e['text'] ) ) : ?>
				<br><span class="sfb-muted">Tekst: “<?php echo esc_html( $e['text'] ); ?>”</span>
			<?php endif; ?>
			<?php if ( ! empty( $e['html'] ) ) : ?>
				<details><summary>HTML</summary><pre class="sfb-pre"><?php echo esc_html( $e['html'] ); ?></pre></details>
			<?php endif; ?>
		</td></tr>
		<tr><th>Muispositie</th><td>
			<?php
			if ( $m ) {
				printf(
					'Pagina: %s, %s px · Scherm: %s, %s px · In element: %s%%, %s%%',
					esc_html( round( $m['page_x'] ) ),
					esc_html( round( $m['page_y'] ) ),
					esc_html( round( $m['client_x'] ) ),
					esc_html( round( $m['client_y'] ) ),
					esc_html( round( $m['pct_x'] ) ),
					esc_html( round( $m['pct_y'] ) )
				);
			}
			?>
		</td></tr>
		<tr><th>Scherm</th><td><?php echo $v ? esc_html( $v['width'] . ' × ' . $v['height'] . ' px (pixel ratio ' . $v['dpr'] . ')' ) : ''; ?></td></tr>
		<tr><th>Browser</th><td class="sfb-muted"><?php echo esc_html( $f['user_agent'] ); ?></td></tr>
	</table>
	<?php
}

function sfb_render_actions_box( $post ) {
	$f = sfb_format_feedback( $post );
	wp_nonce_field( 'sfb_save_' . $post->ID, 'sfb_nonce' );
	?>
	<p><label for="sfb_status_field"><strong>Status</strong></label><br>
		<select name="sfb_status_field" id="sfb_status_field" style="width:100%">
			<option value="open" <?php selected( $f['status'], 'open' ); ?>>Open</option>
			<option value="resolved" <?php selected( $f['status'], 'resolved' ); ?>>Opgelost</option>
		</select>
	</p>
	<p><strong>Asana</strong><br>
		<?php if ( $f['asana']['url'] ) : ?>
			<a class="button" href="<?php echo esc_url( $f['asana']['url'] ); ?>" target="_blank">Open taak in Asana ↗</a>
			</p>
			<table class="sfb-asana-info">
				<tr><th>Taak</th><td><?php echo $f['asana']['completed'] ? '✓ Voltooid' : 'Open'; ?></td></tr>
				<tr><th>Toegewezen</th><td><?php echo esc_html( $f['asana']['assignee'] ? $f['asana']['assignee'] : '—' ); ?></td></tr>
				<tr><th>Deadline</th><td><?php echo esc_html( $f['asana']['due_on'] ? $f['asana']['due_on'] : '—' ); ?></td></tr>
				<tr><th>Sectie</th><td><?php echo esc_html( $f['asana']['section'] ? $f['asana']['section'] : '—' ); ?></td></tr>
			</table>
			<p class="sfb-muted">Bijgewerkt: <?php echo esc_html( $f['asana']['synced'] ? $f['asana']['synced'] : 'nog niet' ); ?> ·
				<a href="<?php echo esc_url( sfb_asana_action_url( $post->ID, 'sync' ) ); ?>">Nu verversen</a>
		<?php elseif ( $f['asana']['deleted'] && SFB_Asana::is_configured() ) : ?>
			<span class="sfb-muted">De taak is in Asana verwijderd.</span><br>
			<a class="button" href="<?php echo esc_url( sfb_asana_action_url( $post->ID ) ); ?>">Opnieuw aanmaken</a>
		<?php elseif ( SFB_Asana::is_configured() ) : ?>
			<?php if ( $f['asana']['error'] ) : ?>
				<span class="sfb-asana-error">⚠ Versturen mislukt: <?php echo esc_html( $f['asana']['error'] ); ?></span><br>
			<?php endif; ?>
			<a class="button button-primary" href="<?php echo esc_url( sfb_asana_action_url( $post->ID ) ); ?>"><?php echo $f['asana']['error'] ? 'Opnieuw proberen' : 'Maak Asana-taak'; ?></a>
		<?php else : ?>
			<span class="sfb-muted">Niet gekoppeld. <a href="<?php echo esc_url( sfb_settings_url() ); ?>">Instellen</a></span>
		<?php endif; ?>
	</p>
	<?php
}

add_action(
	'save_post_' . SFB_POST_TYPE,
	function ( $post_id ) {
		if ( ! isset( $_POST['sfb_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sfb_nonce'] ), 'sfb_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$user_id = get_current_user_id();

		$status = isset( $_POST['sfb_status_field'] ) ? sanitize_key( $_POST['sfb_status_field'] ) : 'open';
		$result = sfb_set_status( $post_id, $status );
		if ( is_wp_error( $result ) ) {
			set_transient( 'sfb_error_' . $user_id, $result->get_error_message(), MINUTE_IN_SECONDS );
		}

		$reply = isset( $_POST['sfb_asana_reply'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['sfb_asana_reply'] ) ) ) : '';
		if ( '' !== $reply ) {
			$result = SFB_Asana::add_comment( $post_id, $reply );
			if ( is_wp_error( $result ) ) {
				set_transient( 'sfb_error_' . $user_id, $result->get_error_message(), MINUTE_IN_SECONDS );
			}
		}
	}
);

// Asana-fout tijdens opslaan tonen na de redirect.
add_filter(
	'redirect_post_location',
	function ( $location, $post_id ) {
		if ( get_post_type( $post_id ) === SFB_POST_TYPE && get_transient( 'sfb_error_' . get_current_user_id() ) ) {
			$location = add_query_arg( 'sfb_msg', 'asana_err', $location );
		}
		return $location;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * Asana-actie vanuit wp-admin
 * ---------------------------------------------------------------------- */

function sfb_asana_action_url( $post_id, $do = 'create' ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=sfb_asana&do=' . $do . '&id=' . (int) $post_id ), 'sfb_asana_' . (int) $post_id );
}

add_action(
	'admin_post_sfb_asana',
	function () {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen toegang.' );
		}
		check_admin_referer( 'sfb_asana_' . $id );

		$sync   = isset( $_GET['do'] ) && 'sync' === $_GET['do'];
		$result = $sync ? SFB_Asana::sync_task( $id ) : SFB_Asana::create_task( $id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'sfb_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . SFB_POST_TYPE );
		$msg  = is_wp_error( $result ) ? 'asana_err' : ( $sync ? 'synced' : 'asana_ok' );
		wp_safe_redirect( add_query_arg( 'sfb_msg', $msg, remove_query_arg( 'sfb_msg', $back ) ) );
		exit;
	}
);

// Instellingenpagina: webhook aan/uit en alles nu synchroniseren.
add_action(
	'admin_post_sfb_asana_sync',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen toegang.' );
		}
		check_admin_referer( 'sfb_asana_sync' );
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';

		if ( 'webhook_on' === $do ) {
			$result = SFB_Asana::create_webhook();
			$msg    = is_wp_error( $result ) ? 'asana_err' : 'hook_on';
			if ( is_wp_error( $result ) ) {
				set_transient( 'sfb_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			}
		} elseif ( 'webhook_off' === $do ) {
			SFB_Asana::delete_webhook();
			$msg = 'hook_off';
		} elseif ( 'test' === $do ) {
			set_transient( 'sfb_test_' . get_current_user_id(), SFB_Asana::test_connection(), MINUTE_IN_SECONDS );
			wp_safe_redirect( sfb_settings_url() );
			exit;
		} elseif ( 'push_missing' === $do ) {
			set_transient( 'sfb_push_' . get_current_user_id(), SFB_Asana::push_missing(), MINUTE_IN_SECONDS );
			wp_safe_redirect( sfb_settings_url() );
			exit;
		} else {
			$count = SFB_Asana::cron( true );
			set_transient( 'sfb_sync_count_' . get_current_user_id(), $count, MINUTE_IN_SECONDS );
			$msg = 'sync_all';
		}
		wp_safe_redirect( sfb_settings_url( array( 'sfb_msg' => $msg ) ) . '#sfb-sync' );
		exit;
	}
);

add_action(
	'admin_notices',
	function () {
		if ( ! sfb_is_screen() ) {
			return;
		}
		// Token wel, project niet: dan gaat er niets naar Asana. Op de lijst/detail duidelijk melden.
		$settings = sfb_settings();
		if ( $settings['asana_token'] && ! $settings['asana_project'] && ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice notice-warning"><p><strong>Feedback gaat nog niet naar Asana:</strong> er is geen project gekozen. <a href="%s">Kies een project</a>.</p></div>',
				esc_url( sfb_settings_url() )
			);
		}
		if ( empty( $_GET['sfb_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg      = sanitize_key( $_GET['sfb_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'asana_ok' => array( 'success', 'Asana-taak aangemaakt.' ),
			'bulk_ok'  => array( 'success', 'Feedback bijgewerkt.' ),
			'synced'   => array( 'success', 'Bijgewerkt met de laatste stand uit Asana.' ),
			'hook_on'  => array( 'success', 'Realtime-sync staat aan: wijzigingen in Asana komen direct binnen.' ),
			'hook_off' => array( 'success', 'Realtime-sync uitgezet. Er wordt nog wel elke 15 minuten gesynchroniseerd.' ),
		);
		if ( 'sync_all' === $msg ) {
			$count               = (int) get_transient( 'sfb_sync_count_' . get_current_user_id() );
			$messages[ $msg ] = array( 'success', sprintf( '%d taken bijgewerkt vanuit Asana.', $count ) );
		}
		if ( 'asana_err' === $msg ) {
			$error = get_transient( 'sfb_error_' . get_current_user_id() );
			delete_transient( 'sfb_error_' . get_current_user_id() );
			$messages['asana_err'] = array( 'error', $error ? $error : 'Niet alle Asana-taken konden worden aangemaakt.' );
		}
		if ( isset( $messages[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
		}
	}
);

/* -------------------------------------------------------------------------
 * Instellingen
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=' . SFB_POST_TYPE, 'Feedback-instellingen', 'Instellingen', 'manage_options', 'sfb-settings', 'sfb_render_settings_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting( 'sfb_settings_group', 'sfb_settings', array( 'sanitize_callback' => 'sfb_sanitize_settings' ) );
	}
);

function sfb_sanitize_settings( $input ) {
	$input = (array) $input;
	$old   = sfb_settings();
	$out   = array(
		'frontend'        => empty( $input['frontend'] ) ? 0 : 1,
		'asana_auto'      => empty( $input['asana_auto'] ) ? 0 : 1,
		'asana_token'     => $old['asana_token'],
		'asana_project'   => preg_replace( '/[^0-9]/', '', (string) ( $input['asana_project'] ?? '' ) ),
	);
	if ( ! empty( $input['asana_token_clear'] ) ) {
		$out['asana_token']   = '';
		$out['asana_project'] = '';
	} elseif ( ! empty( $input['asana_token'] ) ) {
		$out['asana_token'] = sanitize_text_field( $input['asana_token'] );
	}
	return $out;
}

function sfb_render_settings_page() {
	$s         = sfb_settings();
	$uid       = get_current_user_id();

	$projects = null;
	if ( $s['asana_token'] ) {
		$projects = SFB_Asana::get_projects( $s['asana_token'] );
	}
	?>
	<div class="wrap sfb-settings">
		<h1>Feedback — instellingen</h1>

		<?php
		$test = get_transient( 'sfb_test_' . $uid );
		if ( $test ) {
			delete_transient( 'sfb_test_' . $uid );
			printf(
				'<div class="notice notice-%s"><p><strong>Verbindingstest</strong></p><p>%s</p></div>',
				$test['ok'] ? 'success' : 'error',
				implode( '<br>', array_map( 'esc_html', $test['lines'] ) )
			);
		}
		$push = get_transient( 'sfb_push_' . $uid );
		if ( $push ) {
			delete_transient( 'sfb_push_' . $uid );
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$push['failed'] ? 'error' : 'success',
				esc_html( sprintf( '%d taken aangemaakt in Asana.', $push['created'] ) . ( $push['failed'] ? sprintf( ' %d mislukt: %s', $push['failed'], $push['error'] ) : '' ) )
			);
		}
		if ( $s['asana_token'] && ! $s['asana_project'] ) {
			echo '<div class="notice notice-warning"><p><strong>Asana is nog niet actief:</strong> kies hieronder een project en sla op.</p></div>';
		}
		?>

		<form method="post" action="options.php">
			<?php settings_fields( 'sfb_settings_group' ); ?>

			<h2>Algemeen</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Feedback-widget</th>
					<td><label><input type="checkbox" name="sfb_settings[frontend]" value="1" <?php checked( $s['frontend'] ); ?>> Toon het feedback-icoon op de website (alleen voor beheerders)</label></td>
				</tr>
			</table>

			<h2>Asana</h2>
			<p>Maak een <strong>Personal Access Token</strong> aan in Asana via <a href="https://app.asana.com/0/my-apps" target="_blank">Mijn apps → Developer console</a>. Taken worden aangemaakt namens de eigenaar van het token.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sfb_asana_token">Personal Access Token</label></th>
					<td>
						<input type="password" id="sfb_asana_token" name="sfb_settings[asana_token]" class="regular-text" autocomplete="off" placeholder="<?php echo $s['asana_token'] ? esc_attr( '•••••••• (opgeslagen) — laat leeg om te behouden' ) : ''; ?>">
						<?php if ( $s['asana_token'] ) : ?>
							<br><label><input type="checkbox" name="sfb_settings[asana_token_clear]" value="1"> Koppeling verwijderen</label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sfb_asana_project">Project</label></th>
					<td>
						<?php if ( ! $s['asana_token'] ) : ?>
							<span class="sfb-muted">Sla eerst een token op, daarna kun je hier een project kiezen.</span>
						<?php elseif ( is_wp_error( $projects ) ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( $projects->get_error_message() ); ?></p></div>
							<input type="text" id="sfb_asana_project" name="sfb_settings[asana_project]" value="<?php echo esc_attr( $s['asana_project'] ); ?>" class="regular-text" placeholder="Project-GID">
						<?php elseif ( ! $projects ) : ?>
							<div class="notice notice-warning inline"><p>Het token werkt, maar er zijn geen projecten gevonden. Maak eerst een project aan in Asana (of vraag toegang), en herlaad deze pagina.</p></div>
						<?php else : ?>
							<select id="sfb_asana_project" name="sfb_settings[asana_project]"<?php echo $s['asana_project'] ? '' : ' class="sfb-needs-attention"'; ?>>
								<option value="">— Kies een project —</option>
								<?php foreach ( $projects as $p ) : ?>
									<option value="<?php echo esc_attr( $p['gid'] ); ?>" <?php selected( $s['asana_project'], $p['gid'] ); ?>><?php echo esc_html( $p['workspace'] . ' / ' . $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( ! $s['asana_project'] ) : ?>
								<p class="description sfb-warn">⚠ Kies hier het project en klik op <strong>Instellingen opslaan</strong>. Zolang er geen project gekozen is, wordt er niets naar Asana gestuurd.</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Automatisch</th>
					<td><label><input type="checkbox" name="sfb_settings[asana_auto]" value="1" <?php checked( $s['asana_auto'] ); ?>> Stuur alle feedback automatisch naar Asana (aanbevolen)</label>
						<p class="description">Mislukt het aanmaken (bijv. Asana even onbereikbaar), dan wordt het elke 15 minuten opnieuw geprobeerd. Ook bestaande feedback zonder taak wordt zo alsnog aangemaakt.</p></td>
				</tr>
			</table>

			<?php submit_button( 'Instellingen opslaan' ); ?>
		</form>

		<?php if ( $s['asana_token'] ) : ?>
			<?php
			$missing = SFB_Asana::is_configured() ? count(
				get_posts(
					array(
						'post_type'   => SFB_POST_TYPE,
						'post_status' => 'publish',
						'numberposts' => -1,
						'fields'      => 'ids',
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'key'     => '_sfb_asana_gid',
								'compare' => 'NOT EXISTS',
							),
						),
					)
				)
			) : 0;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sfb-inline-actions">
				<input type="hidden" name="action" value="sfb_asana_sync">
				<?php wp_nonce_field( 'sfb_asana_sync' ); ?>
				<button class="button" name="do" value="test">Verbinding testen</button>
				<?php if ( $missing ) : ?>
					<button class="button button-primary" name="do" value="push_missing"><?php echo esc_html( sprintf( 'Stuur %d feedback zonder taak naar Asana', $missing ) ); ?></button>
				<?php endif; ?>
			</form>
		<?php endif; ?>

		<?php if ( SFB_Asana::is_configured() ) : ?>
			<?php
			$hook      = SFB_Asana::webhook();
			$last_sync = (int) get_option( 'sfb_last_sync' );
			$last_hook = (int) get_option( 'sfb_last_webhook' );
			$next_cron = wp_next_scheduled( 'sfb_asana_cron' );
			?>
			<hr>
			<h2 id="sfb-sync">Synchronisatie vanuit Asana</h2>
			<p>De plugin leest per taak de status, de toegewezen persoon, de deadline, de sectie en de reacties uit Asana. Voltooi je een taak in Asana, dan wordt de feedback op <em>opgelost</em> gezet. Andersom geldt hetzelfde.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Realtime (webhook)</th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="sfb_asana_sync">
							<?php wp_nonce_field( 'sfb_asana_sync' ); ?>
							<?php if ( ! empty( $hook['gid'] ) ) : ?>
								<span class="sfb-badge sfb-resolved">Actief</span>
								<span class="sfb-muted"><?php echo $last_hook ? esc_html( 'laatste update ' . human_time_diff( $last_hook ) . ' geleden' ) : 'nog geen updates ontvangen'; ?></span>
								<button class="button" name="do" value="webhook_off">Uitschakelen</button>
							<?php elseif ( sfb_is_local_site() ) : ?>
								<span class="sfb-badge">Niet beschikbaar</span>
								<p class="description">Deze site draait lokaal (<code><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code>), dus Asana kan je site niet bereiken om updates te sturen. Dat is geen probleem: taken aanmaken werkt gewoon, en wijzigingen uit Asana (zoals een taak op done) worden binnen enkele seconden opgehaald zodra je de feedbacklijst of een pagina van de site opent. Op de live site kun je realtime-sync wel aanzetten.</p>
							<?php else : ?>
								<span class="sfb-badge">Uit</span>
								<button class="button button-primary" name="do" value="webhook_on">Realtime-sync activeren</button>
								<p class="description">Asana stuurt dan elke wijziging direct naar <code><?php echo esc_html( SFB_Asana::webhook_url() ); ?></code>. Daarvoor moet de site publiek bereikbaar zijn via https. Werkt dat niet (bijv. op een lokale of afgeschermde staging-site), dan valt de plugin terug op de sync elke 15 minuten.</p>
							<?php endif; ?>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row">Periodiek</th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="sfb_asana_sync">
							<?php wp_nonce_field( 'sfb_asana_sync' ); ?>
							<span class="sfb-muted">Elke 15 minuten via WP-cron. Laatste run: <?php echo $last_sync ? esc_html( human_time_diff( $last_sync ) . ' geleden' ) : 'nog niet'; ?><?php echo $next_cron ? esc_html( ' · volgende over ' . human_time_diff( $next_cron ) ) : ''; ?>.</span>
							<button class="button" name="do" value="sync_all">Nu alles synchroniseren</button>
						</form>
					</td>
				</tr>
			</table>
		<?php endif; ?>

	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Admin-styling
 * ---------------------------------------------------------------------- */

add_action(
	'admin_head',
	function () {
		if ( ! sfb_is_screen() ) {
			return;
		}
		?>
		<style>
			.sfb-thumb{width:120px;height:72px;object-fit:cover;object-position:top;border:1px solid #dcdcde;border-radius:4px;display:block}
			.column-sfb_shot{width:130px}.column-sfb_status,.column-sfb_asana{width:110px}
			.sfb-avatar{width:22px;height:22px;border-radius:50%;vertical-align:middle;margin-right:4px}
			.sfb-muted{color:#787c82}
			.sfb-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
			.sfb-open{background:#fcf0f1;color:#b32d2e}.sfb-resolved{background:#edfaef;color:#008a20}
			.sfb-shot{max-width:100%;border:1px solid #dcdcde;border-radius:6px}
			.sfb-table th{width:130px;font-weight:600}
			.sfb-pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:8px;max-height:200px;overflow:auto}
			.sfb-asana-info th{text-align:left;font-weight:600;padding:2px 10px 2px 0}.sfb-asana-info td{padding:2px 0}
			.sfb-comment{border-left:3px solid #f06a6a;background:#f6f7f7;padding:8px 12px;margin:0 0 10px;border-radius:0 4px 4px 0}
			.sfb-comment div{margin-top:4px}
			.sfb-needs-attention{border-color:#dba617!important;box-shadow:0 0 0 2px #f5e6ab}
			.sfb-warn{color:#8a6100}
			.sfb-inline-actions{margin:-10px 0 20px}
			.sfb-inline-actions .button{margin-right:6px}
			.sfb-asana-error{color:#b32d2e}
		</style>
		<?php
	}
);
