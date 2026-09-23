<?php
/**
 * Asana-koppeling in twee richtingen.
 *
 * WordPress → Asana: taak aanmaken (met screenshot), voltooien/heropenen, reacties plaatsen.
 * Asana → WordPress: status, toegewezen persoon, deadline, sectie en reacties terughalen,
 *                    via een webhook (realtime), WP-cron (elke 15 min) en bij het openen van een item.
 *
 * @see https://developers.asana.com/reference/rest-api-reference
 */

defined( 'ABSPATH' ) || exit;

class SFB_Asana {

	const API         = 'https://app.asana.com/api/1.0';
	const HOOK_OPTION = 'sfb_asana_webhook';
	const TASK_FIELDS = 'name,completed,due_on,permalink_url,assignee.name,memberships.project.gid,memberships.section.name';

	public static function is_configured() {
		$s = sfb_settings();
		return ! empty( $s['asana_token'] ) && ! empty( $s['asana_project'] );
	}

	/**
	 * @return array|WP_Error Het "data"-deel van het Asana-antwoord.
	 */
	public static function request( $method, $path, $data = null, $token = null ) {
		$token = $token ? $token : sfb_settings()['asana_token'];
		$args  = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $data ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( array( 'data' => $data ) );
		}
		return self::parse( wp_remote_request( self::API . $path, $args ) );
	}

	private static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 300 ) {
			$msg = isset( $json['errors'][0]['message'] ) ? $json['errors'][0]['message'] : 'HTTP ' . $code;
			return new WP_Error(
				'sfb_asana',
				'Asana: ' . $msg,
				array(
					'status' => 502,
					'http'   => $code,
				)
			);
		}
		return isset( $json['data'] ) ? $json['data'] : array();
	}

	/**
	 * Maakt van een (technische) fout een begrijpelijke melding met een tip.
	 */
	public static function explain( WP_Error $error ) {
		$msg  = $error->get_error_message();
		$data = (array) $error->get_error_data();
		$http = (int) ( $data['http'] ?? 0 );

		if ( preg_match( '/cURL error (60|77|35)|SSL/i', $msg ) ) {
			return $msg . ' — De server kan het SSL-certificaat van Asana niet controleren. Dit komt vaak voor op lokale omgevingen (XAMPP/WAMP): werk WordPress bij, of stel in php.ini "curl.cainfo" en "openssl.cafile" in op een actueel cacert.pem.';
		}
		if ( preg_match( '/cURL error (6|7|28)/i', $msg ) ) {
			return $msg . ' — De server kan Asana niet bereiken. Controleer de internetverbinding, firewall of proxy.';
		}
		if ( 401 === $http ) {
			return $msg . ' — Het Personal Access Token is ongeldig of ingetrokken. Maak een nieuw token aan.';
		}
		if ( 403 === $http || 404 === $http ) {
			return $msg . ' — Het project bestaat niet (meer) of de eigenaar van het token heeft er geen toegang toe.';
		}
		return $msg;
	}

	/**
	 * Controleert token, project en verbinding. Wordt gebruikt door "Verbinding testen".
	 *
	 * @return array [ ok => bool, lines => string[] ]
	 */
	public static function test_connection() {
		$s     = sfb_settings();
		$lines = array();

		if ( empty( $s['asana_token'] ) ) {
			return array( 'ok' => false, 'lines' => array( '✗ Er is nog geen Personal Access Token opgeslagen.' ) );
		}

		$me = self::request( 'GET', '/users/me?opt_fields=name,email' );
		if ( is_wp_error( $me ) ) {
			return array( 'ok' => false, 'lines' => array( '✗ Verbinding met Asana mislukt: ' . self::explain( $me ) ) );
		}
		$lines[] = '✓ Verbonden met Asana als ' . $me['name'] . ( ! empty( $me['email'] ) ? ' (' . $me['email'] . ')' : '' ) . '.';

		if ( empty( $s['asana_project'] ) ) {
			$lines[] = '✗ Er is nog geen project gekozen.';
			return array( 'ok' => false, 'lines' => $lines );
		}
		$project = self::request( 'GET', '/projects/' . rawurlencode( $s['asana_project'] ) . '?opt_fields=name' );
		if ( is_wp_error( $project ) ) {
			$lines[] = '✗ Project niet bereikbaar: ' . self::explain( $project );
			return array( 'ok' => false, 'lines' => $lines );
		}
		$lines[] = '✓ Taken komen in project "' . $project['name'] . '".';
		$lines[] = empty( $s['asana_auto'] )
			? '⚠ Automatisch versturen staat UIT: nieuwe feedback gaat pas naar Asana als je op "+ Asana-taak" klikt.'
			: '✓ Automatisch versturen staat aan.';

		return array( 'ok' => true, 'lines' => $lines );
	}

	/**
	 * Stuurt alle feedback zonder taak alsnog naar Asana.
	 *
	 * @return array [ created => int, failed => int, error => string ]
	 */
	public static function push_missing( $limit = 50 ) {
		$ids    = get_posts(
			array(
				'post_type'   => SFB_POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sfb_asana_gid',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$result = array(
			'created' => 0,
			'failed'  => 0,
			'error'   => '',
		);
		foreach ( $ids as $id ) {
			$r = self::create_task( $id );
			if ( is_wp_error( $r ) ) {
				$result['failed']++;
				$result['error'] = $result['error'] ? $result['error'] : self::explain( $r );
			} else {
				$result['created']++;
			}
		}
		return $result;
	}

	/**
	 * Alle projecten in alle workspaces van het token (10 minuten gecachet).
	 *
	 * @return array|WP_Error [ [gid, name, workspace], ... ]
	 */
	public static function get_projects( $token ) {
		$cache_key = 'sfb_asana_projects_' . md5( $token );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$workspaces = self::request( 'GET', '/workspaces?limit=100', null, $token );
		if ( is_wp_error( $workspaces ) ) {
			return $workspaces;
		}

		$projects = array();
		foreach ( $workspaces as $ws ) {
			$list = self::request( 'GET', '/projects?archived=false&limit=100&opt_fields=name&workspace=' . rawurlencode( $ws['gid'] ), null, $token );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $p ) {
				$projects[] = array(
					'gid'       => $p['gid'],
					'name'      => $p['name'],
					'workspace' => $ws['name'],
				);
			}
		}

		set_transient( $cache_key, $projects, 10 * MINUTE_IN_SECONDS );
		return $projects;
	}

	/* ---------------------------------------------------------------------
	 * WordPress → Asana
	 * ------------------------------------------------------------------ */

	/**
	 * Maakt een Asana-taak voor een feedback-item (of geeft de bestaande terug).
	 *
	 * @return array|WP_Error [gid, url]
	 */
	public static function create_task( $post_id ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'sfb_asana_config', 'Asana is nog niet ingesteld. Vul een token en project in bij Feedback → Instellingen.', array( 'status' => 400 ) );
		}

		$existing = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( $existing ) {
			return array(
				'gid' => $existing,
				'url' => get_post_meta( $post_id, '_sfb_asana_url', true ),
			);
		}

		$f     = sfb_format_feedback( $post_id );
		$m     = $f['mouse'];
		$v     = $f['viewport'];
		$notes = array(
			$f['comment'],
			'',
			'— Details —',
			'Pagina: ' . $f['url'],
			'Element: ' . $f['selector'],
			'Muispositie: ' . ( isset( $m['page_x'] ) ? round( $m['page_x'] ) . ', ' . round( $m['page_y'] ) . ' px (pagina)' : '-' ),
			'Scherm: ' . ( isset( $v['width'] ) ? $v['width'] . ' × ' . $v['height'] . ' px' : '-' ),
			'Browser: ' . $f['user_agent'],
			'Gemeld door: ' . $f['user']['name'] . ' op ' . $f['date_local'],
			'',
			'Bekijk in WordPress: ' . $f['edit_link'],
		);

		$task = self::request(
			'POST',
			'/tasks?opt_fields=gid,permalink_url',
			array(
				'name'      => 'Feedback: ' . wp_html_excerpt( preg_replace( '/\s+/', ' ', $f['comment'] ), 80, '…' ),
				'notes'     => implode( "\n", $notes ),
				'projects'  => array( sfb_settings()['asana_project'] ),
				'completed' => 'resolved' === $f['status'],
			)
		);
		if ( is_wp_error( $task ) ) {
			// Fout bewaren, zodat hij zichtbaar is in de widget en in wp-admin.
			sfb_update_meta( $post_id, '_sfb_asana_error', self::explain( $task ) );
			return $task;
		}
		delete_post_meta( $post_id, '_sfb_asana_error' );

		$url = ! empty( $task['permalink_url'] ) ? $task['permalink_url'] : 'https://app.asana.com/0/0/' . $task['gid'];
		update_post_meta( $post_id, '_sfb_asana_gid', $task['gid'] );
		update_post_meta( $post_id, '_sfb_asana_url', esc_url_raw( $url ) );
		delete_post_meta( $post_id, '_sfb_asana_deleted' );
		sfb_update_meta(
			$post_id,
			'_sfb_asana_data',
			array(
				'completed' => 'resolved' === $f['status'],
				'synced_at' => 0, // Volgende keer openen haalt de rest op.
			)
		);

		$shot = sfb_screenshot_path( $post_id );
		if ( $shot ) {
			self::attach( $task['gid'], $shot ); // Mislukte bijlage is geen reden om te falen.
		}

		return array(
			'gid' => $task['gid'],
			'url' => $url,
		);
	}

	/**
	 * Voegt een bestand als bijlage toe aan een taak (multipart upload).
	 */
	public static function attach( $task_gid, $file ) {
		$boundary = 'sfb' . wp_generate_password( 24, false );
		$mime     = wp_check_filetype( $file )['type'];
		$mime     = $mime ? $mime : 'application/octet-stream';
		$name     = 'screenshot-' . basename( $file );

		$body  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"parent\"\r\n\r\n{$task_gid}\r\n";
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: {$mime}\r\n\r\n";
		$body .= file_get_contents( $file ) . "\r\n--{$boundary}--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions

		return self::parse(
			wp_remote_post(
				self::API . '/attachments',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . sfb_settings()['asana_token'],
						'Accept'        => 'application/json',
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
				)
			)
		);
	}

	/**
	 * Taak voltooien of heropenen.
	 */
	public static function set_completed( $post_id, $completed ) {
		$gid = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( ! $gid ) {
			return true;
		}
		$res = self::request( 'PUT', '/tasks/' . rawurlencode( $gid ), array( 'completed' => (bool) $completed ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data              = (array) get_post_meta( $post_id, '_sfb_asana_data', true );
		$data['completed'] = (bool) $completed;
		sfb_update_meta( $post_id, '_sfb_asana_data', $data );
		return true;
	}

	/**
	 * Plaatst een reactie op de taak (namens de eigenaar van het token, met de naam van de WP-gebruiker erbij).
	 */
	public static function add_comment( $post_id, $text ) {
		$gid = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( ! $gid ) {
			return new WP_Error( 'sfb_asana_none', 'Deze feedback heeft nog geen Asana-taak.', array( 'status' => 400 ) );
		}
		$user = wp_get_current_user();
		$res  = self::request(
			'POST',
			'/tasks/' . rawurlencode( $gid ) . '/stories',
			array( 'text' => ( $user->exists() ? $user->display_name . ' (via Site Feedback): ' : '' ) . $text )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::sync_task( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Asana → WordPress
	 * ------------------------------------------------------------------ */

	/**
	 * Haalt de actuele taakgegevens en reacties op en werkt het feedback-item bij.
	 * Asana is hierin leidend: een voltooide taak = opgeloste feedback.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_task( $post_id ) {
		$gid = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( ! $gid || ! sfb_settings()['asana_token'] ) {
			return new WP_Error( 'sfb_asana_none', 'Geen gekoppelde Asana-taak.', array( 'status' => 400 ) );
		}

		$task = self::request( 'GET', '/tasks/' . rawurlencode( $gid ) . '?opt_fields=' . self::TASK_FIELDS );
		if ( is_wp_error( $task ) ) {
			$err = $task->get_error_data();
			if ( isset( $err['http'] ) && 404 === (int) $err['http'] ) {
				self::remote_deleted( $post_id ); // Taak is in Asana verwijderd: feedback naar de prullenbak.
			}
			return $task;
		}

		$data = self::apply_task( $post_id, $task, true );

		$stories = self::request( 'GET', '/tasks/' . rawurlencode( $gid ) . '/stories?limit=100&opt_fields=resource_subtype,text,created_at,created_by.name' );
		if ( ! is_wp_error( $stories ) ) {
			$comments = array();
			foreach ( $stories as $story ) {
				if ( 'comment_added' === ( $story['resource_subtype'] ?? '' ) ) {
					$comments[] = array(
						'author' => (string) ( $story['created_by']['name'] ?? 'Asana' ),
						'text'   => (string) ( $story['text'] ?? '' ),
						'date'   => (string) ( $story['created_at'] ?? '' ),
					);
				}
			}
			sfb_update_meta( $post_id, '_sfb_asana_comments', array_slice( $comments, -50 ) );
		}

		return $data;
	}

	/**
	 * Verwerkt taakgegevens uit Asana in het feedback-item: voltooid in Asana = opgelost in WordPress.
	 *
	 * @param bool $full True als ook de reacties zijn opgehaald (dan telt dit als volledige sync).
	 */
	private static function apply_task( $post_id, $task, $full ) {
		$project = sfb_settings()['asana_project'];
		$section = '';
		foreach ( (array) ( $task['memberships'] ?? array() ) as $membership ) {
			if ( ( $membership['project']['gid'] ?? '' ) === $project && ! empty( $membership['section']['name'] ) ) {
				$section = $membership['section']['name'];
				break;
			}
		}

		$old  = (array) get_post_meta( $post_id, '_sfb_asana_data', true );
		$data = array(
			'completed' => ! empty( $task['completed'] ),
			'assignee'  => (string) ( $task['assignee']['name'] ?? '' ),
			'due_on'    => (string) ( $task['due_on'] ?? '' ),
			'section'   => $section,
			// Alleen een volledige sync (met reacties) telt als "bijgewerkt"; zo worden reacties bij openen nog opgehaald.
			'synced_at' => $full ? time() : (int) ( $old['synced_at'] ?? 0 ),
		);
		sfb_update_meta( $post_id, '_sfb_asana_data', $data );
		if ( ! empty( $task['permalink_url'] ) ) {
			update_post_meta( $post_id, '_sfb_asana_url', esc_url_raw( $task['permalink_url'] ) );
		}

		sfb_set_status( $post_id, $data['completed'] ? 'resolved' : 'open', false );
		return $data;
	}

	/**
	 * Haalt in één keer alle taken op die sinds de vorige keer in het project gewijzigd zijn
	 * (voltooid, heropend, toegewezen, verplaatst, ...) en verwerkt ze.
	 *
	 * Werkt ook op lokale sites (WordPress haalt zelf op) en is goedkoop: meestal 1 API-verzoek.
	 * Wordt aangeroepen bij het laden van de feedbacklijst, door de widget en door WP-cron.
	 *
	 * @param int $min_interval Minimaal aantal seconden tussen twee batch-syncs.
	 * @return int|WP_Error Aantal bijgewerkte feedback-items.
	 */
	public static function sync_changed( $min_interval = 15 ) {
		if ( ! self::is_configured() ) {
			return 0;
		}
		$last = (int) get_option( 'sfb_last_batch_sync', 0 );
		if ( $min_interval && time() - $last < $min_interval ) {
			return 0;
		}
		if ( get_transient( 'sfb_batch_lock' ) ) {
			return 0; // Er loopt al een sync (bijv. vanuit een ander tabblad).
		}
		set_transient( 'sfb_batch_lock', 1, 30 );

		$started = time();
		// Ruime overlap (2 min) zodat er niets tussen twee syncs door glipt; eerste keer: laatste 30 dagen.
		$since = $last ? $last - 120 : $started - 30 * DAY_IN_SECONDS;
		$path  = '/tasks?limit=100&project=' . rawurlencode( sfb_settings()['asana_project'] )
			. '&modified_since=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $since ) )
			. '&opt_fields=' . self::TASK_FIELDS;

		$updated = 0;
		$offset  = '';
		for ( $page = 0; $page < 10; $page++ ) {
			$json = self::request_page( $path . ( $offset ? '&offset=' . rawurlencode( $offset ) : '' ) );
			if ( is_wp_error( $json ) ) {
				delete_transient( 'sfb_batch_lock' );
				return $json; // sfb_last_batch_sync niet bijwerken: volgende keer opnieuw vanaf hetzelfde punt.
			}
			foreach ( (array) ( $json['data'] ?? array() ) as $task ) {
				$post_id = empty( $task['gid'] ) ? 0 : sfb_find_by_asana_gid( $task['gid'] );
				if ( $post_id ) {
					self::apply_task( $post_id, $task, false );
					$updated++;
				}
			}
			$offset = $json['next_page']['offset'] ?? '';
			if ( ! $offset ) {
				break;
			}
		}

		update_option( 'sfb_last_batch_sync', $started, false );

		// Verwijderde taken zie je niet in "gewijzigd sinds", dus apart controleren.
		$trashed = self::detect_deleted();
		delete_transient( 'sfb_batch_lock' );
		return $updated + ( is_wp_error( $trashed ) ? 0 : $trashed );
	}

	/* ---------------------------------------------------------------------
	 * Verwijderen, in twee richtingen
	 * ------------------------------------------------------------------ */

	const LINK_META = array( '_sfb_asana_gid', '_sfb_asana_url', '_sfb_asana_data', '_sfb_asana_comments', '_sfb_asana_error' );

	/**
	 * Hook "trashed_post": feedback naar de prullenbak in WordPress → taak verwijderen in Asana
	 * (daar belandt hij 30 dagen in de prullenbak van Asana).
	 */
	public static function on_trash( $post_id ) {
		if ( get_post_type( $post_id ) !== SFB_POST_TYPE ) {
			return;
		}
		$gid = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( $gid ) {
			self::delete_remote( $post_id, $gid );
		}
	}

	/**
	 * Hook "before_delete_post": definitief verwijderen zonder eerst de prullenbak (of een eerder mislukte poging).
	 */
	public static function on_delete( $post_id ) {
		if ( get_post_type( $post_id ) !== SFB_POST_TYPE ) {
			return;
		}
		$gid = get_post_meta( $post_id, '_sfb_asana_gid', true );
		if ( $gid ) {
			self::delete_remote( $post_id, $gid );
		}
	}

	/**
	 * Hook "untrashed_post": feedback teruggezet uit de prullenbak.
	 */
	public static function on_untrash( $post_id ) {
		if ( get_post_type( $post_id ) !== SFB_POST_TYPE ) {
			return;
		}
		if ( get_post_meta( $post_id, '_sfb_asana_delete_pending', true ) ) {
			// De taak in Asana is nooit verwijderd (dat mislukte): koppeling gewoon behouden.
			delete_post_meta( $post_id, '_sfb_asana_delete_pending' );
			return;
		}
		// De oude taak is weg (in de prullenbak van Asana): met een schone lei een nieuwe maken.
		foreach ( array_merge( self::LINK_META, array( '_sfb_asana_deleted', '_sfb_asana_trashed_gid' ) ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
		if ( self::is_configured() && ! empty( sfb_settings()['asana_auto'] ) ) {
			self::create_task( $post_id );
		}
	}

	/**
	 * Verwijdert de taak in Asana. Mislukt dat (bijv. geen verbinding), dan probeert WP-cron het later opnieuw.
	 *
	 * @return true|WP_Error
	 */
	private static function delete_remote( $post_id, $gid ) {
		$res = sfb_settings()['asana_token']
			? self::request( 'DELETE', '/tasks/' . rawurlencode( $gid ) )
			: new WP_Error( 'sfb_asana_config', 'Geen Asana-token ingesteld.' );

		$err = is_wp_error( $res ) ? (array) $res->get_error_data() : array();
		if ( is_wp_error( $res ) && 404 !== (int) ( $err['http'] ?? 0 ) ) {
			update_post_meta( $post_id, '_sfb_asana_delete_pending', $gid );
			return $res;
		}

		// Verwijderd (of was al weg): koppeling opruimen, maar onthouden welke taak het was.
		delete_post_meta( $post_id, '_sfb_asana_delete_pending' );
		update_post_meta( $post_id, '_sfb_asana_trashed_gid', $gid );
		foreach ( self::LINK_META as $key ) {
			delete_post_meta( $post_id, $key );
		}
		return true;
	}

	/**
	 * De taak is in Asana verwijderd: feedback naar de prullenbak in WordPress (30 dagen terug te zetten).
	 */
	private static function remote_deleted( $post_id ) {
		// Eerst de koppeling weghalen, zodat on_trash() niet nog eens probeert de taak in Asana te verwijderen.
		foreach ( self::LINK_META as $key ) {
			delete_post_meta( $post_id, $key );
		}
		update_post_meta( $post_id, '_sfb_asana_deleted', 1 );
		if ( 'trash' !== get_post_status( $post_id ) ) {
			wp_trash_post( $post_id );
		}
	}

	/**
	 * Zoekt feedback waarvan de taak in Asana verwijderd is.
	 *
	 * Vergelijkt de taken die nog in het project staan met de gekoppelde feedback. Een taak die
	 * ontbreekt, wordt apart nagevraagd: alleen bij "bestaat niet" (404) gaat de feedback naar de
	 * prullenbak. Een taak die naar een ander project is verplaatst, blijft dus gewoon gekoppeld.
	 * Lukt het ophalen van de lijst niet volledig, dan gebeurt er niets.
	 *
	 * @return int|WP_Error Aantal feedback-items dat naar de prullenbak is gegaan.
	 */
	public static function detect_deleted() {
		if ( ! self::is_configured() ) {
			return 0;
		}
		$linked = get_posts(
			array(
				'post_type'   => SFB_POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sfb_asana_gid',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( ! $linked ) {
			return 0;
		}

		// Alle taken in het project, ook voltooide (completed_since in het verleden).
		$path   = '/tasks?limit=100&project=' . rawurlencode( sfb_settings()['asana_project'] ) . '&completed_since=1970-01-01T00:00:00Z&opt_fields=gid';
		$seen   = array();
		$offset = '';
		$pages  = 0;
		do {
			if ( ++$pages > 50 ) {
				return 0; // Meer dan 5.000 taken: lijst niet compleet, dus niets als verwijderd beschouwen.
			}
			$json = self::request_page( $path . ( $offset ? '&offset=' . rawurlencode( $offset ) : '' ) );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			foreach ( (array) ( $json['data'] ?? array() ) as $task ) {
				$seen[ (string) ( $task['gid'] ?? '' ) ] = true;
			}
			$offset = $json['next_page']['offset'] ?? '';
		} while ( $offset );

		$trashed = 0;
		$checked = 0;
		foreach ( $linked as $post_id ) {
			$gid = (string) get_post_meta( $post_id, '_sfb_asana_gid', true );
			if ( isset( $seen[ $gid ] ) ) {
				continue;
			}
			if ( ++$checked > 10 ) {
				break; // De rest volgt bij de volgende sync.
			}
			$task = self::request( 'GET', '/tasks/' . rawurlencode( $gid ) . '?opt_fields=gid' );
			$err  = is_wp_error( $task ) ? (array) $task->get_error_data() : array();
			if ( is_wp_error( $task ) && 404 === (int) ( $err['http'] ?? 0 ) ) {
				self::remote_deleted( $post_id );
				$trashed++;
			}
		}
		return $trashed;
	}

	/**
	 * Verwijderingen die eerder mislukten (feedback al in de prullenbak) opnieuw proberen.
	 */
	public static function retry_pending_deletes() {
		$ids = get_posts(
			array(
				'post_type'   => SFB_POST_TYPE,
				'post_status' => 'trash',
				'numberposts' => 20,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sfb_asana_delete_pending',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $post_id ) {
			self::delete_remote( $post_id, get_post_meta( $post_id, '_sfb_asana_delete_pending', true ) );
		}
	}

	/**
	 * Zoals request(), maar geeft het hele antwoord terug (incl. next_page voor paginering).
	 */
	private static function request_page( $path ) {
		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => 'GET',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . sfb_settings()['asana_token'],
					'Accept'        => 'application/json',
				),
			)
		);
		$data = self::parse( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return (array) json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Alleen synchroniseren als de laatste sync ouder is dan $max_age seconden.
	 */
	public static function maybe_sync( $post_id, $max_age = 30 ) {
		if ( ! get_post_meta( $post_id, '_sfb_asana_gid', true ) ) {
			return;
		}
		$data = (array) get_post_meta( $post_id, '_sfb_asana_data', true );
		if ( empty( $data['synced_at'] ) || time() - (int) $data['synced_at'] > $max_age ) {
			self::sync_task( $post_id );
		}
	}

	/**
	 * WP-cron (elke 15 min): mislukte/ontbrekende taken aanmaken en open items bijwerken.
	 */
	public static function cron( $force = false ) {
		if ( ! self::is_configured() ) {
			return 0;
		}

		if ( ! empty( sfb_settings()['asana_auto'] ) ) {
			$missing = get_posts(
				array(
					'post_type'   => SFB_POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => 10,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_sfb_asana_gid',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_sfb_asana_deleted',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			foreach ( $missing as $id ) {
				self::create_task( $id );
			}
		}

		// Eerder mislukte verwijderingen in Asana opnieuw proberen.
		self::retry_pending_deletes();

		// Alle gewijzigde taken in één keer (status voltooid/open, toegewezen, sectie) + verwijderde taken.
		self::sync_changed( 0 );

		// Daarna per item de reacties bijwerken.
		$linked = get_posts(
			array(
				'post_type'   => SFB_POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $force ? 200 : 50,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sfb_asana_gid',
						'compare' => 'EXISTS',
					),
					array(
						'key'   => '_sfb_status',
						'value' => 'open',
					),
				),
			)
		);
		$count = 0;
		foreach ( $linked as $id ) {
			$data = (array) get_post_meta( $id, '_sfb_asana_data', true );
			if ( $force || empty( $data['synced_at'] ) || time() - (int) $data['synced_at'] > 10 * MINUTE_IN_SECONDS ) {
				$count += is_wp_error( self::sync_task( $id ) ) ? 0 : 1;
			}
		}
		update_option( 'sfb_last_sync', time(), false );
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Webhook (realtime updates vanuit Asana)
	 * ------------------------------------------------------------------ */

	public static function webhook_url() {
		return rest_url( 'site-feedback/v1/asana/webhook' );
	}

	public static function webhook() {
		return (array) get_option( self::HOOK_OPTION, array() );
	}

	/**
	 * Registreert een webhook op het gekozen project. Asana doet tijdens dit verzoek een
	 * "handshake" naar onze webhook-URL; die slaat het geheim op (zie handle_webhook()).
	 */
	public static function create_webhook() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'sfb_asana_config', 'Stel eerst een Asana-token en project in.' );
		}
		self::delete_webhook();

		set_transient( 'sfb_webhook_handshake', 1, 2 * MINUTE_IN_SECONDS );
		$res = self::request(
			'POST',
			'/webhooks',
			array(
				'resource' => sfb_settings()['asana_project'],
				'target'   => self::webhook_url(),
			)
		);
		delete_transient( 'sfb_webhook_handshake' );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'sfb_hook', $res->get_error_message() . ' — Is de site publiek bereikbaar via https (geen wachtwoord/maintenance-modus)?' );
		}

		// Het geheim is door een ánder PHP-proces (de handshake) opgeslagen: cache omzeilen.
		wp_cache_delete( self::HOOK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$hook = self::webhook();

		$hook['gid']     = $res['gid'];
		$hook['project'] = sfb_settings()['asana_project'];
		$hook['created'] = time();
		update_option( self::HOOK_OPTION, $hook, false );
		return $hook;
	}

	public static function delete_webhook( $token = null ) {
		$hook = self::webhook();
		if ( ! empty( $hook['gid'] ) ) {
			self::request( 'DELETE', '/webhooks/' . rawurlencode( $hook['gid'] ), null, $token ); // Best effort.
		}
		delete_option( self::HOOK_OPTION );
	}

	public static function handle_webhook( WP_REST_Request $request ) {
		// 1. Handshake bij het aanmaken van de webhook.
		$secret = $request->get_header( 'x-hook-secret' );
		if ( $secret ) {
			if ( ! get_transient( 'sfb_webhook_handshake' ) ) {
				return new WP_Error( 'sfb_hook', 'Onverwachte handshake.', array( 'status' => 403 ) );
			}
			update_option( self::HOOK_OPTION, array( 'secret' => $secret ), false );
			$response = new WP_REST_Response( null, 200 );
			$response->header( 'X-Hook-Secret', $secret );
			return $response;
		}

		// 2. Events: handtekening controleren.
		$hook      = self::webhook();
		$signature = (string) $request->get_header( 'x-hook-signature' );
		$body      = $request->get_body();
		if ( empty( $hook['secret'] ) || ! $signature || ! hash_equals( hash_hmac( 'sha256', $body, $hook['secret'] ), $signature ) ) {
			return new WP_Error( 'sfb_hook', 'Ongeldige handtekening.', array( 'status' => 401 ) );
		}

		$json = json_decode( $body, true );
		$gids = array();
		foreach ( (array) ( $json['events'] ?? array() ) as $event ) {
			if ( 'task' === ( $event['resource']['resource_type'] ?? '' ) ) {
				$gids[] = $event['resource']['gid'];
			} elseif ( 'task' === ( $event['parent']['resource_type'] ?? '' ) ) {
				$gids[] = $event['parent']['gid']; // Bijv. een nieuwe reactie (story) op een taak.
			}
		}

		foreach ( array_slice( array_unique( $gids ), 0, 10 ) as $gid ) {
			$post_id = sfb_find_by_asana_gid( $gid );
			if ( $post_id ) {
				self::sync_task( $post_id );
			}
		}

		update_option( 'sfb_last_webhook', time(), false );
		return new WP_REST_Response( null, 200 );
	}
}

/* -------------------------------------------------------------------------
 * Cron
 * ---------------------------------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['sfb_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Elke 15 minuten',
		);
		return $schedules;
	}
);

add_action( 'sfb_asana_cron', array( 'SFB_Asana', 'cron' ) );

// Verwijderen in WordPress ↔ verwijderen in Asana.
add_action( 'trashed_post', array( 'SFB_Asana', 'on_trash' ) );
add_action( 'untrashed_post', array( 'SFB_Asana', 'on_untrash' ) );
add_action( 'before_delete_post', array( 'SFB_Asana', 'on_delete' ) );

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'sfb_asana_cron' ) ) {
			wp_schedule_event( time() + 60, 'sfb_15min', 'sfb_asana_cron' );
		}
	}
);

// Ander project of token? Dan hoort de oude webhook niet meer te bestaan.
add_action(
	'update_option_sfb_settings',
	function ( $old, $new ) {
		$old = (array) $old;
		$new = (array) $new;
		if ( ( $old['asana_project'] ?? '' ) !== ( $new['asana_project'] ?? '' ) || ( $old['asana_token'] ?? '' ) !== ( $new['asana_token'] ?? '' ) ) {
			SFB_Asana::delete_webhook( $old['asana_token'] ?? null );
		}
	},
	10,
	2
);
