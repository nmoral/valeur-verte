<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Persistance de l'instantané immuable (#33, §9.1, D5) : le contexte complet est
 * sérialisé en JSONB puis relu depuis la base à l'identique. Les caractéristiques
 * de listing (adresse, DPE, valeur) sont dérivées de l'instantané — source unique.
 */
final class RapportInstantaneTest extends KernelTestCase
{
    private RapportRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testLInstantaneEstPersisteEtReconstruitDepuisLaBase(): void
    {
        $this->repository->enregistrer($this->rapport());

        $this->viderIdentityMap();
        $relu = $this->repository->parReference('VV-20260813-A1B2C3');

        self::assertInstanceOf(Rapport::class, $relu);
        $instantane = $relu->instantane();
        self::assertEquals($this->saisie(), $instantane->saisie());
        self::assertEquals($this->restitution(), $instantane->restitution());
        self::assertEquals($this->branding(), $instantane->branding());
        self::assertEquals($this->contexte(), $instantane->contexte());
    }

    public function testLesCaracteristiquesDeListingSontDeriveesDeLInstantane(): void
    {
        $rapport = $this->rapport();

        self::assertSame('12 rue des Lilas, Toulouse', $rapport->adresse());
        self::assertSame(Dpe::E, $rapport->dpeAvant());
        self::assertSame(Dpe::C, $rapport->dpeApres());
        self::assertSame(69972, $rapport->valeurVerteEstimee());
    }

    private function rapport(): Rapport
    {
        return Rapport::creer(
            'VV-20260813-A1B2C3',
            new \DateTimeImmutable('2026-08-13 10:00:00'),
            Source::Audit,
            $this->instantane(),
            '/rapport/VV-20260813-A1B2C3/pdf',
        );
    }

    private function instantane(): InstantaneRapport
    {
        return InstantaneRapport::creer(
            $this->saisie(),
            $this->restitution(),
            $this->contexte(),
            $this->branding(),
            ReferenceRapport::depuis('VV-20260813-A1B2C3'),
            new \DateTimeImmutable('2026-08-13 10:00:00'),
        );
    }

    private function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4);
    }

    private function restitution(): \App\BackOffice\Restitution\Restitution
    {
        return \App\BackOffice\Restitution\Restitution::depuis(69972, 55000, 85000, 134, 10, 2024, 147150, false);
    }

    private function contexte(): ContexteCalcul
    {
        return ContexteCalcul::depuis(-0.20, 0.10, 17.22);
    }

    private function branding(): Branding
    {
        return Branding::creer(
            Couleur::depuisHex('#B8480F'),
            '1234-RGE-31',
            '05 61 00 00 00',
            'SARL Rénov Toulouse',
            'https://cdn.example.com/logo.png',
        );
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
