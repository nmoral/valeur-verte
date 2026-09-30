<?php

declare(strict_types=1);

namespace App\Tests\Simulateur;

use App\BackOffice\Rapport\Dpe;
use App\Simulateur\SimulateurLead;
use App\Simulateur\SimulateurLeadRepository;
use App\Simulateur\Utilisation;
use App\Simulateur\UtilisationRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Simulateur public (#385) : les deux persistances séparées par intention RGPD —
 * le compteur d'usage **anonyme** (toujours incrémenté) et les leads **consentis**
 * (écrits seulement sur consentement explicite).
 */
final class PersistanceSimulateurTest extends KernelTestCase
{
    public function testLeCompteurDUsageEstAnonymeEtSAdditionne(): void
    {
        self::bootKernel();
        $utilisations = self::getContainer()->get(UtilisationRepository::class);
        self::assertInstanceOf(UtilisationRepository::class, $utilisations);

        $depart = $utilisations->compter();

        $utilisations->enregistrer(Utilisation::estimation(disponible: true, codeDepartement: '31'));
        $utilisations->enregistrer(Utilisation::estimation(disponible: false, codeDepartement: null));

        self::assertSame($depart + 2, $utilisations->compter());
    }

    public function testUnLeadConsentiEstConserve(): void
    {
        self::bootKernel();
        $leads = self::getContainer()->get(SimulateurLeadRepository::class);
        self::assertInstanceOf(SimulateurLeadRepository::class, $leads);

        $depart = $leads->compter();

        $leads->enregistrer(SimulateurLead::creer(
            'visiteur@example.com',
            '31555',
            'Toulouse',
            95,
            300,
            Dpe::E,
            Dpe::C,
            52210,
        ));

        self::assertSame($depart + 1, $leads->compter());
    }
}
