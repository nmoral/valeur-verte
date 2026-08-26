<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\EpciMesures;
use App\Publication\LectureModelePublieDbal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : l'énumération nationale des EPCI mesurés (#365, sitemap)
 * liste tous les EPCI portant au moins une commune mesurée, dédoublonnés, en slugs
 * d'URL, sans gating (les hubs EPCI sont toujours indexables). Vide tant qu'aucun
 * millésime n'est publié.
 */
final class EpciMesuresTest extends KernelTestCase
{
    use ProvisionneModelePublie;
    private const COEF = '{"D":0.0,"C":0.11,"E":-0.19}';

    public function testListeLesSlugsDesEpciMesures(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerEpci('200069193', 'Toulouse Métropole');
        $this->semerEpci('200000009', 'CC Sans Mesure'); // aucune commune mesurée
        $this->semerCommuneMesuree('33063', 'Bordeaux', '33', '243300316');
        $this->semerCommuneMesuree('31555', 'Toulouse', '31', '200069193');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        // Ordre par nom : Bordeaux Métropole, Toulouse Métropole ; CC Sans Mesure absent.
        self::assertSame(['bordeaux-metropole', 'toulouse-metropole'], $this->epci()->slugs());
    }

    public function testSansMillesimePublieAucunEpci(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommuneMesuree('33063', 'Bordeaux', '33', '243300316');

        self::assertSame([], $this->epci()->slugs());
    }

    private function epci(): EpciMesures
    {
        return new EpciMesures(new LectureModelePublieDbal($this->app()), $this->app());
    }

    private function semerCommuneMesuree(string $codeInsee, string $nom, string $departement, string $codeEpci): void
    {
        $this->semerCommune($codeInsee, $nom, $departement);
        $this->rattacherCommuneEpci($codeInsee, $codeEpci);
        $this->semerMaille($codeInsee, coefDpe: self::COEF);
    }
}
