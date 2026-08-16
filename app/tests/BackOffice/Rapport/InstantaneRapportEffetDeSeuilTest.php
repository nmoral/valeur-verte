<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

/**
 * L'effet de seuil (#27) est figé dans l'instantané (v3) : le PDF doit se
 * régénérer à l'identique, table de transitions comprise, sans jamais recalculer
 * — la maille vive peut changer d'un millésime à l'autre.
 */
final class InstantaneRapportEffetDeSeuilTest extends TestCase
{
    public function testLEffetDeSeuilEstFigeEtRelu(): void
    {
        $instantane = $this->instantane($this->effetDeSeuil());

        $relu = InstantaneRapport::fromArray($instantane->toArray());

        $effet = $relu->effetDeSeuil();
        self::assertNotNull($effet);
        $paliers = $effet->paliers();
        self::assertSame(['D', 'C'], array_map(static fn ($p): string => $p->classeCible()->value, $paliers));
        self::assertSame([32579, 51482], array_map(static fn ($p): int => $p->gainEuros(), $paliers));
        self::assertTrue($paliers[1]->estSeuil());
        self::assertTrue($paliers[1]->estCible());
    }

    public function testUnInstantaneAncienSansEffetDeSeuilResteRelisible(): void
    {
        // Les rapports émis avant #27 n'ont pas d'effet de seuil : ils se relisent
        // sans table, plutôt qu'en erreur.
        $ancien = $this->instantane(null)->toArray();
        unset($ancien['effet_de_seuil']);

        $relu = InstantaneRapport::fromArray($ancien);

        self::assertNull($relu->effetDeSeuil());
        self::assertSame('12 rue des Lilas, Toulouse', $relu->saisie()->adresse());
    }

    private function effetDeSeuil(): EffetDeSeuil
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, ['E' => -0.20, 'D' => 0.0, 'C' => 0.10], 147150);
        self::assertNotNull($effet);

        return $effet;
    }

    private function instantane(?EffetDeSeuil $effetDeSeuil): InstantaneRapport
    {
        return InstantaneRapport::creer(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4),
            Restitution::depuis(51482, 42000, 60000, 134, 10, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            Branding::parDefaut(),
            ReferenceRapport::depuis('VV-20260816-SEUIL1'),
            new \DateTimeImmutable('2026-08-16 10:00:00'),
            effetDeSeuil: $effetDeSeuil,
        );
    }
}
