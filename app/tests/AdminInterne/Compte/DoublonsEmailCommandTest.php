<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Contrôle préalable au déploiement de l'unicité (#331) : la migration qui pose
 * l'index unique échoue si l'existant porte déjà deux écritures d'une même
 * adresse. La commande dit lesquelles, avant que la migration ne le découvre.
 *
 * Le regroupement lui-même est éprouvé sans base par DoublonsIdentiteTest : ici
 * on vérifie le câblage et le verdict rendu sur une base saine — l'index en place
 * interdit précisément de fabriquer une collision.
 */
final class DoublonsEmailCommandTest extends KernelTestCase
{
    public function testSansCollisionLaCommandePasseEtLeDitSansAmbiguite(): void
    {
        self::bootKernel();
        $this->seedUser('distinct-un@example.com');
        $this->seedUser('distinct-deux@example.com');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Aucune collision', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:comptes:doublons-email'));
    }

    private function seedUser(string $email): void
    {
        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $utilisateurs->enregistrer(new User($email, ['ROLE_PRO']));
    }
}
