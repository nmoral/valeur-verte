<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Pdf\ReferenceRapport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identifiant de rapport unique (§9.6), imprimé en pied de PDF. Value object qui
 * n'accepte que le format normalisé « VV-AAAAMMJJ-XXXXXX » : sans lui, un pro ne
 * peut pas relier un PDF présenté en rendez-vous au rapport qui l'a produit.
 */
final class ReferenceRapportTest extends TestCase
{
    public function testAccepteUneReferenceBienFormee(): void
    {
        $reference = ReferenceRapport::depuis('VV-20260813-A1B2C3');

        self::assertSame('VV-20260813-A1B2C3', $reference->valeur());
    }

    #[DataProvider('referencesMalFormees')]
    public function testRejetteUneReferenceMalFormee(string $valeur): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReferenceRapport::depuis($valeur);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referencesMalFormees(): iterable
    {
        yield 'vide' => [''];
        yield 'sans préfixe' => ['20260813-A1B2C3'];
        yield 'date trop courte' => ['VV-202608-A1B2C3'];
        yield 'suffixe trop court' => ['VV-20260813-A1B2'];
        yield 'suffixe minuscule' => ['VV-20260813-a1b2c3'];
        yield 'texte libre' => ['rapport-42'];
    }
}
