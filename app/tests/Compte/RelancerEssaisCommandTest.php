<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Point d'entrée cron de la relance de fin d'essai (#108) : la commande s'exécute
 * et rend compte. Sans compte à relancer, elle réussit sans rien envoyer.
 */
final class RelancerEssaisCommandTest extends KernelTestCase
{
    public function testLaCommandeSExecuteEtRendCompte(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:essai:relancer'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('relance', $tester->getDisplay());
    }
}
