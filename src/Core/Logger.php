<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** PSR-3 logger writing single lines to error_log (spec §2: fail-safe, observable). */
final class Logger extends AbstractLogger {

	private const SEVERITY = array(
		LogLevel::EMERGENCY => 0,
		LogLevel::ALERT     => 1,
		LogLevel::CRITICAL  => 2,
		LogLevel::ERROR     => 3,
		LogLevel::WARNING   => 4,
		LogLevel::NOTICE    => 5,
		LogLevel::INFO      => 6,
		LogLevel::DEBUG     => 7,
	);

	/** @var \Closure(string): void */
	private \Closure $writer;

	public function __construct( private string $minLevel = LogLevel::WARNING, ?callable $writer = null, private ?FileLog $fileLog = null ) {
		$this->writer = $writer !== null
			? \Closure::fromCallable( $writer )
			: static function ( string $line ): void {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort sink when no FileLog is attached.
				error_log( $line );
			};
	}

	public function withFileLog( FileLog $fileLog ): self {
		$clone          = clone $this;
		$clone->fileLog = $fileLog;
		return $clone;
	}

	public static function fromEnvironment(): self {
		$debug = defined( 'YETISEARCH_DEBUG' ) && (bool) constant( 'YETISEARCH_DEBUG' );
		return new self( $debug ? LogLevel::DEBUG : LogLevel::WARNING );
	}

	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		$level = is_string( $level ) ? $level : LogLevel::DEBUG;
		if ( ( self::SEVERITY[ $level ] ?? 7 ) > ( self::SEVERITY[ $this->minLevel ] ?? 4 ) ) {
			return;
		}

		foreach ( $context as $key => $value ) {
			if ( $value instanceof \Throwable ) {
				$context[ $key ] = get_class( $value ) . ': ' . $value->getMessage();
			}
		}

		$line = sprintf( '[WP YetiSearch] %s: %s', strtoupper( $level ), (string) $message );
		if ( $context !== array() ) {
			$line .= ' ' . (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		}
		( $this->writer )( $line );
		if ( $this->fileLog !== null && ( self::SEVERITY[ $level ] ?? 7 ) <= self::SEVERITY[ LogLevel::WARNING ] ) {
			$this->fileLog->append( $level, (string) $message, $context );
		}
	}
}
