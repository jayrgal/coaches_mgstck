<?php
namespace Magstack\Coaches\Payments;

use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string,GatewayInterface>|null */
	private static ?array $gateways = null;

	public static function hooks(): void {
		// Registry is lazy; nothing to hook in Phase 1.
	}

	/** @return array<string,GatewayInterface> */
	public static function all(): array {
		if ( null === self::$gateways ) {
			$list = array( new ManualGateway() );
			/**
			 * Register additional payment gateways.
			 *
			 * @param GatewayInterface[] $list
			 */
			$list = apply_filters( 'magstack_payment_gateways', $list );
			self::$gateways = array();
			foreach ( $list as $g ) {
				if ( $g instanceof GatewayInterface ) {
					self::$gateways[ $g->id() ] = $g;
				}
			}
		}
		return self::$gateways;
	}

	public static function get( string $id ): ?GatewayInterface {
		return self::all()[ $id ] ?? null;
	}

	public static function active(): GatewayInterface {
		$g = self::get( (string) Settings::get( 'payment_gateway', 'manual' ) );
		if ( ! $g || ! $g->is_available() ) {
			$g = self::get( 'manual' );
		}
		return $g;
	}
}
