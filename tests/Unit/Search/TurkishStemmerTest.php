<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use WpYetiSearch\Search\TurkishStemmer;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Stemmer\StemmerFactory;

final class TurkishStemmerTest extends UnitTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function stemVectors(): iterable
    {
        // Inflected form => expected stem.
        yield 'plural' => ['kitaplar', 'kitap'];
        yield 'plural + possessive' => ['kitapları', 'kitap'];
        yield 'plural + genitive' => ['evlerin', 'ev'];
        yield 'locative' => ['arabada', 'araba'];
        yield 'genitive' => ['kedinin', 'kedi'];
        yield 'dative' => ['kediye', 'kedi'];
        yield 'derivational -lik' => ['güzellik', 'güzel'];
        yield 'privative -siz' => ['evsiz', 'ev'];
        yield 'adjectival -li' => ['Ankaralı', 'ankara'];
        yield 'ki chain' => ['benimki', 'ben'];
        yield 'present buffer kept' => ['geliyor', 'geli'];
        yield 'present + plural' => ['geliyorlar', 'geli'];
        yield 'progressive' => ['okuyor', 'oku'];
        yield 'past + 1sg' => ['okudum', 'oku'];
        yield 'future' => ['yapacak', 'yap'];
        yield 'infinitive' => ['yapmak', 'yap'];
        yield 'necessitative' => ['yapmalı', 'yap'];
        yield 'evidential' => ['gelmiş', 'gel'];
        yield 'negative aorist' => ['yapmaz', 'yap'];
        yield 'while' => ['varken', 'var'];
        // Protected roots and documented limits (must stay intact).
        yield 'negation lookalike' => ['elma', 'elma'];
        yield 'conditional lookalike' => ['masa', 'masa'];
        yield 'ki lookalike' => ['belki', 'belki'];
        yield 'ken lookalike' => ['erken', 'erken'];
        yield 'aorist lookalike' => ['şehir', 'şehir'];
        yield 'infinitive lookalike' => ['yemek', 'yemek'];
        yield 'bare accusative' => ['kitabı', 'kitabı'];
        yield 'bare dative' => ['eve', 'eve'];
        yield 'two-letter root' => ['olmaz', 'olmaz'];
        yield 'short words' => ['su', 'su'];
        yield 'pronoun' => ['ben', 'ben'];
    }

    #[DataProvider('stemVectors')]
    public function testStemming(string $word, string $expected): void
    {
        self::assertSame($expected, (new TurkishStemmer())->stem($word));
    }

    public function testLanguageIsTurkish(): void
    {
        self::assertSame('tr', (new TurkishStemmer())->getLanguage());
    }

    public function testRegisterExposesTurkishToTheFactory(): void
    {
        TurkishStemmer::register();
        try {
            self::assertTrue(StemmerFactory::isSupported('turkish'));
            self::assertSame('turkish', StemmerFactory::canonical('tr'));
            self::assertSame('turkish', StemmerFactory::canonical('tur'));
            self::assertInstanceOf(TurkishStemmer::class, StemmerFactory::create('turkish'));
        } finally {
            StemmerFactory::reset();
        }
        self::assertFalse(StemmerFactory::isSupported('turkish'), 'reset removes the registration');
    }
}
