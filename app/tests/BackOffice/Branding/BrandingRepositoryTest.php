<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Branding\Couleur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BrandingRepositoryTest extends KernelTestCase
{
    private BrandingRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(BrandingRepository::class);
        self::assertInstanceOf(BrandingRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testSansRienConfigureLaMarqueCouranteEstCelleParDefaut(): void
    {
        $courant = $this->repository->courant();

        self::assertEquals(Couleur::depuisHex('#0E4A5C'), $courant->couleurPrincipale());
        self::assertNull($courant->numeroRge());
    }

    public function testLaMarqueEnregistreeEstRelueDepuisLaBase(): void
    {
        $this->repository->enregistrer(Branding::creer(
            Couleur::depuisHex('#B8480F'),
            'QB/54321',
            '05 61 00 00 00',
            null,
            null,
        ));

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $courant = $this->repository->courant();
        self::assertSame('#B8480F', $courant->couleurPrincipale()->hex());
        self::assertSame('QB/54321', $courant->numeroRge());
        self::assertSame('05 61 00 00 00', $courant->telephone());
    }
}
