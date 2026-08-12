<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use PHPUnit\Framework\TestCase;

final class FicheEntrepriseTest extends TestCase
{
    public function testUneFicheAgregeIdentiteEtQualifications(): void
    {
        $fiche = FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            'Renov Habitat',
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs', 'Menuiseries'],
        );

        self::assertSame('12345678901234', $fiche->siret()->valeur());
        self::assertSame('Renov Habitat', $fiche->raisonSociale());
        self::assertSame('12 rue des Lilas, 31000 Toulouse', $fiche->adresse());
        self::assertSame('43.29A', $fiche->codeNaf());
        self::assertSame('6 à 9 salariés', $fiche->effectif());
        self::assertSame(StatutRge::Rge, $fiche->statutRge());
        self::assertSame(['Isolation des murs', 'Menuiseries'], $fiche->domainesRge());
    }

    public function testUneFicheNonRgeResteValideSansDomaines(): void
    {
        $fiche = FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            'Plomberie Durand',
            '5 place du Marché, 31000 Toulouse',
            '43.22A',
            null,
            StatutRge::NonRge,
            [],
        );

        self::assertSame(StatutRge::NonRge, $fiche->statutRge());
        self::assertSame([], $fiche->domainesRge());
        self::assertNull($fiche->effectif());
    }
}
