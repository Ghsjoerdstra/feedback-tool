<?php
/**
 * Tests voor de Asana-koppeling, zonder WordPress: WP-functies en Asana-antwoorden worden nagebootst.
 *
 * Draaien:  php wordpress-plugin/tests/asana-sync-test.php
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$FAILS = 0;
define('ABSPATH', __DIR__); define('SFB_POST_TYPE','sfb_feedback'); define('MINUTE_IN_SECONDS',60); define('MB_IN_BYTES',1048576);
$OPT=['sfb_settings'=>['asana_token'=>'tok','asana_project'=>'P1','asana_auto'=>1]]; $META=[]; $TRANS=[]; $HTTP=[]; $CALLS=[];
class WP_Error{public $c,$m,$d;function __construct($c='',$m='',$d=[]){$this->c=$c;$this->m=$m;$this->d=$d;}function get_error_message(){return $this->m;}function get_error_data(){return $this->d;}}
class WP_REST_Response{public $h=[];public $s;function __construct($d=null,$s=200){$this->s=$s;}function header($k,$v){$this->h[$k]=$v;}}
class WP_REST_Request{public $hd=[];public $b='';function get_header($k){return $this->hd[$k]??null;}function get_body(){return $this->b;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(){} function add_filter(){} function wp_next_scheduled(){return 1;}
function get_option($k,$d=false){global $OPT;return $OPT[$k]??$d;} function update_option($k,$v){global $OPT;$OPT[$k]=$v;} function delete_option($k){global $OPT;unset($OPT[$k]);}
function get_transient($k){global $TRANS;return $TRANS[$k]??false;} function set_transient($k,$v){global $TRANS;$TRANS[$k]=$v;} function delete_transient($k){global $TRANS;unset($TRANS[$k]);}
function wp_cache_delete(){}
function get_post_meta($id,$k,$s=true){global $META;return $META[$id][$k]??'';} function update_post_meta($id,$k,$v){global $META;$META[$id][$k]=$v;} function delete_post_meta($id,$k){global $META;unset($META[$id][$k]);}
function wp_slash($v){return $v;} function esc_url_raw($u){return $u;} function wp_json_encode($x){return json_encode($x);} function rest_url($p){return 'https://site.nl/wp-json/'.$p;}
function wp_parse_args($a,$d){return array_merge($d,(array)$a);}
$STATUS=[]; $TRASHED=[];
function get_post_status($id){global $STATUS;return $STATUS[$id]??'publish';}
function get_post_type($id){return 'sfb_feedback';}
function wp_trash_post($id){global $STATUS,$TRASHED;$STATUS[$id]='trash';$TRASHED[]=$id;SFB_Asana::on_trash($id);}
function wp_untrash_post($id){global $STATUS;$STATUS[$id]='publish';SFB_Asana::on_untrash($id);}
// Nagebootste get_posts: post_status, meta_key/meta_value en meta_query (EXISTS / NOT EXISTS / =).
function get_posts($a){global $META;$out=[];foreach($META as $id=>$m){
  $st=get_post_status($id);$want=$a['post_status']??'publish';if($want!=='any'&&$want!==$st)continue;
  if(isset($a['meta_key'])&&(string)($m[$a['meta_key']]??'')!==(string)$a['meta_value'])continue;
  $keep=true;foreach(($a['meta_query']??[]) as $q){$has=isset($m[$q['key']])&&$m[$q['key']]!=='';$c=$q['compare']??'=';
    if(($c==='EXISTS'&&!$has)||($c==='NOT EXISTS'&&$has)||($c==='='&&($m[$q['key']]??null)!==$q['value']))$keep=false;}
  if($keep)$out[]=$id;if(($a['numberposts']??-1)>0&&count($out)>=$a['numberposts'])break;}return $out;}
function wp_remote_request($url,$args){global $HTTP,$CALLS;$CALLS[]=$args['method'].' '.$url.(isset($args['body'])?' '.$args['body']:'');foreach($HTTP as $pat=>$r){if(strpos($args['method'].' '.$url,$pat)!==false)return $r;}return ['code'=>500,'body'=>'{}'];}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
require __DIR__ . '/../includes/helpers.php'; require __DIR__ . '/../includes/asana.php';
function ok($c,$m){global $FAILS;if(!$c)$FAILS++;echo ($c?'PASS ':'FAIL ').$m."\n";}

// --- sync_task ---
$META[7]=['_sfb_asana_gid'=>'T7','_sfb_status'=>'open'];
$HTTP=['GET https://app.asana.com/api/1.0/tasks/T7?'=>['code'=>200,'body'=>json_encode(['data'=>['completed'=>true,'due_on'=>'2026-10-01','permalink_url'=>'https://app.asana.com/x','assignee'=>['name'=>'Sanne'],'memberships'=>[['project'=>['gid'=>'P9'],'section'=>['name'=>'Andere']],['project'=>['gid'=>'P1'],'section'=>['name'=>'In behandeling']]]]])],
 'GET https://app.asana.com/api/1.0/tasks/T7/stories'=>['code'=>200,'body'=>json_encode(['data'=>[['resource_subtype'=>'assigned','text'=>'x'],['resource_subtype'=>'comment_added','text'=>'Opgelost in v2','created_at'=>'2026-09-23T10:00:00Z','created_by'=>['name'=>'Sanne']]]])]];
$d=SFB_Asana::sync_task(7);
ok($d['assignee']==='Sanne' && $d['section']==='In behandeling' && $d['due_on']==='2026-10-01','taakgegevens + sectie van juiste project');
ok($META[7]['_sfb_status']==='resolved','voltooid in Asana -> opgelost in WP');
ok(count($META[7]['_sfb_asana_comments'])===1 && $META[7]['_sfb_asana_comments'][0]['text']==='Opgelost in v2','alleen echte reacties overgenomen');
ok(!array_filter($CALLS,fn($c)=>str_starts_with($c,'PUT')),'sync pusht niet terug naar Asana (geen lus)');

// --- set_status push ---
$CALLS=[]; $HTTP=['PUT https://app.asana.com/api/1.0/tasks/T7'=>['code'=>200,'body'=>'{"data":{}}']];
$r=sfb_set_status(7,'open');
ok($r===true && str_contains($CALLS[0]??'','"completed":false'),'heropenen in WP -> taak heropend in Asana');
$CALLS=[]; sfb_set_status(7,'open'); ok(!$CALLS,'geen API-call als status niet verandert');

// --- 404 ---
$HTTP=['GET https://app.asana.com/api/1.0/tasks/T7?'=>['code'=>404,'body'=>'{"errors":[{"message":"Not found"}]}']];
$r=SFB_Asana::sync_task(7);
ok(is_wp_error($r) && empty($META[7]['_sfb_asana_gid']) && $META[7]['_sfb_asana_deleted']==1,'taak verwijderd in Asana -> koppeling losgelaten');

// --- webhook ---
$req=new WP_REST_Request; $req->hd['x-hook-secret']='S3CR3T';
$r=SFB_Asana::handle_webhook($req); ok(is_wp_error($r),'handshake geweigerd als we er niet om vroegen');
set_transient('sfb_webhook_handshake',1);
$r=SFB_Asana::handle_webhook($req); ok($r->h['X-Hook-Secret']==='S3CR3T' && get_option('sfb_asana_webhook')['secret']==='S3CR3T','handshake beantwoord + geheim opgeslagen');
$META[8]=['_sfb_asana_gid'=>'T8','_sfb_status'=>'open'];
$body=json_encode(['events'=>[['action'=>'added','resource'=>['gid'=>'S1','resource_type'=>'story'],'parent'=>['gid'=>'T8','resource_type'=>'task']],['action'=>'changed','resource'=>['gid'=>'OTHER','resource_type'=>'task']]]]);
$req=new WP_REST_Request; $req->b=$body; $req->hd['x-hook-signature']='fout';
ok(is_wp_error(SFB_Asana::handle_webhook($req)),'ongeldige handtekening geweigerd');
$CALLS=[]; $HTTP=['GET https://app.asana.com/api/1.0/tasks/T8?'=>['code'=>200,'body'=>'{"data":{"completed":false,"assignee":null}}'],'GET https://app.asana.com/api/1.0/tasks/T8/stories'=>['code'=>200,'body'=>'{"data":[]}']];
$req->hd['x-hook-signature']=hash_hmac('sha256',$body,'S3CR3T');
$r=SFB_Asana::handle_webhook($req);
ok($r instanceof WP_REST_Response && count(array_filter($CALLS,fn($c)=>str_contains($c,'/tasks/T8?')))===1,'geldige webhook -> juiste taak gesynct (onbekende taak genegeerd)');
ok(($META[8]['_sfb_asana_data']['assignee']??'x')==='','taak zonder toegewezen persoon geeft geen fout');
// --- v1.1.1: diagnose ---
function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_get_environment_type(){return 'production';}
$HOME='http://yugo.local'; function home_url(){global $HOME;return $HOME;}
ok(sfb_is_local_site(),'yugo.local herkend als lokaal');
$HOME='http://192.168.1.20'; ok(sfb_is_local_site(),'192.168.x herkend als lokaal');
$HOME='https://yugo.nl'; ok(!sfb_is_local_site(),'yugo.nl is niet lokaal');
ok(str_contains(SFB_Asana::explain(new WP_Error('x','cURL error 60: SSL certificate problem')),'certificaat'),'SSL-fout krijgt uitleg');
ok(str_contains(SFB_Asana::explain(new WP_Error('x','Asana: Not Authorized',['http'=>401])),'token'),'401 krijgt uitleg over token');
$OPT['sfb_settings']['asana_project']='';
$HTTP=['GET https://app.asana.com/api/1.0/users/me'=>['code'=>200,'body'=>'{"data":{"name":"Geert","email":"g@x.nl"}}']];
$t=SFB_Asana::test_connection(); ok(!$t['ok'] && str_contains(implode(' ',$t['lines']),'geen project'),'test meldt ontbrekend project');
$OPT['sfb_settings']['asana_project']='P1';
$HTTP['GET https://app.asana.com/api/1.0/projects/P1']=['code'=>200,'body'=>'{"data":{"name":"Website"}}'];
$t=SFB_Asana::test_connection(); ok($t['ok'] && str_contains(implode(' ',$t['lines']),'"Website"'),'test ok met projectnaam');
// --- v1.3: batch-sync ("done" in Asana -> opgelost in WP) ---
define('DAY_IN_SECONDS',86400);
// Twee gekoppelde items, beide open; één taak staat nu op done in Asana, één is heropend.
$META=[21=>['_sfb_asana_gid'=>'A21','_sfb_status'=>'open','_sfb_asana_data'=>['synced_at'=>111]],22=>['_sfb_asana_gid'=>'A22','_sfb_status'=>'resolved']];
unset($OPT['sfb_last_batch_sync']); $TRANS=[]; $CALLS=[];
$HTTP=['GET https://app.asana.com/api/1.0/tasks?limit=100&project=P1'=>['code'=>200,'body'=>json_encode(['data'=>[
  ['gid'=>'A21','completed'=>true,'assignee'=>['name'=>'Sanne']],
  ['gid'=>'A22','completed'=>false],
  ['gid'=>'ONBEKEND','completed'=>true]],'next_page'=>null])]];
$n=SFB_Asana::sync_changed(15);
ok($n===2,'batch: 2 gekoppelde taken verwerkt, onbekende genegeerd');
ok($META[21]['_sfb_status']==='resolved','done in Asana -> opgelost in WordPress');
ok($META[22]['_sfb_status']==='open','heropend in Asana -> weer open in WordPress');
ok($META[21]['_sfb_asana_data']['assignee']==='Sanne' && $META[21]['_sfb_asana_data']['synced_at']===111,'toegewezen bijgewerkt, reacties-sync tijdstip ongemoeid');
ok(count(array_filter($CALLS,fn($c)=>str_contains($c,'modified_since=')))===1 && str_contains($CALLS[0],'modified_since='),'één API-verzoek met modified_since');
ok(!array_filter($CALLS,fn($c)=>str_starts_with($c,'PUT')),'batch pusht niets terug naar Asana (geen lus)');
$CALLS=[]; SFB_Asana::sync_changed(15); ok(!$CALLS,'binnen 15 sec niet opnieuw (throttle)');
// Volgende sync gebruikt de vorige tijd min 2 min overlap
$OPT['sfb_last_batch_sync']=time()-60; $CALLS=[];
SFB_Asana::sync_changed(15);
preg_match('/modified_since=([^&]+)/',$CALLS[0],$mm); $since=strtotime(urldecode($mm[1]));
ok(abs($since-(time()-180))<=2,'volgende sync overlapt 2 minuten');
// Paginering
$OPT['sfb_last_batch_sync']=0; $CALLS=[]; $META[23]=['_sfb_asana_gid'=>'A23','_sfb_status'=>'open'];
$HTTP=['&offset=XYZ'=>['code'=>200,'body'=>json_encode(['data'=>[['gid'=>'A23','completed'=>true]],'next_page'=>null])],
 'GET https://app.asana.com/api/1.0/tasks?limit=100&project=P1'=>['code'=>200,'body'=>json_encode(['data'=>[],'next_page'=>['offset'=>'XYZ']])]];
SFB_Asana::sync_changed(0); ok($META[23]['_sfb_status']==='resolved' && count(array_filter($CALLS,fn($c)=>str_contains($c,'modified_since=')))===2,'paginering: taak op pagina 2 ook verwerkt');
// Fout -> tijdstip niet opschuiven
$OPT['sfb_last_batch_sync']=12345; $HTTP=[];
$r=SFB_Asana::sync_changed(0); ok(is_wp_error($r) && $OPT['sfb_last_batch_sync']===12345 && !get_transient('sfb_batch_lock'),'bij fout: volgende keer opnieuw vanaf zelfde punt, lock vrijgegeven');

// --- v1.5: verwijderen in twee richtingen ---
$OPT['sfb_settings']['asana_project'] = 'P1';
$OPT['sfb_settings']['asana_auto']    = 0;
$T = 'https://app.asana.com/api/1.0/tasks';

// WordPress -> Asana: feedback naar prullenbak => taak verwijderd in Asana.
$META = [ 41 => [ '_sfb_asana_gid' => 'D41', '_sfb_status' => 'open', '_sfb_asana_url' => 'u' ] ]; $STATUS = []; $TRASHED = []; $CALLS = [];
$HTTP = [ "DELETE $T/D41" => [ 'code' => 200, 'body' => '{"data":{}}' ] ];
wp_trash_post( 41 );
ok( in_array( "DELETE $T/D41", $CALLS, true ), 'WP prullenbak -> DELETE-verzoek naar Asana' );
ok( empty( $META[41]['_sfb_asana_gid'] ) && $META[41]['_sfb_asana_trashed_gid'] === 'D41' && empty( $META[41]['_sfb_asana_delete_pending'] ), 'koppeling opgeruimd, verwijderde taak onthouden' );

// Taak was al weg in Asana (404) => telt als gelukt.
$META[42] = [ '_sfb_asana_gid' => 'D42' ];
$HTTP     = [ "DELETE $T/D42" => [ 'code' => 404, 'body' => '{"errors":[{"message":"Not found"}]}' ] ];
wp_trash_post( 42 );
ok( empty( $META[42]['_sfb_asana_delete_pending'] ) && $META[42]['_sfb_asana_trashed_gid'] === 'D42', 'taak al weg in Asana (404): geen fout' );

// Geen verbinding => later opnieuw via cron; koppeling blijft bewaard.
$META[43] = [ '_sfb_asana_gid' => 'D43' ];
$HTTP     = [];
wp_trash_post( 43 );
ok( $META[43]['_sfb_asana_delete_pending'] === 'D43' && $META[43]['_sfb_asana_gid'] === 'D43', 'Asana onbereikbaar: verwijdering staat klaar om opnieuw te proberen' );
$HTTP = [ "DELETE $T/D43" => [ 'code' => 200, 'body' => '{"data":{}}' ] ];
SFB_Asana::retry_pending_deletes();
ok( empty( $META[43]['_sfb_asana_delete_pending'] ) && empty( $META[43]['_sfb_asana_gid'] ), 'cron: tweede poging slaagt, klaar' );

// Terugzetten uit WP-prullenbak nadat verwijderen mislukte: koppeling blijft intact.
$META[44] = [ '_sfb_asana_gid' => 'D44' ];
$HTTP     = [];
wp_trash_post( 44 );
wp_untrash_post( 44 );
ok( $META[44]['_sfb_asana_gid'] === 'D44' && empty( $META[44]['_sfb_asana_delete_pending'] ), 'terugzetten na mislukte verwijdering: zelfde taak blijft gekoppeld' );

// Terugzetten nadat de taak echt verwijderd is: schone lei (nieuwe taak mogelijk).
wp_untrash_post( 41 );
ok( empty( $META[41]['_sfb_asana_trashed_gid'] ) && empty( $META[41]['_sfb_asana_gid'] ) && get_post_status( 41 ) === 'publish', 'terugzetten na verwijdering: oude koppeling gewist, feedback weer gepubliceerd' );

// Asana -> WordPress: taak verwijderd => feedback naar de prullenbak, zonder DELETE terug (geen lus).
$META = [
	51 => [ '_sfb_asana_gid' => 'E51', '_sfb_status' => 'open' ],
	52 => [ '_sfb_asana_gid' => 'E52', '_sfb_status' => 'open' ],
	53 => [ '_sfb_asana_gid' => 'E53', '_sfb_status' => 'open' ],
];
$STATUS = []; $TRASHED = []; $CALLS = [];
$HTTP   = [
	"GET $T?limit=100&project=P1&completed_since" => [ 'code' => 200, 'body' => json_encode( [ 'data' => [ [ 'gid' => 'E51' ] ], 'next_page' => null ] ) ],
	"GET $T/E52?" => [ 'code' => 404, 'body' => '{"errors":[{"message":"Not found"}]}' ], // echt verwijderd
	"GET $T/E53?" => [ 'code' => 200, 'body' => '{"data":{"gid":"E53"}}' ],               // verplaatst naar ander project
];
$n = SFB_Asana::detect_deleted();
ok( $n === 1 && $TRASHED === [ 52 ], 'verwijderd in Asana -> alleen die feedback naar de prullenbak' );
ok( get_post_status( 53 ) === 'publish' && $META[53]['_sfb_asana_gid'] === 'E53', 'taak verplaatst naar ander project: feedback blijft staan' );
ok( $META[52]['_sfb_asana_deleted'] == 1 && empty( $META[52]['_sfb_asana_gid'] ), 'prullenbak-item gemarkeerd als "verwijderd in Asana"' );
ok( ! array_filter( $CALLS, fn( $c ) => str_starts_with( $c, 'DELETE' ) ), 'geen DELETE terug naar Asana (geen lus)' );

// Lijst ophalen mislukt => niets verwijderen.
$STATUS = []; $TRASHED = [];
$HTTP   = [ "GET $T" => [ 'code' => 500, 'body' => '{}' ] ];
$r      = SFB_Asana::detect_deleted();
ok( is_wp_error( $r ) && $TRASHED === [], 'lijst uit Asana mislukt: niets naar de prullenbak (veilig)' );

// Paginering: taak op pagina 2 telt als bestaand.
$STATUS = []; $TRASHED = [];
$META   = [ 61 => [ '_sfb_asana_gid' => 'F61', '_sfb_status' => 'open' ] ];
$HTTP   = [
	'&offset=P2' => [ 'code' => 200, 'body' => json_encode( [ 'data' => [ [ 'gid' => 'F61' ] ], 'next_page' => null ] ) ],
	"GET $T?limit=100&project=P1&completed_since" => [ 'code' => 200, 'body' => json_encode( [ 'data' => [ [ 'gid' => 'X' ] ], 'next_page' => [ 'offset' => 'P2' ] ] ) ],
	"GET $T/F61?" => [ 'code' => 404, 'body' => '{}' ],
];
SFB_Asana::detect_deleted();
ok( $TRASHED === [], 'taak op pagina 2 van de lijst: niet als verwijderd gezien' );

// Webhook / item openen: sync_task krijgt 404 => ook naar de prullenbak.
$STATUS = []; $TRASHED = [];
$META   = [ 71 => [ '_sfb_asana_gid' => 'G71', '_sfb_status' => 'open' ] ];
$HTTP   = [ "GET $T/G71?" => [ 'code' => 404, 'body' => '{}' ] ];
SFB_Asana::sync_task( 71 );
ok( $TRASHED === [ 71 ], 'webhook of openen van item: verwijderde taak -> prullenbak' );

// Batch-sync (lijst openen) roept de controle op verwijderde taken aan.
$STATUS = []; $TRASHED = []; $TRANS = []; $OPT['sfb_last_batch_sync'] = 0;
$META   = [ 81 => [ '_sfb_asana_gid' => 'H81', '_sfb_status' => 'open' ] ];
$HTTP   = [
	"GET $T?limit=100&project=P1&modified_since" => [ 'code' => 200, 'body' => '{"data":[],"next_page":null}' ],
	"GET $T?limit=100&project=P1&completed_since" => [ 'code' => 200, 'body' => '{"data":[],"next_page":null}' ],
	"GET $T/H81?" => [ 'code' => 404, 'body' => '{}' ],
];
SFB_Asana::sync_changed( 0 );
ok( $TRASHED === [ 81 ] && ! get_transient( 'sfb_batch_lock' ), 'lijst openen (batch-sync) pikt verwijderde taak direct op' );

echo $FAILS ? "
$FAILS test(s) mislukt
" : "
Alle tests geslaagd
";
exit( $FAILS ? 1 : 0 );
