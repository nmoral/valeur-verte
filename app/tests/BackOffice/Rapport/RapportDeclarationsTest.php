<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\BackOffice\Restitution\DeclarationsPro;
use App\BackOffice\Restitution\Restitution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Déclarations du pro (#26) portées par le rapport, dans un champ **distinct** de
 * l'instantané figé — même séparation que l'ajustement (#30). Le pro peut revenir
 * sur la situation de revenus ou son chiffrage sans que la mesure statistique
 * bouge, et sans réémettre un rapport.
 */
final class RapportDeclarationsTest extends KernelTestCase
{
    private const string REFERENCE = 'VV-20260815-DC1234';

    private RapportRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testUnRapportNeufNaAucuneDeclaration(): void
    {
        // Un objet exploitable est toujours servi, jamais null : le reste à charge
        // doit pouvoir se calculer sans branche de garde.
        $declarations = $this->rapport()->declarations();

        self::assertSame(CategorieRevenu::NonPrecisee, $declarations->categorieRevenu());
        self::assertFalse($declarations->aDesRetouches());
    }

    public function testLesDeclarationsSontPersisteesEtReluesDepuisLaBase(): void
    {
        $rapport = $this->rapport();
        $rapport->declarerPar(DeclarationsPro::depuis(
            CategorieRevenu::Modeste,
            [Poste::Menuiseries->name => 8400],
        ));
        $this->repository->enregistrer($rapport);

        $this->viderIdentityMap();
        $relu = $this->repository->parReference(self::REFERENCE);

        self::assertInstanceOf(Rapport::class, $relu);
        self::assertSame(CategorieRevenu::Modeste, $relu->declarations()->categorieRevenu());
        self::assertTrue($relu->declarations()->aDesRetouches());
    }

    public function testDeclarerNAlterePasLInstantaneFige(): void
    {
        $rapport = $this->rapport();
        $rapport->declarerPar(DeclarationsPro::depuis(CategorieRevenu::TresModeste, []));
        $this->repository->enregistrer($rapport);

        $this->viderIdentityMap();
        $relu = $this->repository->parReference(self::REFERENCE);

        self::assertInstanceOf(Rapport::class, $relu);
        self::assertSame(69972, $relu->instantane()->restitution()?->montantEuros());
        self::assertSame(69972, $relu->valeurVerteEstimee());
    }

    public function testDeclarerDeNouveauRemplaceLaDeclarationPrecedente(): void
    {
        // Le pro affine sa saisie : c'est une correction, pas un empilement.
        $rapport = $this->rapport();
        $rapport->declarerPar(DeclarationsPro::depuis(CategorieRevenu::TresModeste, []));
        $rapport->declarerPar(DeclarationsPro::depuis(CategorieRevenu::Superieur, []));
        $this->repository->enregistrer($rapport);

        $this->viderIdentityMap();
        $relu = $this->repository->parReference(self::REFERENCE);

        self::assertInstanceOf(Rapport::class, $relu);
        self::assertSame(CategorieRevenu::Superieur, $relu->declarations()->categorieRevenu());
    }

    private function rapport(): Rapport
    {
        return Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-15 10:00:00'),
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
            new \DateTimeImmutable('2026-08-15 10:00:00'),
        );
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
