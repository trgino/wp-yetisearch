<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use WpYetiSearch\Core\Logger;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class LoggerTest extends UnitTestCase
{
    public function testMessagesBelowMinimumLevelAreDropped(): void
    {
        $lines = [];
        $logger = new Logger(LogLevel::WARNING, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->debug('noise');
        $logger->info('noise');
        $logger->error('boom');

        self::assertCount(1, $lines);
        self::assertStringContainsString('[WP YetiSearch] ERROR: boom', $lines[0]);
    }

    public function testThrowablesInContextAreSummarized(): void
    {
        $lines = [];
        $logger = new Logger(LogLevel::DEBUG, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->warning('failed', ['exception' => new \RuntimeException('disk full'), 'post_id' => 7]);

        self::assertStringContainsString('RuntimeException: disk full', $lines[0]);
        self::assertStringContainsString('"post_id":7', $lines[0]);
    }

    public function testDefaultWriterUsesErrorLog(): void
    {
        $logger = new Logger();

        $logger->warning('last resort');

        $this->expectNotToPerformAssertions();
    }

    public function testFileLogReceivesWarningsAndAbove(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/yetisearch-logger-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            $logger = (new Logger(LogLevel::DEBUG, static function (string $line): void {}))
                ->withFileLog(new \WpYetiSearch\Core\FileLog($dir));

            $logger->warning('visible in file');
            $logger->debug('file skips debug');

            $lines = (new \WpYetiSearch\Core\FileLog($dir))->tail(10);
            self::assertCount(1, $lines);
            self::assertSame('visible in file', $lines[0]['message']);
        } finally {
            foreach ((scandir($dir) ?: []) as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($dir . '/' . $name);
                }
            }
            @rmdir($dir);
        }
    }
}
