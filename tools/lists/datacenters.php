<?php
/**
 * Builds plugin/foxfire-studio/data/datacenters.bin (1.24.0) from X4BNet's
 * lists_vpn "datacenter" list (MIT), with the same code the site uses for
 * its monthly download (includes/netlist.php).
 *
 *   php tools/lists/datacenters.php <lists_vpn checkout>
 *
 * A shallow clone of https://github.com/X4BNet/lists_vpn is enough.
 */
if ( 2 !== $argc ) {
	fwrite( STDERR, "usage: php tools/lists/datacenters.php <lists_vpn checkout>\n" );
	exit( 2 );
}
define( 'ABSPATH', '/' );
function add_action() {}
function apply_filters( $n, $v ) { return $v; }
function __( $s ) { return $s; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$root = dirname( __DIR__, 2 ) . '/plugin/foxfire-studio/';
require $root . 'includes/netlist.php';
$lines = array();
foreach ( array( 'ipv4.txt', 'ipv6.txt' ) as $f ) {
	$lines = array_merge( $lines, file( $argv[1] . '/output/datacenter/' . $f, FILE_IGNORE_NEW_LINES ) );
}
$n = rrt_net_convert( $lines, $root . 'data/datacenters.bin' );
if ( is_wp_error( $n ) ) {
	fwrite( STDERR, $n->m . "\n" );
	exit( 1 );
}
$date = trim( shell_exec( 'git -C ' . escapeshellarg( $argv[1] ) . ' log -1 --format=%cs' ) );
file_put_contents( $root . 'data/datacenters-date.txt', $date . "\n" );
$lic = file_get_contents( $argv[1] . '/README.md' );
$at  = strpos( $lic, '# License' );
file_put_contents( $root . 'data/datacenters-LICENSE.txt', "datacenters.bin is made from X4BNet lists_vpn (https://github.com/X4BNet/lists_vpn), output/datacenter, edition $date.\n\nThe ranges come from the IPLocate IP to ASN database: IP address data powered by IPLocate.io (https://www.iplocate.io), licensed under CC BY-SA 4.0 (https://creativecommons.org/licenses/by-sa/4.0/). The lists_vpn scripts, curated inputs and generated lists are under the MIT License:\n\n" . trim( false === $at ? $lic : substr( $lic, $at ) ) . "\n" );
echo "IPv4 {$n['v4']}, IPv6 {$n['v6']} ranges, " . filesize( $root . 'data/datacenters.bin' ) . " bytes, edition $date\n";
