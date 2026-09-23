<?php
/**
 * Tests voor de GitHub-updater, zonder WordPress: WP-functies en GitHub-antwoorden worden nagebootst.
 *
 * Draaien:  php tests/updater-test.php
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$FAILS = 0;
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SFB_VERSION', '1.4.0' );
$PLUGIN_DIR = sys_get_temp_dir() . '/sfb-updater-test-' . getmypid() . '/site-feedback/';
@mkdir( $PLUGIN_DIR, 0777, true );
define( 'SFB_DIR', $PLUGIN_DIR );
define( 'SFB_FILE', $PLUGIN_DIR . 'site-feedback.php' );

$TRANS = array(); $HTTP = null; $CALLS = 0; $FILTERS = array();
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_filter( $h, $cb ) { global $FILTERS; $FILTERS[ $h ] = $cb; }
function add_action( $h, $cb ) { add_filter( $h, $cb ); }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function get_transient( $k ) { global $TRANS; return $TRANS[ $k ] ?? false; }
function set_transient( $k, $v ) { global $TRANS; $TRANS[ $k ] = $v; }
function delete_transient( $k ) { global $TRANS; unset( $TRANS[ $k ] ); }
function wp_remote_get( $url ) { global $HTTP, $CALLS; $CALLS++; return $HTTP; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function esc_url( $s ) { return $s; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
function current_user_can() { return true; }

require __DIR__ . '/../includes/updater.php';

function ok( $c, $m ) { global $FAILS; if ( ! $c ) { $FAILS++; } echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; }
function release( $tag, $asset = 'site-feedback.zip', $extra = array() ) {
	return array( 'code' => 200, 'body' => json_encode( array_merge( array(
		'tag_name' => $tag, 'html_url' => 'https://github.com/x/releases/' . $tag, 'body' => 'Notities', 'published_at' => '2026-09-23T10:00:00Z',
		'assets'   => array( array( 'name' => $asset, 'browser_download_url' => 'https://github.com/x/download/' . $tag . '/' . $asset ) ),
	), $extra ) ) );
}
$FILE = 'site-feedback/site-feedback.php';

ok( isset( $FILTERS['update_plugins_github.com'] ), 'filter voor Update URI (github.com) geregistreerd' );

// Nieuwere release -> update-info met zip uit de release.
$HTTP = release( 'v1.5.0' );
$u = SFB_Updater::check_update( false, array( 'RequiresPHP' => '7.4' ), $FILE );
ok( $u['version'] === '1.5.0' && str_ends_with( $u['package'], '/v1.5.0/site-feedback.zip' ) && $u['slug'] === 'site-feedback', 'nieuwere release: versie + zip-URL doorgegeven' );

// Cache: tweede check doet geen nieuw verzoek.
$CALLS = 0; SFB_Updater::check_update( false, array(), $FILE );
ok( $CALLS === 0, 'resultaat 6 uur gecachet (geen extra GitHub-verzoek)' );

// Andere plugin met github.com Update URI wordt niet aangeraakt.
ok( SFB_Updater::check_update( 'ongewijzigd', array(), 'andere-plugin/andere.php' ) === 'ongewijzigd', 'andere plugins met github.com-Update URI ongemoeid' );

// Release zonder site-feedback.zip -> geen update (anders zou WP de broncode-zip installeren).
ok( SFB_Updater::parse_release( json_decode( release( 'v2.0.0', 'iets-anders.zip' )['body'], true ) ) === null, 'release zonder site-feedback.zip wordt genegeerd' );
ok( SFB_Updater::parse_release( json_decode( release( 'v2.0.0', 'site-feedback.zip', array( 'prerelease' => true ) )['body'], true ) ) === null, 'pre-release wordt genegeerd' );
ok( SFB_Updater::parse_release( json_decode( release( 'nightly' )['body'], true ) ) === null, 'tag zonder versienummer wordt genegeerd' );

// GitHub onbereikbaar -> geen update, kort gecachet.
SFB_Updater::clear_cache(); $HTTP = array( 'code' => 403, 'body' => '{"message":"rate limit"}' );
ok( SFB_Updater::check_update( false, array(), $FILE ) === false, 'GitHub-fout (bijv. rate limit): geen update, geen crash' );

// Force-check vanuit Dashboard -> Updates slaat de cache over (max. 1x per minuut).
$HTTP = release( 'v1.6.0' ); $_GET['force-check'] = 1; $CALLS = 0;
$u = SFB_Updater::check_update( false, array(), $FILE );
ok( $CALLS === 1 && $u['version'] === '1.6.0', '"Opnieuw controleren" haalt direct de nieuwste release op' );
$CALLS = 0; SFB_Updater::check_update( false, array(), $FILE );
ok( $CALLS === 0, 'force-check hooguit 1x per minuut' );
unset( $_GET['force-check'] );

// Details-venster.
$info = SFB_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'site-feedback' ) );
ok( $info->version === '1.6.0' && str_contains( $info->sections['changelog'], 'Notities' ), '"Details bekijken" toont versie en release-notities' );
ok( SFB_Updater::plugin_info( 'x', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) === 'x', 'details van andere plugins ongemoeid' );

// Git-checkout: nooit via zip updaten (zou .git wissen).
mkdir( $PLUGIN_DIR . '.git' );
ok( SFB_Updater::check_update( false, array(), $FILE ) === false, 'git-checkout krijgt geen zip-update' );
rmdir( $PLUGIN_DIR . '.git' );

// Mapnaam: zip bevat site-feedback/, plugin staat in feedback-tool-main/ -> uitgepakte map hernoemen.
class FakeFS { public $moved; function move( $a, $b ) { $this->moved = array( $a, $b ); return true; } }
$GLOBALS['wp_filesystem'] = new FakeFS();
$r = SFB_Updater::fix_folder_name( '/tmp/upgrade/abc/site-feedback/', '/tmp/upgrade/abc', null, array( 'plugin' => $FILE ) );
ok( $r === '/tmp/upgrade/abc/site-feedback/' && ! $GLOBALS['wp_filesystem']->moved, 'zelfde mapnaam: niets hernoemen' );
$r = SFB_Updater::fix_folder_name( '/tmp/upgrade/abc/feedback-tool-main/', '/tmp/upgrade/abc', null, array( 'plugin' => $FILE ) );
ok( $r === '/tmp/upgrade/abc/site-feedback/' && $GLOBALS['wp_filesystem']->moved[1] === '/tmp/upgrade/abc/site-feedback/', 'andere mapnaam in zip: hernoemd naar de map van de geïnstalleerde plugin' );
$GLOBALS['wp_filesystem']->moved = null;
$r = SFB_Updater::fix_folder_name( '/tmp/x/iets/', '/tmp/x', null, array( 'plugin' => 'andere/andere.php' ) );
ok( $r === '/tmp/x/iets/' && ! $GLOBALS['wp_filesystem']->moved, 'updates van andere plugins ongemoeid' );

@rmdir( $PLUGIN_DIR );
@rmdir( dirname( $PLUGIN_DIR ) );

echo $FAILS ? "\n$FAILS test(s) mislukt\n" : "\nAlle tests geslaagd\n";
exit( $FAILS ? 1 : 0 );
