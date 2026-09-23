<?php
/**
 * Gedeelde helpers: instellingen, screenshots en formatteren van feedback.
 */

defined( 'ABSPATH' ) || exit;

function sfb_settings() {
	$defaults = array(
		'frontend'        => 1,
		'asana_token'     => '',
		'asana_project'   => '',
		'asana_auto'      => 1,
	);
	return wp_parse_args( (array) get_option( 'sfb_settings', array() ), $defaults );
}

/**
 * Normaliseert een URL naar "host/pad" zodat feedback per pagina teruggevonden wordt,
 * ongeacht querystring, hash of slash aan het einde.
 */
function sfb_url_key( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['host'] ) ) {
		return '';
	}
	$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
	return strtolower( $parts['host'] ) . ( '' === $path ? '/' : $path );
}

/**
 * Draait deze site lokaal (niet bereikbaar vanaf internet)? Dan kan Asana geen webhooks sturen.
 */
function sfb_is_local_site() {
	if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
		return true;
	}
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( 'localhost' === $host || preg_match( '/\.(local|test|localhost|lan|internal)$/', $host ) ) {
		return true;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
	return false;
}

function sfb_update_meta( $post_id, $key, $value ) {
	// update_post_meta() haalt slashes weg, dus eerst toevoegen.
	update_post_meta( $post_id, $key, wp_slash( $value ) );
}

/**
 * Zet de status en stuurt die (optioneel) door naar Asana: opgelost = taak voltooid.
 *
 * @return true|WP_Error Fout als Asana niet bijgewerkt kon worden (de lokale status is dan wél gewijzigd).
 */
