<?php
/**
 * Plugin Name:       Site Feedback
 * Description:       Visuele feedbacktool voor beheerders (à la BugHerd): klik een element aan, beschrijf wat er mis is en de tool bewaart gebruiker, URL, element, muispositie en een screenshot. Met koppeling in twee richtingen met Asana.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       site-feedback
 */

defined( 'ABSPATH' ) || exit;

define( 'SFB_VERSION', '1.3.0' );
define( 'SFB_FILE', __FILE__ );
define( 'SFB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFB_URL', plugin_dir_url( __FILE__ ) );
define( 'SFB_POST_TYPE', 'sfb_feedback' );

require_once SFB_DIR . 'includes/helpers.php';
require_once SFB_DIR . 'includes/asana.php';
require_once SFB_DIR . 'includes/rest.php';

if ( is_admin() ) {
	require_once SFB_DIR . 'includes/admin.php';
}

/**
 * Eenmalige opruiming bij updaten naar 1.2: Shopify-/token-ondersteuning is verhuisd naar een losse Shopify-app.
 */
add_action(
	'admin_init',
	function () {
		if ( version_compare( (string) get_option( 'sfb_version', '0' ), '1.2.0', '>=' ) ) {
			return;
		}
		delete_metadata( 'user', 0, '_sfb_token_hash', '', true ); // Oude persoonlijke tokens ongeldig maken.
		$settings = get_option( 'sfb_settings' );
		if ( is_array( $settings ) && array_key_exists( 'allowed_origins', $settings ) ) {
			unset( $settings['allowed_origins'] );
			update_option( 'sfb_settings', $settings );
		}
		update_option( 'sfb_version', SFB_VERSION );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'sfb_asana_cron' );
		SFB_Asana::delete_webhook();
	}
);

/**
 * Feedback wordt opgeslagen als (niet-publiek) post type. Alleen beheerders mogen erbij.
 */
add_action( 'init', 'sfb_register_post_type' );
function sfb_register_post_type() {
	$cap = 'manage_options';

	register_post_type(
		SFB_POST_TYPE,
		array(
			'labels'        => array(
				'name'               => 'Feedback',
				'singular_name'      => 'Feedback',
				'menu_name'          => 'Feedback',
				'all_items'          => 'Alle feedback',
				'edit_item'          => 'Feedback bewerken',
				'search_items'       => 'Feedback zoeken',
				'not_found'          => 'Nog geen feedback. Open de website en klik op het feedback-icoon rechts.',
				'not_found_in_trash' => 'Geen feedback in de prullenbak.',
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'show_in_rest'  => false,
			'menu_position' => 26,
			'menu_icon'     => 'dashicons-format-chat',
			'supports'      => array( 'title', 'editor' ),
			'map_meta_cap'  => false,
			'capabilities'  => array(
				'edit_post'              => $cap,
				'read_post'              => $cap,
				'delete_post'            => $cap,
				'edit_posts'             => $cap,
				'edit_others_posts'      => $cap,
				'edit_published_posts'   => $cap,
				'delete_posts'           => $cap,
				'delete_others_posts'    => $cap,
				'delete_published_posts' => $cap,
				'publish_posts'          => $cap,
				'read_private_posts'     => $cap,
				'create_posts'           => 'do_not_allow', // Feedback maak je via de widget op de site.
			),
		)
	);
}

/**
 * Laad de widget op de front-end, alleen voor ingelogde beheerders.
 */
add_action( 'wp_enqueue_scripts', 'sfb_enqueue_widget' );
function sfb_enqueue_widget() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = sfb_settings();
	if ( empty( $settings['frontend'] ) ) {
		return;
	}

	// Niet tonen in de Customizer of in page builders.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( is_customize_preview() || isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) || isset( $_GET['bricks'] ) ) {
		return;
	}

	$user = wp_get_current_user();

	wp_enqueue_script( 'sfb-widget', SFB_URL . 'assets/widget.js', array(), SFB_VERSION, true );
	wp_add_inline_script(
		'sfb-widget',
		'window.SFB_CONFIG = ' . wp_json_encode(
			array(
				'api'    => untrailingslashit( rest_url( 'site-feedback/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'asana'  => SFB_Asana::is_configured(),
				'user'   => array(
					'name'   => $user->display_name,
					'avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
				),
			)
		) . ';',
		'before'
	);
}
