<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\IndicateurFiabilite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndicateurFiabiliteAffichageTest extends TestCase
{
    #[DataProvider('attributs')]
    public function testChaqueNiveauPorteSesAttributsDAffichage(
        IndicateurFiabilite $indicateur,
        string $pastille,
        string $couleur,
        string $libelle,
    ): void {
        self::assertSame($pastille, $indicateur->pastille());
        self::assertSame($couleur, $indicateur->couleur());
        self::assertSame($libelle, $indicateur->libelle());
    }

    /**
     * @return iterable<string, array{IndicateurFiabilite, string, string, string}>
     */
    public static function attributs(): iterable
    {
        yield 'solide' => [IndicateurFiabilite::Solide, '🟢', 'vert', 'Solide'];
        yield 'correct' => [IndicateurFiabilite::Correct, '🟠', 'orange', 'Correct'];
        yield 'fragile' => [IndicateurFiabilite::Fragile, '🔴', 'rouge', 'Fragile'];
    }

    /**
     * Seul le niveau Fragile déclenche le bandeau d'avertissement (§5.3) :
     * repli départemental ou effectif insuffisant doivent être signalés au lecteur.
     */
    #[DataProvider('bandeau')]
    public function testSeulLeNiveauFragileExigeUnBandeau(IndicateurFiabilite $indicateur, bool $attendu): void
    {
        self::assertSame($attendu, $indicateur->exigeBandeau());
    }

    /**
     * @return iterable<string, array{IndicateurFiabilite, bool}>
     */
    public static function bandeau(): iterable
    {
        yield 'solide sans bandeau' => [IndicateurFiabilite::Solide, false];
        yield 'correct sans bandeau' => [IndicateurFiabilite::Correct, false];
        yield 'fragile avec bandeau' => [IndicateurFiabilite::Fragile, true];
    }
}
