<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class ServerSecurityTest extends UnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function testHtaccessDeniesWholeDirectory(): void
    {
        self::assertTrue((new ServerSecurity())->writeProtectionFiles($this->dir));

        $htaccess = (string) file_get_contents($this->dir . '/.htaccess');
        self::assertStringContainsString('Require all denied', $htaccess);
        self::assertStringContainsString('Deny from all', $htaccess);
        self::assertStringNotContainsString('FilesMatch', $htaccess, 'the whole directory is denied, not only extensions');

        $webConfig = (string) file_get_contents($this->dir . '/web.config');
        self::assertStringContainsString('allowUnlisted="false"', $webConfig);
        self::assertStringContainsString('accessType="Deny"', $webConfig);

        self::assertStringContainsString('403', (string) file_get_contents($this->dir . '/index.php'));
    }

    public function testForeignFilesAreNeverOverwritten(): void
    {
        file_put_contents($this->dir . '/.htaccess', "Options -Indexes\n");

        self::assertFalse((new ServerSecurity())->writeProtectionFiles($this->dir));
        self::assertSame("Options -Indexes\n", file_get_contents($this->dir . '/.htaccess'));
    }

    public function testDetectServer(): void
    {
        $security = new ServerSecurity();

        self::assertSame('nginx', $security->detectServer('nginx/1.25.3'));
        self::assertSame('litespeed', $security->detectServer('LiteSpeed'));
        self::assertSame('caddy', $security->detectServer('Caddy'));
        self::assertSame('iis', $security->detectServer('Microsoft-IIS/10.0'));
        self::assertSame('apache', $security->detectServer('Apache/2.4.58 (Unix)'));
        self::assertSame('apache', $security->detectServer(''));
    }

    public function testRuleSnippetsUseTheRealUrlPath(): void
    {
        $security = new ServerSecurity();
        $url = 'https://example.test/wp-content/uploads/yetisearch';

        self::assertStringContainsString('location ^~ /wp-content/uploads/yetisearch/ {', $security->ruleSnippet('nginx', $url));
        self::assertStringContainsString('deny all;', $security->ruleSnippet('nginx', $url));
        self::assertStringContainsString('@yetisearch path /wp-content/uploads/yetisearch/*', $security->ruleSnippet('caddy', $url));
        self::assertSame('', $security->ruleSnippet('apache', $url));
    }

    public function testProbeReportsLeakWhenFileIsServed(): void
    {
        $dir = $this->dir;
        Functions\when('wp_remote_get')->alias(static function (string $url) use ($dir): array {
            return ['code' => 200, 'body' => (string) file_get_contents($dir . '/' . basename($url))];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);

        $status = (new ServerSecurity())->probe($this->dir, 'https://example.test/wp-content/uploads/yetisearch');

        self::assertSame('leak', $status);
        self::assertSame([], glob($this->dir . '/yetisearch-probe-*') ?: [], 'probe file is removed');
    }

    public function testProbeReportsSafeOn403AndUnknownOnTransportError(): void
    {
        Functions\when('wp_remote_get')->justReturn(['code' => 403, 'body' => 'Forbidden']);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);
        Functions\when('is_wp_error')->justReturn(false);
        $security = new ServerSecurity();

        self::assertSame('safe', $security->probe($this->dir, 'https://example.test/x'));
        self::assertSame('safe', $security->probe($this->dir, null), 'outside the web root');

        Functions\when('is_wp_error')->justReturn(true);
        self::assertSame('unknown', $security->probe($this->dir, 'https://example.test/x'));
    }

    public function testCurrentServerReadsServerSoftware(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
        try {
            self::assertSame('nginx', (new ServerSecurity())->currentServer());
        } finally {
            unset($_SERVER['SERVER_SOFTWARE']);
        }

        self::assertSame('apache', (new ServerSecurity())->currentServer());
    }

    public function testWriteProtectionFailsOutsideDirectories(): void
    {
        $file = $this->dir . '/afile';
        file_put_contents($file, 'x');

        self::assertFalse((new ServerSecurity())->writeProtectionFiles($file));
    }

    public function testProbeReportsUnknownWhenTokenCannotBeWritten(): void
    {
        $file = $this->dir . '/afile';
        file_put_contents($file, 'x');

        self::assertSame('unknown', (new ServerSecurity())->probe($file, 'https://example.test/x'));
    }
}
