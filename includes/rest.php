<?php
/**
 * REST API: /wp-json/site-feedback/v1/...
 *
 * Toegang: alleen ingelogde beheerders (cookie + REST-nonce).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'sfb_register_routes' );
function sfb_register_routes() {
	$ns   = 'site-feedback/v1';
	$perm = 'sfb_rest_permission';

	register_rest_route(
		$ns,
		'/feedback',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'sfb_rest_list',
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'sfb_rest_create',
				'permission_callback' => $perm,
			),
		)
	);

	register_rest_route(
		$ns,
		'/feedback/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'sfb_rest_get',
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'sfb_rest_update',
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'sfb_rest_delete',
				'permission_callback' => $perm,
			),
		)
	);

	register_rest_route(
		$ns,
		'/feedback/(?P<id>\d+)/asana',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sfb_rest_asana',
			'permission_callback' => $perm,
		)
	);

	register_rest_route(
		$ns,
		'/feedback/(?P<id>\d+)/comment',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sfb_rest_comment',
			'permission_callback' => $perm,
		)
	);

	// Asana roept dit zelf aan; beveiligd met handshake + HMAC-handtekening.
	register_rest_route(
		$ns,
		'/asana/webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( 'SFB_Asana', 'handle_webhook' ),
			'permission_callback' => '__return_true',
		)
	);
}

function sfb_rest_permission() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return new WP_Error( 'sfb_forbidden', 'Geen toegang tot feedback.', array( 'status' => rest_authorization_required_code() ) );
}

function sfb_get_feedback_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || SFB_POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
		return new WP_Error( 'sfb_not_found', 'Feedback niet gevonden.', array( 'status' => 404 ) );
	}
	return $post;
}

function sfb_numbers( $input, $keys ) {
	$out = array();
	foreach ( $keys as $key ) {
		$out[ $key ] = ( is_array( $input ) && isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) ? round( (float) $input[ $key ], 2 ) : 0;
	}
	return $out;
}

/* ---------------------------------------------------------------------- */

