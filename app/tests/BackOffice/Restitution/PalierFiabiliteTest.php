<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\PalierFiabilite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tier de fiabilité de la maille (#169) : STANDARD sous 20 % d'erreur médiane,
 * DÉGRADÉ entre 20 et 30 % (servi avec bandeau). Distinct de l'indicateur
 * Solide/Correct/Fragile (dérivé de n et rayon) : c'est un second canal, porté
 * par la colonne `palier` de la maille.
 */
final class PalierFiabiliteTest extends TestCase
{
    public function testLePalierDegradeSeSignale(): void
    {
        self::assertTrue(PalierFiabilite::Degrade->estDegrade());
        self::assertFalse(PalierFiabilite::Standard->estDegrade());
    }

    /**
     * Traduction du contrat data→app : seul `degrade` marque le tier dégradé ;
     * toute autre valeur — `standard`, absente (ligne héritée) ou inconnue —
     * retombe sur STANDARD (pas de bandeau injustifié).
     */
    #[DataProvider('lectures')]
    public function testElleSeDeduitDeLaColonne(?string $palier, PalierFiabilite $attendu): void
    {
        self::assertSame($attendu, PalierFiabilite::depuis($palier));
    }

    /**
     * @return iterable<string, array{string|null, PalierFiabilite}>
     */
    public static function lectures(): iterable
    {
        yield 'standard' => ['standard', PalierFiabilite::Standard];
        yield 'dégradé' => ['degrade', PalierFiabilite::Degrade];
        yield 'legacy absent → standard' => [null, PalierFiabilite::Standard];
        yield 'inconnu → standard' => ['???', PalierFiabilite::Standard];
    }
}
