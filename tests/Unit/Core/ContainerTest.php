<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use WpYetiSearch\Core\Container;

final class ContainerTest extends TestCase
{
    public function testFactoryRunsOnceAndInstanceIsShared(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('svc', static function () use (&$calls): \stdClass {
            $calls++;
            return new \stdClass();
        });

        self::assertSame($container->get('svc'), $container->get('svc'));
        self::assertSame(1, $calls);
    }

    public function testNullResultIsMemoized(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('nothing', static function () use (&$calls): ?\stdClass {
            $calls++;
            return null;
        });

        self::assertNull($container->get('nothing'));
        self::assertNull($container->get('nothing'));
        self::assertSame(1, $calls);
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $container->set('a', static fn (): string => 'A');
        $container->set('b', static fn (Container $c): string => $c->get('a') . 'B');

        self::assertSame('AB', $container->get('b'));
    }

    public function testUnknownServiceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Container())->get('missing');
    }

    public function testHasReportsRegisteredFactories(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): string => 'x');

        self::assertTrue($container->has('svc'));
        self::assertFalse($container->has('missing'));
    }
}
