<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('sanitize_text_field')->alias(
            static fn (mixed $v): string => trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $v)))
        );
        Functions\when('sanitize_key')->alias(
            static fn (mixed $v): string => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v))
        );
        Functions\when('esc_url_raw')->alias(static fn (mixed $v): string => trim((string) $v));
        Functions\when('wp_normalize_path')->alias(
            static fn (mixed $p): string => (string) preg_replace('#/+#', '/', str_replace('\\', '/', (string) $p))
        );
        Functions\when('wp_strip_all_tags')->alias(
            // Mirrors core (script/style removal + strip_tags, no trimming).
            static fn (mixed $v): string => (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', strip_tags( (string) $v ) )
        );
        Functions\when('wp_json_encode')->alias(
            static fn (mixed $data, int $options = 0, int $depth = 512 ): string|false => json_encode( $data, $options, $depth )
        );
        Functions\when('wp_delete_file')->alias(
            static function ( string $file ): void {
                if ( is_file( $file ) ) {
                    unlink( $file );
                }
            }
        );
        Functions\when('wp_parse_url')->alias(
            static fn ( string $url, int $component = -1 ): mixed => parse_url( $url, $component )
        );
        Functions\when('strip_shortcodes')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        // Multilingual plugin API: default to "no multilingual plugin" so
        // feature detection via function_exists() stays hermetic. Brain Monkey
        // definitions leak across test files in one process; a stub made by
        // one test would otherwise trap later tests (defined but unmocked).
        // Tests needing Polylang/WPML re-stub these per test.
        Functions\when('pll_get_post_language')->justReturn(null);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('pll_current_language')->justReturn(null);
        Functions\when('has_filter')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Creates an empty temp directory and returns its normalized path. */
    protected function tempDir(string $prefix = 'yetisearch-test-'): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $prefix . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
