<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use WpYetiSearch\Core\FileLog;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class FileLogTest extends UnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir('yetisearch-filelog-');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function testAppendAndTailPreserveOrder(): void
    {
        $log = new FileLog($this->dir);
        $log->append('warning', 'first', ['a' => 1]);
        $log->append('error', 'second', []);

        $lines = $log->tail(10);

        self::assertCount(2, $lines);
        self::assertSame('first', $lines[0]['message']);
        self::assertSame('warning', $lines[0]['level']);
        self::assertSame('second', $lines[1]['message']);
        self::assertArrayHasKey('time', $lines[0]);
    }

    public function testTailFiltersByLevelAndLimitsLines(): void
    {
        $log = new FileLog($this->dir);
        for ($i = 0; $i < 5; $i++) {
            $log->append('info', 'info-' . $i, []);
        }
        $log->append('error', 'boom', []);

        $errors = $log->tail(100, 'error');

        self::assertCount(1, $errors);
        self::assertSame('boom', $errors[0]['message']);

        $lastTwo = $log->tail(2);

        self::assertCount(2, $lastTwo);
        self::assertSame('boom', $lastTwo[1]['message']);
    }

    public function testPruneDeletesOnlyExpiredFiles(): void
    {
        $log = new FileLog($this->dir);
        $log->append('error', 'fresh', []);
        $old = $this->dir . '/yetisearch-2000-01-01.log';
        file_put_contents($old, "{\"time\":\"x\",\"level\":\"error\",\"message\":\"old\",\"context\":[]}\n");
        touch($old, strtotime('2000-01-02'));

        $pruned = $log->prune();

        self::assertSame(1, $pruned);
        self::assertFileDoesNotExist($old);
        self::assertNotSame([], glob($this->dir . '/yetisearch-*.log'));
    }

    public function testClearRemovesLogFiles(): void
    {
        $log = new FileLog($this->dir);
        $log->append('error', 'x', []);
        file_put_contents($this->dir . '/keep.txt', 'keep');

        $log->clear();

        self::assertSame([], glob($this->dir . '/yetisearch-*.log') ?: []);
        self::assertFileExists($this->dir . '/keep.txt');
    }

    public function testAppendGivesUpOnUncreatableDirectory(): void
    {
        $file = $this->dir . '/afile';
        file_put_contents($file, 'x');

        (new FileLog($file . '/sub'))->append('error', 'lost');

        $this->expectNotToPerformAssertions();
    }

    public function testAppendSkipsUnencodableContext(): void
    {
        $log = new FileLog($this->dir);
        $log->append('error', 'bad', ['raw' => "\xB1\x31"]);

        self::assertSame([], $log->tail(10));
    }

    public function testAppendGivesUpWhenFileIsADirectory(): void
    {
        mkdir($this->dir . '/yetisearch-' . gmdate('Y-m-d') . '.log');

        (new FileLog($this->dir))->append('error', 'lost');

        $this->expectNotToPerformAssertions();
    }

    public function testTailSkipsMalformedLines(): void
    {
        $log = new FileLog($this->dir);
        $log->append('error', 'good', []);
        file_put_contents(
            $this->dir . '/yetisearch-' . gmdate('Y-m-d') . '.log',
            "not json\n{\"level\":\"error\"}\n",
            FILE_APPEND
        );

        $lines = $log->tail(10);

        self::assertCount(1, $lines);
        self::assertSame('good', $lines[0]['message']);
    }

    public function testAppendScrubsObjectsFromContext(): void
    {
        $log = new FileLog($this->dir);
        $log->append('error', 'boom', ['exception' => new \RuntimeException('x'), 'obj' => new \stdClass()]);

        $raw = file_get_contents($this->dir . '/yetisearch-' . gmdate('Y-m-d') . '.log');

        self::assertStringNotContainsString('RuntimeException', (string) $raw);
        self::assertStringContainsString('stdClass', (string) $raw);
    }
}
