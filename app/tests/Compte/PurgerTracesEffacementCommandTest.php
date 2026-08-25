<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Point d'entrée cron de la purge des traces d'effacement (#332, rétention 12
 * mois) : la commande s'exécute et rend compte. Sans trace périmée, elle réussit
 * sans rien supprimer.
 */
final class PurgerTracesEffacementCommandTest extends KernelTestCase
{
    public function testLaCommandeSExecuteEtRendCompte(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:compte:purger-traces-effacement'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('trace', $tester->getDisplay());
    }
}