function sfb_set_status( $post_id, $status, $push = true ) {
	$status = 'resolved' === $status ? 'resolved' : 'open';
	$old    = get_post_meta( $post_id, '_sfb_status', true );
	update_post_meta( $post_id, '_sfb_status', $status );

	if ( $push && $old !== $status && get_post_meta( $post_id, '_sfb_asana_gid', true ) ) {
		$result = SFB_Asana::set_completed( $post_id, 'resolved' === $status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}

function sfb_find_by_asana_gid( $gid ) {
	$ids = get_posts(
		array(
			'post_type'   => SFB_POST_TYPE,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_sfb_asana_gid', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => (string) $gid, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/* -------------------------------------------------------------------------
 * Screenshots
 * ---------------------------------------------------------------------- */

function sfb_screenshot_dir() {
	$uploads = wp_upload_dir();
	return array(
		'path' => trailingslashit( $uploads['basedir'] ) . 'site-feedback/',
		'url'  => trailingslashit( $uploads['baseurl'] ) . 'site-feedback/',
	);
}

/**
 * Slaat een screenshot (data-URL) op in uploads/site-feedback/.
 */
function sfb_save_screenshot( $post_id, $data_url ) {
	if ( ! is_string( $data_url ) || ! preg_match( '#^data:image/(png|jpeg|webp);base64,#', $data_url ) ) {
		return '';
	}

	$binary = base64_decode( substr( $data_url, strpos( $data_url, ',' ) + 1 ), true );
	if ( ! $binary || strlen( $binary ) > 10 * MB_IN_BYTES ) {
		return '';
	}

	$info  = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$types = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);
	if ( ! $info || ! isset( $types[ $info['mime'] ] ) ) {
		return '';
	}

	$dir = sfb_screenshot_dir();
	if ( ! wp_mkdir_p( $dir['path'] ) ) {
		return '';
	}
	if ( ! file_exists( $dir['path'] . 'index.php' ) ) {
		file_put_contents( $dir['path'] . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$file = $post_id . '-' . wp_generate_password( 16, false ) . '.' . $types[ $info['mime'] ];
	if ( false === file_put_contents( $dir['path'] . $file, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return '';
	}

	update_post_meta( $post_id, '_sfb_screenshot', $file );
	return $file;
}

function sfb_screenshot_path( $post_id ) {
	$file = get_post_meta( $post_id, '_sfb_screenshot', true );
	if ( ! $file ) {
		return '';
	}
	$path = sfb_screenshot_dir()['path'] . basename( $file );
	return file_exists( $path ) ? $path : '';
}

function sfb_screenshot_url( $post_id ) {
	$file = get_post_meta( $post_id, '_sfb_screenshot', true );
	return $file ? sfb_screenshot_dir()['url'] . rawurlencode( basename( $file ) ) : '';
}

// Screenshot opruimen wanneer feedback definitief verwijderd wordt.
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( get_post_type( $post_id ) !== SFB_POST_TYPE ) {
			return;
		}
		$path = sfb_screenshot_path( $post_id );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}
);

/* -------------------------------------------------------------------------
 * Feedback-item als array (voor REST, admin en Asana)
 * ---------------------------------------------------------------------- */

function sfb_format_feedback( $post ) {
	$post = get_post( $post );
	$id   = $post->ID;
	$user = get_userdata( $post->post_author );
	$time = (int) get_post_time( 'U', true, $post );

	$asana    = (array) get_post_meta( $id, '_sfb_asana_data', true );
	$comments = array();
	foreach ( (array) get_post_meta( $id, '_sfb_asana_comments', true ) as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$ts         = ! empty( $c['date'] ) ? strtotime( $c['date'] ) : 0;
		$comments[] = array(
			'author' => (string) ( $c['author'] ?? '' ),
			'text'   => (string) ( $c['text'] ?? '' ),
			'date'   => $ts ? wp_date( 'j M, H:i', $ts ) : '',
		);
	}

	return array(
		'id'         => $id,
		'comment'    => $post->post_content,
		'url'        => (string) get_post_meta( $id, '_sfb_url', true ),
		'page_title' => (string) get_post_meta( $id, '_sfb_page_title', true ),
		'selector'   => (string) get_post_meta( $id, '_sfb_selector', true ),
		'element'    => (array) get_post_meta( $id, '_sfb_element', true ),
		'mouse'      => (array) get_post_meta( $id, '_sfb_mouse', true ),
		'viewport'   => (array) get_post_meta( $id, '_sfb_viewport', true ),
		'user_agent' => (string) get_post_meta( $id, '_sfb_user_agent', true ),
		'status'     => get_post_meta( $id, '_sfb_status', true ) === 'resolved' ? 'resolved' : 'open',
		'user'       => array(
			'id'     => (int) $post->post_author,
			'name'   => $user ? $user->display_name : 'Onbekend',
			'avatar' => get_avatar_url( $post->post_author, array( 'size' => 48 ) ),
		),
		'date'       => gmdate( 'c', $time ),
		'date_human' => sprintf( '%s geleden', human_time_diff( $time ) ),
		'date_local' => wp_date( 'j M Y, H:i', $time ),
		'screenshot' => sfb_screenshot_url( $id ),
		'asana'      => array(
			'gid'       => (string) get_post_meta( $id, '_sfb_asana_gid', true ),
			'url'       => (string) get_post_meta( $id, '_sfb_asana_url', true ),
			'deleted'   => (bool) get_post_meta( $id, '_sfb_asana_deleted', true ),
			'error'     => (string) get_post_meta( $id, '_sfb_asana_error', true ),
			'completed' => ! empty( $asana['completed'] ),
			'assignee'  => (string) ( $asana['assignee'] ?? '' ),
			'due_on'    => ! empty( $asana['due_on'] ) ? wp_date( 'j M Y', strtotime( $asana['due_on'] ) ) : '',
			'section'   => (string) ( $asana['section'] ?? '' ),
			'synced'    => ! empty( $asana['synced_at'] ) ? sprintf( '%s geleden', human_time_diff( (int) $asana['synced_at'] ) ) : '',
			'comments'  => $comments,
		),
		'edit_link'  => admin_url( 'post.php?post=' . $id . '&action=edit' ),
	);
}
