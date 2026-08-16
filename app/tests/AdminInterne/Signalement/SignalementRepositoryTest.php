<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Signalement;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : les signalements sont exercés contre un vrai PostGIS,
 * chaque test isolé par une transaction annulée (DAMA).
 */
final class SignalementRepositoryTest extends KernelTestCase
{
    private SignalementRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(Signalement::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testEnregistreEtReliteLesSignalementsLesPlusRecentsDAbord(): void
    {
        $this->repository->enregistrer($this->signalement('Baziège', '2026-08-13 09:00:00'));
        $this->repository->enregistrer($this->signalement('Montgiscard', '2026-08-13 11:00:00'));

        $this->viderIdentityMap();
        $recents = $this->repository->recents();

        self::assertCount(2, $recents);
        self::assertSame('Montgiscard', $recents[0]->adresse());
        self::assertSame('Baziège', $recents[1]->adresse());
    }

    public function testLeContexteEtLeMessageSontPersistesEtRelus(): void
    {
        $signalement = Signalement::creer(
            adresse: 'Baziège',
            contexte: ['dpe_avant' => 'E', 'dpe_apres' => 'C', 'n' => 134],
            message: 'Gain trop élevé',
            signaleLe: new \DateTimeImmutable('2026-08-13 10:00:00'),
        );
        $this->repository->enregistrer($signalement);

        $this->viderIdentityMap();
        $relu = $this->repository->recents()[0];

        self::assertSame(['dpe_avant' => 'E', 'dpe_apres' => 'C', 'n' => 134], $relu->contexte());
        self::assertSame('Gain trop élevé', $relu->message());
    }

    private function signalement(string $adresse, string $signaleLe): Signalement
    {
        return Signalement::creer($adresse, ['n' => 100], null, new \DateTimeImmutable($signaleLe));
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
