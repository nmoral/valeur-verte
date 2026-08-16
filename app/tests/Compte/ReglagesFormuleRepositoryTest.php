<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Formule;
use App\Compte\ParametresFormule;
use App\Compte\ReglagesFormuleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Réglages des formules (#61/#65), éditables en base : la source de vérité des
 * paramètres d'essai. Tant qu'une formule n'est pas réglée, ses défauts sont
 * servis ; un réglage prend ensuite le relais, formule par formule.
 */
final class ReglagesFormuleRepositoryTest extends KernelTestCase
{
    private ReglagesFormuleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testSansReglageLesParametresParDefautSontServis(): void
    {
        $parametres = $this->repository->pour(Formule::Fondateur);

        self::assertSame(180, $parametres->dureeEssaiJours());
        self::assertSame(100, $parametres->quotaRapports());
        self::assertSame(10, $parametres->plafondComptes());
    }

    public function testUnReglageEnregistrePrendLeRelais(): void
    {
        $this->repository->enregistrer(Formule::Classique, new ParametresFormule(90, 15, null));

        self::assertEquals(
            new ParametresFormule(90, 15, null),
            $this->repository->pour(Formule::Classique),
        );
    }

    public function testUnReglageNAffectePasLesAutresFormules(): void
    {
        $this->repository->enregistrer(Formule::Classique, new ParametresFormule(90, 15, null));

        // Fondateur, non réglé, garde ses défauts.
        self::assertSame(180, $this->repository->pour(Formule::Fondateur)->dureeEssaiJours());
    }

    public function testReglerDeuxFoisLaMemeFormuleRemplaceSansDupliquer(): void
    {
        $this->repository->enregistrer(Formule::Classique, new ParametresFormule(90, 15, null));
        $this->repository->enregistrer(Formule::Classique, new ParametresFormule(75, 12, null));

        self::assertSame(75, $this->repository->pour(Formule::Classique)->dureeEssaiJours());
        self::assertSame(12, $this->repository->pour(Formule::Classique)->quotaRapports());
    }
}
