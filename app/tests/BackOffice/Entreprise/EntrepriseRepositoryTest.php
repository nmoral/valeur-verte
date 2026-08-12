<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntrepriseRepositoryTest extends KernelTestCase
{
    public function testUneFicheEstPersisteePuisReluePourSonTenant(): void
    {
        self::bootKernel();
        $conteneur = self::getContainer();
        $repository = $conteneur->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $repository);

        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            'Renov Habitat',
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs', 'Menuiseries'],
        ));
        $entreprise->setTenantId(42);
        $repository->enregistrer($entreprise);

        $manager = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $relue = $repository->findOneBy(['siret' => '12345678901234']);
        self::assertInstanceOf(Entreprise::class, $relue);
        self::assertSame('Renov Habitat', $relue->raisonSociale());
        self::assertSame('12 rue des Lilas, 31000 Toulouse', $relue->adresse());
        self::assertSame('43.29A', $relue->codeNaf());
        self::assertSame('6 à 9 salariés', $relue->effectif());
        self::assertSame(StatutRge::Rge, $relue->statutRge());
        self::assertSame(['Isolation des murs', 'Menuiseries'], $relue->domainesRge());
        self::assertSame(42, $relue->getTenantId());
    }
}
