<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\LectureModelePublieDbal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la page publique lit le schéma « data » en lecture seule.
 *
 * Contrat REG-2 (#130) : ce qui est servi est le millésime **publié**, c'est-à-dire
 * la table vive ``data.coefficient_maille`` — jamais le dernier millésime calculé,
 * qui vit dans l'historique tant qu'un humain ne l'a pas publié.
 */
final class LectureModelePublieDbalTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testAucunePublicationQuandAucunMillesimeNAEteValide(): void
    {
        $this->prive();

        self::assertNull($this->lecture()->publication());
    }

    public function testUnMillesimeAbandonneNEstPasUnePublication(): void
    {
        $this->deciderMillesime(2025, 'abandonne', '2026-02-10 09:30:00');

        self::assertNull($this->lecture()->publication());
    }

    public function testLaPublicationPorteLeMillesimeSaDateEtLaDateDeCalcul(): void
    {
        $this->semerMaille('31001', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('31002', calculeLe: '2026-02-08 05:40:00');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $publication = $this->lecture()->publication();

        self::assertNotNull($publication);
        self::assertSame(2025, $publication->millesime);
        self::assertSame('2026-02-10 09:30', $publication->publieLe->format('Y-m-d H:i'));
        self::assertSame('2026-02-08 05:40', $publication->calculeLe->format('Y-m-d H:i'), 'Le calcul le plus récent du millésime.');
    }

    public function testLeDernierMillesimePublieLEmporte(): void
    {
        $this->deciderMillesime(2024, 'publie', '2025-02-10 09:30:00');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $publication = $this->lecture()->publication();

        self::assertNotNull($publication);
        self::assertSame(2025, $publication->millesime);
    }

    public function testLesMaillesLuesSontCellesDeLaTableVive(): void
    {
        $this->semerMaille('31001', mode: 'local', coefDpe: '{"D":0.0,"C":0.11,"E":-0.19}');
        $this->semerMaille('09001', mode: 'departemental', coefDpe: '{"D":0.0,"C":0.05,"E":-0.05}');
        $this->semerMailleArchivee('12001');

        $mailles = $this->lecture()->mailles();

        self::assertSame(['09001', '31001'], array_map(static fn ($maille) => $maille->codeInsee, $mailles));
        self::assertSame(35, $mailles[1]->gain('E', 'C'));
        self::assertSame('departemental', $mailles[0]->mode);
    }

    public function testLeNombreDeCommunesReferenceesVientDuReferentiel(): void
    {
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerCommune('09001', 'Foix', '09');

        self::assertSame(2, $this->lecture()->communesReferencees());
    }

    private function lecture(): LectureModelePublieDbal
    {
        return new LectureModelePublieDbal($this->app());
    }
}
