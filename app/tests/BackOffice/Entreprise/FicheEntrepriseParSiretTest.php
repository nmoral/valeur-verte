<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\FicheEntrepriseParSiret;
use App\BackOffice\Entreprise\IdentiteEntreprise;
use App\BackOffice\Entreprise\QualificationRge;
use App\BackOffice\Entreprise\QualificationsRge;
use App\BackOffice\Entreprise\RechercheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use PHPUnit\Framework\TestCase;

final class FicheEntrepriseParSiretTest extends TestCase
{
    public function testIdentiteEtQualificationsSontFusionneesEnUneFiche(): void
    {
        $service = new FicheEntrepriseParSiret(
            $this->rechercheRenvoyant(IdentiteEntreprise::creer('Renov Habitat', '12 rue des Lilas', '43.29A', '6 à 9 salariés')),
            $this->qualificationRenvoyant(QualificationRge::rge(['Isolation des murs'])),
        );

        $fiche = $service->parSiret(Siret::depuis('12345678901234'));

        self::assertNotNull($fiche);
        self::assertSame('Renov Habitat', $fiche->raisonSociale());
        self::assertSame('43.29A', $fiche->codeNaf());
        self::assertSame(StatutRge::Rge, $fiche->statutRge());
        self::assertSame(['Isolation des murs'], $fiche->domainesRge());
    }

    public function testUnRgeIndetermineNeBloquePasLaFiche(): void
    {
        $service = new FicheEntrepriseParSiret(
            $this->rechercheRenvoyant(IdentiteEntreprise::creer('Plomberie Durand', '5 place du Marché', '43.22A', null)),
            $this->qualificationRenvoyant(QualificationRge::indeterminee()),
        );

        $fiche = $service->parSiret(Siret::depuis('12345678901234'));

        self::assertNotNull($fiche, 'Une source RGE muette ne doit pas priver l\'artisan de sa fiche.');
        self::assertSame(StatutRge::Indetermine, $fiche->statutRge());
        self::assertSame([], $fiche->domainesRge());
    }

    public function testUneIdentiteIntrouvableDonneNull(): void
    {
        $service = new FicheEntrepriseParSiret(
            $this->rechercheRenvoyant(null),
            $this->qualificationRenvoyant(QualificationRge::nonRge()),
        );

        self::assertNull($service->parSiret(Siret::depuis('12345678901234')));
    }

    private function rechercheRenvoyant(?IdentiteEntreprise $identite): RechercheEntreprise
    {
        return new class($identite) implements RechercheEntreprise {
            public function __construct(private readonly ?IdentiteEntreprise $identite)
            {
            }

            public function parSiret(Siret $siret): ?IdentiteEntreprise
            {
                return $this->identite;
            }
        };
    }

    private function qualificationRenvoyant(QualificationRge $qualification): QualificationsRge
    {
        return new class($qualification) implements QualificationsRge {
            public function __construct(private readonly QualificationRge $qualification)
            {
            }

            public function pourSiret(Siret $siret): QualificationRge
            {
                return $this->qualification;
            }
        };
    }
}
