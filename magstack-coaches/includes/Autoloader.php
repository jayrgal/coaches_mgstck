<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

/** Minimal PSR-4 style autoloader: Magstack\Coaches\Foo\Bar => includes/Foo/Bar.php */
final class Autoloader {

	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $base_dir ) {
				if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
