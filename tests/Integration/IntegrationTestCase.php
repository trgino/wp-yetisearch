<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Integration;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

abstract class IntegrationTestCase extends UnitTestCase
{
    protected string $dir = '';

    /** @var list<YetiSearch> */
    private array $engines = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for integration tests.');
        }
        $this->dir = $this->tempDir('yetisearch-it-');
    }

    protected function tearDown(): void
    {
        foreach ($this->engines as $engine) {
            $engine->close(); // Windows cannot delete open SQLite files.
        }
        $this->engines = [];
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    protected function makeYeti(Config $config): YetiSearch
    {
        $engine = new YetiSearch($config->toYetiConfig($this->dir . '/test.sqlite'));
        $this->engines[] = $engine;
        return $engine;
    }
}
