<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\AdministrationComptes;
use App\AdminInterne\Compte\DemanderSuppressionComptes;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Intégration : l'action de groupe admin « Supprimer » (#308) programme
 * l'effacement des comptes sélectionnés — même mécanisme et même délai de grâce
 * que le self-service — pour honorer une demande d'effacement reçue par email.
 */
final class DemanderSuppressionComptesTest extends KernelTestCase
{
    public function testLActionProgrammeLEffacementDesComptesSelectionnes(): void
    {
        self::bootKernel();
        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $comptes->enregistrer(Compte::creer(55, new \DateTimeImmutable('2026-01-01')));

        $administration = self::getContainer()->get(AdministrationComptes::class);
        self::assertInstanceOf(AdministrationComptes::class, $administration);
        $security = self::getContainer()->get(Security::class);
        self::assertInstanceOf(Security::class, $security);

        $action = new DemanderSuppressionComptes($administration, $security);
        $message = $action->executer(['55']);

        self::assertStringContainsString('1', $message);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertTrue($comptes->pourTenant(55)?->estEnAttenteDeSuppression());
    }
}