function sfb_rest_list( WP_REST_Request $request ) {
	// Eerst wijzigingen uit Asana ophalen (bijv. taak op "done" gezet), max. 1x per 15 sec.
	SFB_Asana::sync_changed( 15 );

	$meta_query = array();

	$status = $request->get_param( 'status' );
	if ( 'all' !== $status ) {
		$meta_query[] = array(
			'key'   => '_sfb_status',
			'value' => 'resolved' === $status ? 'resolved' : 'open',
		);
	}

	$url = $request->get_param( 'url' );
	if ( $url ) {
		$meta_query[] = array(
			'key'   => '_sfb_url_key',
			'value' => sfb_url_key( $url ),
		);
	}

	$query = new WP_Query(
		array(
			'post_type'      => SFB_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	return rest_ensure_response( array_map( 'sfb_format_feedback', $query->posts ) );
}

function sfb_rest_get( WP_REST_Request $request ) {
	$post = sfb_get_feedback_post( $request['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	// ?sync=1: eerst de laatste stand uit Asana ophalen (max. 1x per 30 sec).
	if ( $request->get_param( 'sync' ) && SFB_Asana::is_configured() ) {
		SFB_Asana::maybe_sync( $post->ID, 30 );
	}
	return rest_ensure_response( sfb_format_feedback( $post ) );
}

function sfb_rest_comment( WP_REST_Request $request ) {
	$post = sfb_get_feedback_post( $request['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$text = trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) );
	if ( '' === $text ) {
		return new WP_Error( 'sfb_empty', 'Typ eerst een reactie.', array( 'status' => 400 ) );
	}
	$result = SFB_Asana::add_comment( $post->ID, $text );
	return is_wp_error( $result ) ? $result : rest_ensure_response( sfb_format_feedback( $post->ID ) );
}

function sfb_rest_create( WP_REST_Request $request ) {
	$p = $request->get_json_params();
	$p = is_array( $p ) ? $p : array();

	$comment = isset( $p['comment'] ) ? trim( sanitize_textarea_field( $p['comment'] ) ) : '';
	if ( '' === $comment ) {
		return new WP_Error( 'sfb_empty', 'Beschrijf eerst wat er mis is.', array( 'status' => 400 ) );
	}

	$url = isset( $p['url'] ) ? esc_url_raw( $p['url'] ) : '';

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => SFB_POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => wp_trim_words( $comment, 12, '…' ),
				'post_content' => $comment,
				'post_author'  => get_current_user_id(),
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$el = isset( $p['element'] ) && is_array( $p['element'] ) ? $p['element'] : array();

	sfb_update_meta( $post_id, '_sfb_status', 'open' );
	sfb_update_meta( $post_id, '_sfb_url', $url );
	sfb_update_meta( $post_id, '_sfb_url_key', sfb_url_key( $url ) );
	sfb_update_meta( $post_id, '_sfb_page_title', sanitize_text_field( $p['page_title'] ?? '' ) );
	sfb_update_meta( $post_id, '_sfb_selector', mb_substr( wp_strip_all_tags( (string) ( $p['selector'] ?? '' ) ), 0, 1000 ) );
	sfb_update_meta(
		$post_id,
		'_sfb_element',
		array(
			'tag'     => sanitize_key( $el['tag'] ?? '' ),
			'id'      => sanitize_text_field( $el['id'] ?? '' ),
			'classes' => sanitize_text_field( $el['classes'] ?? '' ),
			'text'    => mb_substr( sanitize_text_field( $el['text'] ?? '' ), 0, 200 ),
			'html'    => mb_substr( (string) ( $el['html'] ?? '' ), 0, 1000 ), // Wordt altijd ge-escaped bij tonen.
		)
	);
	sfb_update_meta( $post_id, '_sfb_mouse', sfb_numbers( $p['mouse'] ?? null, array( 'client_x', 'client_y', 'page_x', 'page_y', 'offset_x', 'offset_y', 'pct_x', 'pct_y' ) ) );
	sfb_update_meta( $post_id, '_sfb_viewport', sfb_numbers( $p['viewport'] ?? null, array( 'width', 'height', 'dpr', 'scroll_x', 'scroll_y' ) ) );
	sfb_update_meta( $post_id, '_sfb_user_agent', sanitize_text_field( $p['user_agent'] ?? $request->get_header( 'user_agent' ) ) );

	if ( ! empty( $p['screenshot'] ) ) {
		sfb_save_screenshot( $post_id, $p['screenshot'] );
	}

	$warning = '';
	if ( ! empty( sfb_settings()['asana_auto'] ) && SFB_Asana::is_configured() ) {
		$result = SFB_Asana::create_task( $post_id );
		if ( is_wp_error( $result ) ) {
			// Feedback is wél opgeslagen; cron probeert het later opnieuw.
			$warning = 'Feedback opgeslagen, maar niet naar Asana gestuurd: ' . SFB_Asana::explain( $result );
		}
	}

	$data            = sfb_format_feedback( $post_id );
	$data['warning'] = $warning;
	$response        = rest_ensure_response( $data );
	$response->set_status( 201 );
	return $response;
}

function sfb_rest_update( WP_REST_Request $request ) {
	$post = sfb_get_feedback_post( $request['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$warning = '';
	$status  = $request->get_param( 'status' );
	if ( in_array( $status, array( 'open', 'resolved' ), true ) ) {
		$pushed = sfb_set_status( $post->ID, $status );
		if ( is_wp_error( $pushed ) ) {
			$warning = 'Status opgeslagen, maar Asana kon niet worden bijgewerkt: ' . $pushed->get_error_message();
		}
	}

	$comment = $request->get_param( 'comment' );
	if ( is_string( $comment ) && '' !== trim( $comment ) ) {
		$comment = trim( sanitize_textarea_field( $comment ) );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $comment,
					'post_title'   => wp_trim_words( $comment, 12, '…' ),
				)
			)
		);
	}

	$data            = sfb_format_feedback( $post->ID );
	$data['warning'] = $warning;
	return rest_ensure_response( $data );
}

function sfb_rest_delete( WP_REST_Request $request ) {
	$post = sfb_get_feedback_post( $request['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$had_task = (bool) get_post_meta( $post->ID, '_sfb_asana_gid', true );
	wp_trash_post( $post->ID ); // Naar de prullenbak (30 dagen terug te zetten); de hook verwijdert ook de Asana-taak.

	$pending = (bool) get_post_meta( $post->ID, '_sfb_asana_delete_pending', true );
	return rest_ensure_response(
		array(
			'deleted'       => true,
			'asana_deleted' => $had_task && ! $pending,
			'warning'       => $pending ? 'Feedback verwijderd, maar de Asana-taak kon nog niet worden verwijderd. Dat wordt later automatisch opnieuw geprobeerd.' : '',
		)
	);
}

function sfb_rest_asana( WP_REST_Request $request ) {
	$post = sfb_get_feedback_post( $request['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$result = SFB_Asana::create_task( $post->ID );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'sfb_asana', SFB_Asana::explain( $result ), array( 'status' => 502 ) );
	}
	return rest_ensure_response( sfb_format_feedback( $post->ID ) );
}
