#!/usr/local/bin/php -q
<?php

define( 'MONGODB_HOST', 'localhost' );
define( 'MONGODB_PORT', 27018 );

// listen only on hostname interface
// define( 'LISTEN_HOST', trim( shell_exec( 'hostname -i' ) ) );
// listen on every interface (some php versions perfer NULL instead of 0)
define( 'LISTEN_HOST', 0 );
define( 'LISTEN_PORT', 8080 );

error_reporting( E_ALL ^ E_NOTICE );
set_time_limit( 0 );
ob_implicit_flush();

require_once( 'System/Daemon.php' );

function http_parse( $header ) {
	$return = array();
	$fields = explode( "\r\n", preg_replace( '/\x0D\x0A[\x09\x20]+/', ' ', $header ) );
	if( count( $fields ) > 0 ) {
		$type = explode( ' ', $fields[0] );
		$return['type'] = $type[0];
		$path = explode( '?', $type[1] );
		$return['path'] = $path[0];
		parse_str( $path[1], $return['query'] );
		$e = false;
		foreach( $fields as $field ) {
			if( $e ) { $return['data'] = $field; continue; }
			if( empty( $field ) ) { $e = true; continue; }
			if( preg_match( '/([^:]+): (.+)/m', $field, $match ) ) {
				$match[1] = preg_replace( '/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower( trim( $match[1] ) ) );
				if( isset( $return[$match[1]] ) ) {
					$return['header'][$match[1]] = array( $return[$match[1]], $match[2] );
				} else {
					$return['header'][$match[1]] = trim( $match[2] );
				}
			}
		}
	}
	return $return;
}

System_Daemon::setOptions( array( 
	'appName' => 'chimangodaemon', 
	'appDir' => dirname(__FILE__),
	'appDescription' => 'rest api for mongodb in php',
	'authorName' => 'sleepy.chimango',
	'authorEmail' => 'tobsn@php.net',
	'logLocation' => '/dev/null'
));

System_Daemon::setSigHandler( SIGTERM, 'sigterm' );
function sigterm( $signal ){ if( $signal === SIGTERM ) { System_Daemon::stop(); } }

// Spawn Deamon!
#System_Daemon::start();
	$connected = false;
	function mconnect() {
		global $mongo, $connected;
		if( $connected ) {
			return true;
		}
		else {
			$connected = false;
			$mongo = new Mongo( MONGODB_HOST.':'.MONGODB_PORT, array( 'timeout' => 2000 ) );
			if( $mongo->connect() ) {
				$connected = true;
				return true;
			}
		}
	}

	function msg( $socket, $buf ) {
		global $mongo, $connected;
		$request = http_parse( $buf );
		$struct = explode( '/', $request['path'] );
		$data = array();
		if( $request['type'] == 'POST' && count( $struct ) == 4 && mconnect() ) {
			$database = $mongo->selectDB( $struct[1] );
			$collection = $database->selectCollection( $struct[2] );
			$cursor = ( !empty( $request['data'] ) ) ? $collection->$struct[3]( json_decode( $request['data'], true ) ) : $collection->$struct[3]();
			if( $cursor != NULL ) {
				if( $cursor->count() == 1 ) {
					$data[] = $cursor->getNext();
				}
				elseif( $cursor->count() > 1 ) {
					while( $cursor->hasNext() ) {
						$d = $cursor->getNext();
						if( is_array( $d ) ) {
							$data[] = $d;
						}
						else {
							$data = $d;
							break;
						}
					}
				}
			}
			else {
				$data['error'] = 1;
			}
		} else {
			$data['error'] = 1;
		}
		$data = json_encode( $data );
		socket_write( $socket, $data );
	}

	if( ( $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP ) ) < 0 ) {
		echo "socket_create() failed, reason: " . socket_strerror($master) . "\n";
	}
	socket_set_option( $master, SOL_SOCKET,SO_REUSEADDR, 1 );
	if( ( $ret = socket_bind( $master, LISTEN_HOST, LISTEN_PORT ) ) < 0 ) {
		echo "socket_bind() failed, reason: " . socket_strerror($ret) . "\n";
	}
	if( ( $ret = socket_listen( $master, 5 ) ) < 0 ) {
		echo "socket_listen() failed, reason: " . socket_strerror($ret) . "\n";
	}
	$read_sockets = array( $master );

	while( true ) {
		$changed_sockets = $read_sockets;
		$num_changed_sockets = socket_select( $changed_sockets, $write = NULL, $except = NULL, NULL );
		foreach( $changed_sockets as $socket ) {
			if( $socket == $master ) {
				if( ( $client = socket_accept( $master ) ) < 0 ) {
					echo "socket_accept() failed: reason: " . socket_strerror($msgsock) . "\n";
					continue;
				}
				else {
					array_push( $read_sockets, $client );
				}
			}
			else {
				$bytes = socket_recv( $socket, $buffer, 1024, 0 );
				if( $bytes == 0 ) {
					$index = array_search( $socket, $read_sockets );
					unset( $read_sockets[$index] );
					socket_close( $socket );
				}
				else {
					msg( $socket, $buffer );
					$index = array_search( $socket, $read_sockets );
					unset( $read_sockets[$index] );
					socket_close( $socket );
				}
			}
		}
	}

System_Daemon::stop();

?>
