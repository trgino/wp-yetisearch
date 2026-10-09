<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Storage\ServerSecurity;
use WpYetiSearch\Storage\StorageManager;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class StorageManagerTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = $this->tempDir('yetisearch-uploads-');
        $uploads = $this->uploads;
        $this->options = [];
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('wp_mkdir_p')->alias(static function (string $d): bool {
            if (is_dir($d)) {
                return true;
            }
            return file_exists($d) ? false : mkdir($d, 0777, true);
        });
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false): mixed => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;
            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        parent::tearDown();
    }

    public function testDefaultDirectoryAndDatabasePath(): void
    {
        $storage = new StorageManager(new Config(), new ServerSecurity());

        self::assertSame($this->uploads . '/yetisearch', $storage->storageDir());
        self::assertMatchesRegularExpression('#/yetisearch/yetisearch_[a-f0-9]{12}\.sqlite$#', $storage->dbPath());
    }

    public function testHashIsCreatedOnceAndReused(): void
    {
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $first = $storage->dbHash();

        self::assertSame($first, $storage->dbHash());
        self::assertSame($first, $this->options[StorageManager::HASH_OPTION]);
    }

    public function testCustomDirectoryWins(): void
    {
        $storage = new StorageManager(new Config(['db_custom_dir' => '/var/data/yeti']), new ServerSecurity());

        self::assertSame('/var/data/yeti', $storage->storageDir());
        self::assertNull($storage->dirUrl(), 'outside uploads and ABSPATH means not web reachable');
    }

    public function testDirUrlForUploadsAndAbspath(): void
    {
        self::assertSame(
            'https://example.test/wp-content/uploads/yetisearch',
            (new StorageManager(new Config(), new ServerSecurity()))->dirUrl()
        );

        $insideRoot = rtrim(ABSPATH, '/') . '/private/yeti';
        self::assertSame(
            'https://example.test/private/yeti',
            (new StorageManager(new Config(['db_custom_dir' => $insideRoot]), new ServerSecurity()))->dirUrl()
        );
    }

    public function testEnsureProtectedCreatesDirectoryAndFiles(): void
    {
        $storage = new StorageManager(new Config(), new ServerSecurity());

        self::assertTrue($storage->ensureProtected());
        self::assertFileExists($this->uploads . '/yetisearch/.htaccess');
        self::assertFileExists($this->uploads . '/yetisearch/index.php');
    }

    public function testCheckDirRejectsBadPaths(): void
    {
        $storage = new StorageManager(new Config(), new ServerSecurity());

        self::assertSame('not_absolute', $storage->checkDir('relative/path')['error']);
        self::assertSame('not_absolute', $storage->checkDir('/tmp/../etc')['error']);
        self::assertFalse($storage->checkDir('relative/path')['ok']);
    }

    public function testCheckDirAcceptsWritableDirectory(): void
    {
        $dir = $this->uploads . '/custom';
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $result = $storage->checkDir($dir);

        self::assertTrue($result['ok'], $result['error']);
        self::assertTrue(is_dir($dir), 'check creates the directory');
    }

    public function testCheckDirRejectsExistingFile(): void
    {
        $file = $this->uploads . '/afile';
        file_put_contents($file, 'x');
        $storage = new StorageManager(new Config(), new ServerSecurity());

        self::assertSame('not_a_directory', $storage->checkDir($file)['error']);
    }

    public function testCheckDirReportsUnwritableDirectory(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            self::markTestSkipped('chmod-based read-only dirs are unreliable on Windows.');
        }
        $dir = $this->uploads . '/readonly';
        mkdir($dir, 0777, true);
        chmod($dir, 0555);
        try {
            $result = (new StorageManager(new Config(), new ServerSecurity()))->checkDir($dir);
        } finally {
            chmod($dir, 0777);
        }

        self::assertFalse($result['ok']);
        self::assertSame('not_writable', $result['error']);
    }

    public function testMigrateCopiesVerifiesAndRemovesSources(): void
    {
        $from = $this->uploads . '/old';
        $to = $this->uploads . '/new';
        mkdir($from, 0777, true);
        file_put_contents($from . '/yetisearch_abc123.sqlite', str_repeat('d', 1000));
        file_put_contents($from . '/.htaccess', 'WP YetiSearch deny');
        file_put_contents($from . '/other.txt', 'foreign file stays');
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $result = $storage->migrate($from, $to);

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['moved']);
        self::assertSame(str_repeat('d', 1000), file_get_contents($to . '/yetisearch_abc123.sqlite'));
        self::assertFileDoesNotExist($from . '/yetisearch_abc123.sqlite');
        self::assertFileDoesNotExist($from . '/.htaccess');
        self::assertFileExists($from . '/other.txt', 'foreign files are never touched');
    }

    public function testMigrateKeepsSourcesWhenTargetIsUnusable(): void
    {
        $from = $this->uploads . '/old2';
        mkdir($from, 0777, true);
        file_put_contents($from . '/yetisearch_abc123.sqlite', 'data');
        $to = $this->uploads . '/afile';
        file_put_contents($to, 'x');
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $result = $storage->migrate($from, $to);

        self::assertFalse($result['ok']);
        self::assertFileExists($from . '/yetisearch_abc123.sqlite', 'sources stay intact');
    }

    public function testCheckDirAcceptsEmptyMeaningDefault(): void
    {
        $result = (new StorageManager(new Config(), new ServerSecurity()))->checkDir('');

        self::assertTrue($result['ok']);
        self::assertSame('', $result['error']);
    }

    public function testCheckDirReportsUncreatablePath(): void
    {
        Functions\when('wp_mkdir_p')->justReturn(false);
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $result = $storage->checkDir($this->uploads . '/nope');

        self::assertFalse($result['ok']);
        self::assertSame('not_creatable', $result['error']);
    }

    public function testMigrateRejectsEmptyOrSameSource(): void
    {
        $storage = new StorageManager(new Config(), new ServerSecurity());

        self::assertSame(['source'], $storage->migrate('', $this->uploads . '/new')['failed']);
        self::assertSame(['source'], $storage->migrate($this->uploads . '/missing', $this->uploads . '/new')['failed']);
    }

    public function testMigrateKeepsSourceWhenCopyFails(): void
    {
        $from = $this->uploads . '/old3';
        $to = $this->uploads . '/new3';
        mkdir($from, 0777, true);
        mkdir($to, 0777, true);
        file_put_contents($from . '/yetisearch_abc123.sqlite', 'data');
        mkdir($to . '/yetisearch_abc123.sqlite', 0777, true);
        $storage = new StorageManager(new Config(), new ServerSecurity());

        $result = $storage->migrate($from, $to);

        self::assertFalse($result['ok']);
        self::assertSame(['yetisearch_abc123.sqlite'], $result['failed']);
        self::assertFileExists($from . '/yetisearch_abc123.sqlite', 'source stays intact');
    }

    public function testEnsureProtectedFailsForUncreatableDirectory(): void
    {
        $file = $this->uploads . '/afile';
        file_put_contents($file, 'x');
        $storage = new StorageManager(new Config(['db_custom_dir' => $file]), new ServerSecurity());

        self::assertFalse($storage->ensureProtected());
    }
}
