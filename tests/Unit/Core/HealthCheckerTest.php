<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class HealthCheckerTest extends UnitTestCase
{
    /** @return array<string, array{ok: bool, value: string}> */
    private static function passingChecks(): array
    {
        $checks = [];
        foreach (['php_version', 'pdo_sqlite', 'sqlite_version', 'sqlite_recency', 'fts5_support', 'rtree_support', 'directory_writable'] as $key) {
            $checks[$key] = ['ok' => true, 'value' => 'ok'];
        }
        return $checks;
    }

    public function testNotReadyWhenFts5Missing(): void
    {
        $checks = self::passingChecks();
        $checks['fts5_support']['ok'] = false;

        self::assertFalse(HealthChecker::computeReady($checks));
    }

    public function testRtreeIsOptional(): void
    {
        $checks = self::passingChecks();
        $checks['rtree_support']['ok'] = false;

        self::assertTrue(HealthChecker::computeReady($checks));
    }

    public function testOldSqliteIsOnlyANotice(): void
    {
        $checks = self::passingChecks();
        $checks['sqlite_recency']['ok'] = false;

        self::assertTrue(HealthChecker::computeReady($checks));
    }

    public function testRealEnvironmentPassesWithWritableDirectory(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
        $dir = $this->tempDir();

        $checks = (new HealthChecker())->checkAll($dir . '/storage');

        self::assertTrue($checks['pdo_sqlite']['ok']);
        self::assertTrue($checks['sqlite_version']['ok'], $checks['sqlite_version']['value']);
        self::assertTrue($checks['directory_writable']['ok']);
        self::assertSame([], array_diff(scandir($dir . '/storage') ?: [], ['.', '..']), 'write test leaves no file');
        $this->removeDir($dir);
    }

    public function testMissingDriverIsNotReadyAndIsPersisted(): void
    {
        $dir = $this->tempDir();
        Functions\expect('update_option')
            ->once()
            ->with(HealthChecker::OPTION_KEY, \Mockery::on(static fn (array $r): bool => $r['ready'] === false && $r['php'] === PHP_VERSION), false);

        $record = (new HealthChecker(static fn (): ?\PDO => null))->refresh($dir);

        self::assertFalse($record['ready']);
        self::assertFalse($record['checks']['pdo_sqlite']['ok']);
        self::assertFalse($record['checks']['fts5_support']['ok']);
        $this->removeDir($dir);
    }

    public function testUnwritableDirectoryFails(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ys');

        $checks = (new HealthChecker(static fn (): ?\PDO => null))->checkAll($file . '/storage');

        self::assertFalse($checks['directory_writable']['ok']);
        unlink($file);
    }

    public function testCachedRecordIsIgnoredAfterPhpUpgrade(): void
    {
        Functions\when('get_option')->justReturn(['ready' => true, 'checks' => [], 'checked_at' => 1, 'php' => '7.4.0']);

        $health = new HealthChecker();

        self::assertNull($health->cached());
        self::assertFalse($health->isReady());
    }

    public function testGeoReadyRequiresRtree(): void
    {
        $checks = self::passingChecks();
        $checks['rtree_support']['ok'] = false;
        Functions\when('get_option')->justReturn(['ready' => true, 'checks' => $checks, 'checked_at' => 1, 'php' => PHP_VERSION]);

        $health = new HealthChecker();

        self::assertTrue($health->isReady());
        self::assertFalse($health->isGeoReady());
    }

    public function testBrokenPdoDegradesVersionAndFeatureChecks(): void
    {
        $pdo = \Mockery::mock(\PDO::class);
        $pdo->shouldReceive('query')->andThrow(new \PDOException('gone'));
        $pdo->shouldReceive('exec')->andThrow(new \PDOException('gone'));
        $dir = $this->tempDir();

        $checks = (new HealthChecker(static fn (): ?\PDO => $pdo))->checkAll($dir);

        self::assertSame('n/a', $checks['sqlite_version']['value']);
        self::assertFalse($checks['fts5_support']['ok']);
        self::assertFalse($checks['rtree_support']['ok']);
        $this->removeDir($dir);
    }

    public function testReadOnlyDirectoryFails(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            self::markTestSkipped('chmod-based read-only dirs are unreliable on Windows.');
        }
        $dir = $this->tempDir();
        chmod($dir, 0555);
        try {
            $checks = (new HealthChecker(static fn (): ?\PDO => null))->checkAll($dir);
        } finally {
            chmod($dir, 0777);
            $this->removeDir($dir);
        }

        self::assertFalse($checks['directory_writable']['ok']);
    }
}
