<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use WpYetiSearch\Search\ItalianStemmer;
use WpYetiSearch\Tests\Unit\UnitTestCase;

final class ItalianStemmerTest extends UnitTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function stemVectors(): iterable
    {
        // Inflected form => expected stem (verified against PyStemmer 3.1.1,
        // the reference Snowball implementation, 2026-10-09).
        yield 'feminine adjective' => ['abbandonata', 'abbandon'];
        yield 'feminine plural' => ['abbandonate', 'abbandon'];
        yield 'masculine plural' => ['abbandonati', 'abbandon'];
        yield 'past participle' => ['abbandonato', 'abbandon'];
        yield 'imperfect' => ['abbandonava', 'abbandon'];
        yield 'future plural' => ['abbandoneranno', 'abbandon'];
        yield 'future 3sg accented' => ['abbandonerà', 'abbandon'];
        yield 'future 1sg accented' => ['abbandonerò', 'abbandon'];
        yield 'present 1sg -ono' => ['abbandono', 'abband'];
        yield 'past 3sg accented' => ['abbandonò', 'abbandon'];
        yield 'past participle -ato' => ['abbaruffato', 'abbaruff'];
        yield '-amento noun' => ['abbassamento', 'abbass'];
        yield 'gerund' => ['abbassando', 'abbass'];
        yield 'gerund + pronoun' => ['abbassandola', 'abbass'];
        yield 'infinitive' => ['abbassare', 'abbass'];
        yield 'infinitive + reflexive' => ['abbassarsi', 'abbass'];
        yield 'past 3pl' => ['abbassarono', 'abbass'];
        yield '-anza noun' => ['abbastanza', 'abbast'];
        yield 'infinitive -ere' => ['abbattere', 'abbatt'];
        yield 'infinitive -ere + reflexive' => ['abbattersi', 'abbatt'];
        yield 'subjunctive' => ['abbattesse', 'abbattess'];
        yield '-imento noun' => ['abbattimento', 'abbatt'];
        yield 'past participle fem' => ['abbattuta', 'abbatt'];
        yield 'past participle masc pl' => ['abbattuti', 'abbatt'];
        yield 'past participle masc' => ['abbattuto', 'abbatt'];
        yield 'past 3sg acute accent' => ['abbatté', 'abbatt'];
        yield '-ita participle' => ['abbellita', 'abbell'];
        yield 'consonant + ché' => ['abbenché', 'abbenc'];
        yield 'short word' => ['abbi', 'abbi'];
        yield 'final vowel' => ['pronto', 'pront'];
        yield 'final a' => ['propaganda', 'propagand'];
        yield 'present 3sg' => ['propone', 'propon'];
        yield 'present participle pl' => ['proponenti', 'proponent'];
        yield 'subjunctive -ga' => ['proponga', 'propong'];
        yield '-ende verb' => ['propende', 'prop'];
        yield 'adjective pl' => ['propensi', 'propens'];
        yield '-ione noun' => ['propensione', 'propension'];
        yield 'diphthong + final o' => ['proprio', 'propr'];
        yield 'adjective -izio' => ['propizio', 'propiz'];
        yield '-mento noun' => ['pronunciamento', 'pronunc'];
        yield 'infinitive + reflexive si' => ['pronunciarsi', 'pronunc'];
        yield '-azione + ic' => ['propagazione', 'propag'];
        yield 'infinitive + pronoun la' => ['propagarla', 'propag'];
        yield '-azione + ic overstem' => ['comunicazione', 'comun'];
        yield '-azione outside R2' => ['nazione', 'nazion'];
        yield 'accented final à' => ['città', 'citt'];
        yield '-amente adverb' => ['velocemente', 'veloc'];
        yield '-ale adjective' => ['nazionale', 'nazional'];
        yield 'divano exception' => ['divano', 'divan'];
        yield 'diva still strips' => ['diva', 'div'];
        yield 'attached pronoun dopo gerund' => ['guardandogli', 'guard'];
        yield 'attached pronoun dopo infinitive' => ['accomodarci', 'accomod'];
        yield 'ch + i' => ['crocchi', 'crocc'];
        yield 'ch + io' => ['crocchio', 'crocc'];
        yield 'perché' => ['perché', 'perc'];
        yield 'infinitive -ar' => ['parlar', 'parl'];
        yield 'final -er kept' => ['perder', 'perder'];
        yield 'elision l’' => ["l'anno", 'anno'];
        yield 'elision dall’' => ["dall'anno", 'anno'];
        yield 'trailing apostrophe kept' => ["d'", "d'"];
        yield '-logia noun' => ['teologia', 'teolog'];
        yield '-evole adjective' => ['amichevole', 'amichevol'];
        yield '-amente + iv chain' => ['produttivamente', 'produtt'];
        yield 'uppercase input' => ['CITTÀ', 'citt'];
        yield 'empty input' => ['', ''];
    }

    #[DataProvider('stemVectors')]
    public function testStemming(string $word, string $expected): void
    {
        self::assertSame($expected, (new ItalianStemmer())->stem($word));
    }

    public function testLanguageIsItalian(): void
    {
        self::assertSame('it', (new ItalianStemmer())->getLanguage());
    }

    public function testRegisterIsDormantWithoutLibrarySupport(): void
    {
        // Becomes true once the library ships StemmerFactory::register().
        self::assertFalse(ItalianStemmer::registerIfSupported());
    }

    public function testStemmingIsIdempotent(): void
    {
        $stemmer = new ItalianStemmer();
        foreach (['comunicazione', 'velocemente', 'abbassandola', 'produttivamente', "dall'anno"] as $word) {
            $once = $stemmer->stem($word);
            self::assertSame($once, $stemmer->stem($once), $word);
        }
    }
}
