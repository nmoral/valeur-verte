<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\GrilleCout\Poste;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Persistance et liste des bouquets du pro (#35, §9.2). Tant que le pro n'a rien
 * configuré, la liste par défaut — les trois bouquets types — reste servie (ONB-3),
 * puis ses bouquets prennent le relais. C'est la source de la sélection en RDV (#29).
 */
final class BouquetRepositoryTest extends KernelTestCase
{
    private BouquetRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(BouquetRepository::class);
        self::assertInstanceOf(BouquetRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testSansConfigurationLaListeParDefautEstServie(): void
    {
        $noms = array_map(
            static fn (Bouquet $bouquet): string => $bouquet->nom(),
            $this->repository->disponibles(),
        );

        self::assertSame(
            ["Rénovation d'ampleur standard", 'Isolation seule', 'Chauffage seul'],
            $noms,
        );
    }

    public function testUnBouquetEnregistrePrendLeRelaisDesDefauts(): void
    {
        $this->repository->enregistrer(Bouquet::creer('Sur mesure', Poste::Murs, Poste::Chauffage));

        $this->viderIdentityMap();
        $disponibles = $this->repository->disponibles();

        self::assertCount(1, $disponibles);
        self::assertEquals(Bouquet::creer('Sur mesure', Poste::Murs, Poste::Chauffage), $disponibles[0]);
    }

    public function testEnregistrerUnMemeNomMetAJourSansDupliquer(): void
    {
        $this->repository->enregistrer(Bouquet::creer('Sur mesure', Poste::Murs));
        $this->repository->enregistrer(Bouquet::creer('Sur mesure', Poste::Murs, Poste::Combles, Poste::Vmc));

        $this->viderIdentityMap();
        $disponibles = $this->repository->disponibles();

        self::assertCount(1, $disponibles);
        self::assertEqualsCanonicalizing(
            [Poste::Murs, Poste::Combles, Poste::Vmc],
            $disponibles[0]->postes(),
        );
    }

    public function testParNomResoutUnBouquetEnregistre(): void
    {
        $this->repository->enregistrer(Bouquet::creer('Sur mesure', Poste::Murs));

        self::assertEquals(
            Bouquet::creer('Sur mesure', Poste::Murs),
            $this->repository->parNom('Sur mesure'),
        );
        self::assertNull($this->repository->parNom('Inexistant'));
    }

    public function testSupprimerRetireLeBouquet(): void
    {
        $this->repository->enregistrer(Bouquet::creer('Sur mesure', Poste::Murs));
        $this->repository->enregistrer(Bouquet::creer('Autre', Poste::Combles));

        $this->repository->supprimer('Sur mesure');

        $this->viderIdentityMap();
        $noms = array_map(
            static fn (Bouquet $bouquet): string => $bouquet->nom(),
            $this->repository->disponibles(),
        );
        self::assertSame(['Autre'], $noms);
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
