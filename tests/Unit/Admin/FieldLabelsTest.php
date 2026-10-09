<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Admin;

use WpYetiSearch\Admin\FieldLabels;
use WpYetiSearch\Core\SettingsSchema;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class FieldLabelsTest extends UnitTestCase
{
    public function testTabsCoverAllSchemaTabs(): void
    {
        $tabs = FieldLabels::tabs();

        self::assertSame(SettingsSchema::TABS, array_keys($tabs));
        foreach ($tabs as $label) {
            self::assertNotSame('', $label);
        }
    }

    public function testEverySchemaFieldHasLabelAndHelp(): void
    {
        foreach (SettingsSchema::TABS as $tab) {
            $labels = FieldLabels::for($tab);
            self::assertSame(
                array_keys(SettingsSchema::fieldsForTab($tab)),
                array_keys($labels),
                "tab {$tab}"
            );
            foreach ($labels as $key => $entry) {
                self::assertNotSame('', $entry['label'], $key);
                self::assertArrayHasKey('help', $entry, $key);
            }
        }
    }

    public function testUnknownKeyFallsBackToKeyItself(): void
    {
        self::assertSame(
            ['label' => 'nope_missing', 'help' => ''],
            FieldLabels::label('nope_missing')
        );
    }
}
