<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpYetiSearch\Admin\FieldInput;

#[CoversClass(FieldInput::class)]
final class FieldInputTest extends TestCase
{
    public function testNumberListRendersCommaJoinedSoSubmitPreservesDefaults(): void
    {
        self::assertSame('100, 500, 1000', FieldInput::textValue('number_list', [100.0, 500.0, 1000.0]));
    }

    public function testNumberListRendersEmptyStringForEmptyArray(): void
    {
        self::assertSame('', FieldInput::textValue('number_list', []));
    }

    public function testScalarTypesPassThrough(): void
    {
        self::assertSame('7', FieldInput::textValue('int', 7));
        self::assertSame('_price', FieldInput::textValue('string', '_price'));
        self::assertSame('', FieldInput::textValue('string', ['not-scalar']));
    }
}
