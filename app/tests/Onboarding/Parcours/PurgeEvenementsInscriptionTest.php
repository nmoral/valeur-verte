<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Parcours;

use App\Onboarding\Parcours\EvenementInscription;
use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\PurgeEvenementsInscription;
use App\Onboarding\Parcours\TypeEvenementInscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Rétention courte des événements du parcours d'inscription (#111) : la collecte
 * est anonyme et déclarée pour une finalité de mesure de l'activation. Passé le
 * délai, les événements sont supprimés — la purge est le pendant obligatoire de la
 * collecte, à jouer par un cron (registre #190).
 */
final class PurgeEvenementsInscriptionTest extends KernelTestCase
{
    private const int RETENTION_JOURS = 90;
    private const string MAINTENANT = '2026-08-25 12:00:00';
    private const string SESSION = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    public function testLesEvenementsAuDelaDeLaRetentionSontSupprimes(): void
    {
        self::bootKernel();
        $this->evenementSurvenuLe('2026-05-01 09:00:00');

        $supprimes = $this->purge()->executer();

        self::assertSame(1, $supprimes);
        self::assertCount(0, $this->evenements()->pourSession(self::SESSION));
    }

    public function testLesEvenementsDansLaFenetreSontConserves(): void
    {
        self::bootKernel();
        $this->evenementSurvenuLe('2026-08-24 09:00:00');

        $supprimes = $this->purge()->executer();

        self::assertSame(0, $supprimes);
        self::assertCount(1, $this->evenements()->pourSession(self::SESSION));
    }

    private function purge(): PurgeEvenementsInscription
    {
        return new PurgeEvenementsInscription(
            $this->evenements(),
            new MockClock(new \DateTimeImmutable(self::MAINTENANT)),
            self::RETENTION_JOURS,
        );
    }

    private function evenementSurvenuLe(string $survenuLe): void
    {
        $this->evenements()->enregistrer(
            EvenementInscription::survenu(
                self::SESSION,
                TypeEvenementInscription::InscriptionAffichee,
                new \DateTimeImmutable($survenuLe),
            ),
        );
    }

    private function evenements(): EvenementInscriptionRepository
    {
        $repository = self::getContainer()->get(EvenementInscriptionRepository::class);
        self::assertInstanceOf(EvenementInscriptionRepository::class, $repository);

        return $repository;
    }
}
