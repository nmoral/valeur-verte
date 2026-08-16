<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\BackOffice\Restitution\AjustementProfessionnel;
use App\BackOffice\Restitution\Restitution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ligne d'ajustement du professionnel (#30) portée par le rapport, dans un champ
 * **distinct** de l'instantané statistique. L'instantané reste figé (#33, §9.1) :
 * l'ajustement est l'annotation propre au pro, jamais une surcharge de la mesure.
 * On vérifie ici la persistance séparée et que la mesure figée n'est pas touchée.
 */
final class RapportAjustementTest extends KernelTestCase
{
    private const REFERENCE = 'VV-20260814-AJ1234';

    private RapportRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testUnRapportNeufNaPasDAjustement(): void
    {
        self::assertNull($this->rapport()->ajustement());
    }

    public function testLAjustementEstPersisteEtReluDepuisLaBase(): void
    {
        $rapport = $this->rapport();
        $rapport->ajusterPar(AjustementProfessionnel::depuis(-5000, 'État général du bien'));
        $this->repository->enregistrer($rapport);

        $this->viderIdentityMap();
        $relu = $this->repository->parReference(self::REFERENCE);

        self::assertInstanceOf(Rapport::class, $relu);
        $ajustement = $relu->ajustement();
        self::assertInstanceOf(AjustementProfessionnel::class, $ajustement);
        self::assertSame(-5000, $ajustement->montantEuros());
        self::assertSame('État général du bien', $ajustement->motif());
    }

    public function testLAjustementNAlterePasLaMesureFigee(): void
    {
        $rapport = $this->rapport();
        $rapport->ajusterPar(AjustementProfessionnel::depuis(-5000, 'État général du bien'));
        $this->repository->enregistrer($rapport);

        $this->viderIdentityMap();
        $relu = $this->repository->parReference(self::REFERENCE);

        self::assertInstanceOf(Rapport::class, $relu);
        // La restitution statistique de l'instantané reste intacte…
        self::assertSame(69972, $relu->instantane()->restitution()?->montantEuros());
        // …et la valeur de listing reste la mesure, jamais l'estimation retenue.
        self::assertSame(69972, $relu->valeurVerteEstimee());
    }

    private function rapport(): Rapport
    {
        return Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-14 10:00:00'),
            Source::Audit,
            $this->instantane(),
            '/rapport/'.self::REFERENCE.'/pdf',
        );
    }

    private function instantane(): InstantaneRapport
    {
        return InstantaneRapport::creer(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4),
            Restitution::depuis(69972, 55000, 85000, 134, 10, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            Branding::parDefaut(),
            ReferenceRapport::depuis(self::REFERENCE),
            new \DateTimeImmutable('2026-08-14 10:00:00'),
        );
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
