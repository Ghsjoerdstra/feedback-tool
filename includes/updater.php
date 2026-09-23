<?php
/**
 * Updates via GitHub-releases.
 *
 * WordPress kijkt bij de gewone update-check (twee keer per dag, of via Dashboard → Updates)
 * naar de laatste release in de GitHub-repo. Is die nieuwer, dan verschijnt de update onder
 * Plugins, net als bij plugins uit de officiële bibliotheek. Automatische updates aanzetten kan ook.
 *
 * Werkt via de "Update URI"-header in site-feedback.php (WordPress 5.8+). Die zorgt er ook voor
 * dat WordPress nooit een plugin met dezelfde naam uit de officiële bibliotheek over deze heen zet.
 */

defined( 'ABSPATH' ) || exit;

class SFB_Updater {

	const REPO      = 'Ghsjoerdstra/feedback-tool';
	const SLUG      = 'site-feedback';
	const ASSET     = 'site-feedback.zip';
	const CACHE_KEY = 'sfb_update_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function basename() {
		return plugin_basename( SFB_FILE );
	}

	/**
	 * Een git-checkout (ontwikkelomgeving) updaten we niet via een zip: dat zou .git overschrijven.
	 */
	public static function is_git_checkout() {
		return is_dir( SFB_DIR . '.git' );
	}

	/**
	 * Laatste release van GitHub (6 uur gecachet; bij een fout 30 minuten).
	 *
	 * @return array|null [version, package, url, notes, date]
	 */
	public static function latest_release( $force = false ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['release'];
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'site-feedback-wordpress-plugin',
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		set_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
		return $release;
	}

	/**
	 * Haalt versie en zip-URL uit het GitHub-antwoord. Zonder site-feedback.zip als bijlage: geen update.
	 */
	public static function parse_release( $json ) {
		if ( ! is_array( $json ) || empty( $json['tag_name'] ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
			return null;
		}
		$package = '';
		foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
			if ( ( $asset['name'] ?? '' ) === self::ASSET && ! empty( $asset['browser_download_url'] ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
		$version = ltrim( (string) $json['tag_name'], 'vV' );
		if ( ! $package || ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			return null;
		}
		return array(
			'version' => $version,
			'package' => $package,
			'url'     => (string) ( $json['html_url'] ?? 'https://github.com/' . self::REPO ),
			'notes'   => (string) ( $json['body'] ?? '' ),
			'date'    => (string) ( $json['published_at'] ?? '' ),
		);
	}

	/**
	 * Filter "update_plugins_github.com": WordPress vergelijkt de versie zelf en toont de update als die nieuwer is.
	 */
	public static function check_update( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file || self::is_git_checkout() ) {
			return $update;
		}
		// "Opnieuw controleren" in Dashboard → Updates: cache overslaan (max. 1x per minuut).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force = ! empty( $_GET['force-check'] ) && ! get_transient( 'sfb_update_forced' );
		if ( $force ) {
			set_transient( 'sfb_update_forced', 1, MINUTE_IN_SECONDS );
		}

		$release = self::latest_release( $force );
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		);
	}

	/**
	 * Venster "Details bekijken" onder Plugins.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		$notes   = $release && $release['notes'] ? $release['notes'] : 'Geen release-notities.';

		return (object) array(
			'name'          => 'Site Feedback',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : SFB_VERSION,
			'author'        => 'Ghsjoerdstra',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['date'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => '<p>Visuele feedbacktool voor beheerders: klik een element aan, beschrijf wat er mis is en de tool bewaart gebruiker, URL, element, muispositie en een screenshot. Met een koppeling in twee richtingen met Asana.</p>',
				'changelog'   => wpautop( esc_html( $notes ) ),
			),
		);
	}

	/**
	 * De zip bevat de map "site-feedback/". Staat de plugin in een andere map (bijv. "feedback-tool-main"),
	 * dan hernoemen we de uitgepakte map, zodat WordPress de bestaande plugin vervangt in plaats van een tweede te installeren.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'sfb_update_folder', 'Kon de map van de update niet hernoemen.' );
	}

	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file ) {
			return $links;
		}
		$links[] = '<a href="https://github.com/' . self::REPO . '" target="_blank">GitHub</a>';
		if ( self::is_git_checkout() ) {
			$links[] = '<span>Git-installatie: bijwerken met <code>git pull</code></span>';
		} elseif ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'update-core.php?force-check=1' ) ) . '">Controleer op updates</a>';
		}
		return $links;
	}
}

SFB_Updater::init();
