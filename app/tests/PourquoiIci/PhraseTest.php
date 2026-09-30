<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * Une phrase du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * Deux natures, portées par la phrase elle-même : la phrase MESURÉE est un fait
 * (pas de badge) ; la phrase CONTEXTUELLE est une hypothèse (badge « Hypothèse »
 * au rendu). Le principe cardinal de l'issue — « le chiffre est mesuré,
 * l'explication est une hypothèse » — vit dans cette distinction.
 */
final class PhraseTest extends TestCase
{
    public function testUnePhraseMesureeEstUnFaitSansBadge(): void
    {
        $phrase = Phrase::mesure('Ici, rénover E→C ajoute +35 %.');

        self::assertSame('Ici, rénover E→C ajoute +35 %.', $phrase->texte);
        self::assertFalse($phrase->estHypothese());
    }

    public function testUnePhraseContextuelleEstUneHypothese(): void
    {
        $phrase = Phrase::hypothese('Département froid : isoler paie probablement.');

        self::assertSame('Département froid : isoler paie probablement.', $phrase->texte);
        self::assertTrue($phrase->estHypothese());
    }
}
