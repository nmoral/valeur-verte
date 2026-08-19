<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Constats;
use App\Publication\MetriqueDepartement;
use PHPUnit\Framework\TestCase;

/**
 * Les constats chiffrés de la page publique (#48/#224), dérivés des métriques
 * départementales du millésime publié — plus aucun département ni pourcentage
 * figé dans le gabarit.
 *
 * Les listes citent les départements extrêmes, groupés par valeur égale, en
 * accumulant les paliers jusqu'à en nommer au moins cinq.
 */
final class ConstatsTest extends TestCase
{
    public function testLesGainsLesPlusFortsSontGroupesParValeurJusquACinqDepartements(): void
    {
        $constats = Constats::depuis(self::jeu());

        // gain E→C décroissant : 36 (1) → 35 (2) → 32 (2) = cinq nommés, on s'arrête.
        $forts = $constats->gainsLesPlusForts;
        self::assertSame([36, 35, 32], array_map(static fn ($g) => $g->valeur, $forts));
        self::assertSame(['Indre'], $forts[0]->noms);
        self::assertSame(['Vienne', 'Meuse'], $forts[1]->noms);
        self::assertSame(['Haute-Marne', 'Orne'], $forts[2]->noms);
    }

    public function testLesGainsLesPlusFaiblesSontGroupesEnPartantDuBas(): void
    {
        $constats = Constats::depuis(self::jeu());

        // gain E→C croissant : 5 (1) → 20 (1) → 31 (2) → 32 (2) = six nommés.
        $faibles = $constats->gainsLesPlusFaibles;
        self::assertSame([5, 20, 31, 32], array_map(static fn ($g) => $g->valeur, $faibles));
        self::assertSame(['Ardennes'], $faibles[0]->noms);
        self::assertSame(['Mayenne', 'Cher'], $faibles[2]->noms);
    }

    public function testLEcartDuSecondPalierClasseParDeltaEcMoinsEd(): void
    {
        $constats = Constats::depuis(self::jeu());

        // delta = gain E→C − gain E→D, décroissant : 19 (Orne, Mayenne) → 18
        // (Haute-Marne) → 17 (Indre, Vienne, Cher) = six nommés, seuil au dernier palier.
        $ecart = $constats->ecartSecondPalier;
        self::assertSame(['Orne', 'Mayenne', 'Haute-Marne', 'Indre', 'Vienne', 'Cher'], $ecart->noms);
        self::assertSame(17, $ecart->seuil);
    }

    public function testCompteLesDepartementsQuiDepassentTrentePourCent(): void
    {
        self::assertSame(7, Constats::depuis(self::jeu())->departementsAuDela30);
    }

    public function testUnDepartementSansGainEstIgnore(): void
    {
        $metriques = [
            self::metrique('36', 'Indre', 36, 19),
            self::metrique('35', 'Sans gain', null, null),
            self::metrique('34', 'Gain partiel', 40, null),
        ];

        $constats = Constats::depuis($metriques);

        // « Sans gain » sort de tous les classements ; « Gain partiel » compte pour
        // le gain E→C (40) mais pas pour l'écart du second palier (E→D manquant).
        self::assertSame(['Gain partiel', 'Indre'], array_merge(...array_map(static fn ($g) => $g->noms, $constats->gainsLesPlusForts)));
        self::assertSame(['Indre'], $constats->ecartSecondPalier->noms);
        self::assertSame(2, $constats->departementsAuDela30);
    }

    /**
     * @return list<MetriqueDepartement>
     */
    private static function jeu(): array
    {
        return [
            self::metrique('36', 'Indre', 36, 19),        // delta 17
            self::metrique('86', 'Vienne', 35, 18),       // delta 17
            self::metrique('55', 'Meuse', 35, 20),        // delta 15
            self::metrique('52', 'Haute-Marne', 32, 14),  // delta 18
            self::metrique('61', 'Orne', 32, 13),         // delta 19
            self::metrique('53', 'Mayenne', 31, 12),      // delta 19 → mais gainEc plus bas
            self::metrique('18', 'Cher', 31, 14),         // delta 17
            self::metrique('20', 'Milieu', 20, 12),       // delta 8
            self::metrique('08', 'Ardennes', 5, 3),       // delta 2
        ];
    }

    private static function metrique(string $code, string $nom, ?int $gainEc, ?int $gainEd): MetriqueDepartement
    {
        return new MetriqueDepartement($code, $nom, 100, $gainEc, $gainEd, 0);
    }
}
