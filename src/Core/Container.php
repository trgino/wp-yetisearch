<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

final class Container {

	/** @var array<string, callable(self): mixed> */
	private array $factories = array();

	/** @var array<string, mixed> */
	private array $instances = array();

	/** @param callable(self): mixed $factory */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not output.
			throw new \InvalidArgumentException( sprintf( "Service '%s' is not registered.", $id ) );
		}

		$service                = ( $this->factories[ $id ] )( $this );
		$this->instances[ $id ] = $service;

		return $service;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
