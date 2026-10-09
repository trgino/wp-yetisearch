<?php
declare(strict_types=1);

namespace WpYetiSearch\Search;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;

/**
 * Turkish suffix-striping stemmer (Snowball-inspired).
 *
 * Strips nominal suffixes (plural, possessive, case, -ki, -lik/-siz/-li/-ci)
 * then verbal ones (infinitive, tense, conditional, negation, fused personals),
 * longest match first, each guarded by vowel harmony and a minimum stem
 * (3+ chars with a vowel). Single-letter suffixes are never stripped, so
 * bare accusative/dative forms (kitabı, eve) and two-letter roots (ol) stay
 * intact by design.
 *
 * Dormant until the library ships StemmerFactory::register(): see
 * docs/issues/05-stemmer-registration.md, then call registerIfSupported()
 * from boot and offer 'turkish' in the stemmer_language setting.
 */
final class TurkishStemmer implements StemmerInterface {

	/** Two-letter roots stripping may land on; anything else needs 3+ chars. */
	private const SHORT_ROOTS = array( 'ev', 'su', 'at', 'ot', 'ay', 'oy', 'ön', 'iç' );

	public function getLanguage(): string {
		return 'tr';
	}

	public static function registerIfSupported(): bool {
		if ( ! method_exists( StemmerFactory::class, 'register' ) ) {
			return false;
		}
		StemmerFactory::register( 'turkish', self::class, array( 'tr', 'tur' ) );
		return true;
	}

	public function stem( string $word ): string {
		$word = $this->lower( $word );
		if ( $word === '' ) {
			return '';
		}
		// Verb forms first: fused personals (okudum) must win over the
		// possessive lookalikes (-um). Cycles handle chains like
		// geliyorlar (-lar, then -yor). Stripping only shortens, so this ends.
		for ( $i = 0; $i < 3; $i++ ) {
			$next = $this->stripGroup( $this->stripGroup( $word, $this->verbSuffixes() ), $this->nounSuffixes() );
			if ( $next === $word ) {
				return $word;
			}
			$word = $next;
		}
		return $word;
	}

	/** @return list<array{suffix: string, vowels: string, minStem?: int}> */
	private function nounSuffixes(): array {
		return array(
			array(
				'suffix' => 'ımız',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'imiz',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'umuz',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'ümüz',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'ınız',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'iniz',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'unuz',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'ünüz',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'ları',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'leri',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'nın',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'nin',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'nun',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'nün',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'dan',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'den',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'tan',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'ten',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'lık',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'lik',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'luk',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'lük',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'sız',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'siz',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'suz',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'süz',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'yla',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'yle',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'lar',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'ler',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'sı',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'si',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'su',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'sü',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'yı',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'yi',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'yu',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'yü',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'da',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'de',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'ta',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'te',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'ya',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'ye',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'ım',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'im',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'um',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'üm',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'ın',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'in',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'un',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'ün',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'lı',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'li',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'lu',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'lü',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'cı',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'ci',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'cu',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'cü',
				'vowels' => 'eiöü',
			),
			array(
				'suffix'  => 'ki',
				'vowels'  => 'aeıioöuü',
				'minStem' => 4,
			),
		);
	}

	/** @return list<array{suffix: string, vowels: string, minStem?: int}> */
	private function verbSuffixes(): array {
		return array(
			array(
				'suffix' => 'acaksınız',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'eceksiniz',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'sınız',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'siniz',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'sunuz',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'sünüz',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'acak',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'ecek',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'yor',
				'vowels' => 'aeıioöuü',
			),
			array(
				'suffix' => 'malı',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'meli',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'dım',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'dim',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'dum',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'düm',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'tım',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'tim',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'tum',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'tüm',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'dın',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'din',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'dun',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'dün',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'tın',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'tin',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'tun',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'tün',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'sın',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'sin',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'sun',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'sün',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'yım',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'yim',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'yum',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'yüm',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'yız',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'yiz',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'yuz',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'yüz',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'mak',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'mek',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'mış',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'miş',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'muş',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'müş',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'maz',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'mez',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'dır',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'dir',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'dur',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'dür',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'tır',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'tir',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'tur',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'tür',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'dı',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'di',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'du',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'dü',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'tı',
				'vowels' => 'aı',
			),
			array(
				'suffix' => 'ti',
				'vowels' => 'ei',
			),
			array(
				'suffix' => 'tu',
				'vowels' => 'ou',
			),
			array(
				'suffix' => 'tü',
				'vowels' => 'öü',
			),
			array(
				'suffix' => 'ken',
				'vowels' => 'aeıioöuü',
			),
			array(
				'suffix' => 'sa',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'se',
				'vowels' => 'eiöü',
			),
			array(
				'suffix' => 'ma',
				'vowels' => 'aıou',
			),
			array(
				'suffix' => 'me',
				'vowels' => 'eiöü',
			),
		);
	}

	/**
	 * @param list<array{suffix: string, vowels: string, minStem?: int}> $suffixes
	 */
	private function stripGroup( string $word, array $suffixes ): string {
		for ( $i = 0; $i < 4; $i++ ) {
			$stripped = $this->stripOne( $word, $suffixes );
			if ( $stripped === null ) {
				return $word;
			}
			$word = $stripped;
		}
		return $word;
	}

	/**
	 * @param list<array{suffix: string, vowels: string, minStem?: int}> $suffixes
	 */
	private function stripOne( string $word, array $suffixes ): ?string {
		foreach ( $suffixes as $rule ) {
			$suffix = $rule['suffix'];
			if ( ! str_ends_with( $word, $suffix ) ) {
				continue;
			}
			$stem = mb_substr( $word, 0, mb_strlen( $word, 'UTF-8' ) - mb_strlen( $suffix, 'UTF-8' ), 'UTF-8' );
			$min  = $rule['minStem'] ?? 3;
			if ( mb_strlen( $stem, 'UTF-8' ) < $min || ! $this->hasVowel( $stem ) ) {
				if ( ! in_array( $stem, self::SHORT_ROOTS, true ) ) {
					continue;
				}
			}
			if ( ! $this->harmonizes( $stem, $rule['vowels'] ) ) {
				continue;
			}
			return $stem;
		}
		return null;
	}

	private function hasVowel( string $word ): bool {
		return (bool) preg_match( '/[aeıioöuü]/u', $word );
	}

	private function lastVowel( string $word ): string {
		if ( preg_match_all( '/[aeıioöuü]/u', $word, $matches ) === false || $matches[0] === array() ) {
			return '';
		}
		$found = $matches[0];
		return $found[ count( $found ) - 1 ];
	}

	private function harmonizes( string $stem, string $allowed ): bool {
		$vowel = $this->lastVowel( $stem );
		return $vowel !== '' && str_contains( $allowed, $vowel );
	}

	private function lower( string $word ): string {
		$word = str_replace( array( 'I', 'İ' ), array( 'ı', 'i' ), $word );
		return mb_strtolower( trim( $word ), 'UTF-8' );
	}
}
