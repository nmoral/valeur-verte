<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Point d'entrée de l'enrôlement (#312) : la commande jouée en SSH sur le VPS.
 * C'est elle qui ferme la fenêtre d'un enrôlement ouvert à qui se connecte le
 * premier — sans elle, personne ne peut enrôler quoi que ce soit.
 */
final class EnrolerSecondFacteurCommandTest extends KernelTestCase
{
    public function testLaCommandeRendUnLienDEnrolementUtilisable(): void
    {
        $tester = $this->commande();
        $this->seedAdmin('cli-admin@example.com');

        $tester->execute(['email' => 'cli-admin@example.com']);

        $tester->assertCommandIsSuccessful();
        // Le lien doit être collable tel quel dans un navigateur : c'est tout ce
        // que l'opérateur a en main.
        self::assertMatchesRegularExpression(
            '#https://admin\.[^\s]+/second-facteur/enrolement\?jeton=[0-9a-f]{64}#',
            $tester->getDisplay(),
        );
    }

    public function testLaCommandeRappelleLaDureeDeValidite(): void
    {
        $tester = $this->commande();
        $this->seedAdmin('cli-duree@example.com');

        $tester->execute(['email' => 'cli-duree@example.com']);

        // Un lien dont on ignore la durée de vie est un lien qu'on laisse traîner.
        self::assertStringContainsString('30 minutes', $tester->getDisplay());
    }

    public function testUnCompteInconnuEchoueSansRienEmettre(): void
    {
        $tester = $this->commande();

        $code = $tester->execute(['email' => 'fantome@example.com']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringNotContainsString('/second-facteur/enrolement', $tester->getDisplay());
    }

    public function testUnCompteSansRoleAdminEstRefuse(): void
    {
        $tester = $this->commande();
        $user = new User('cli-pro@example.com', ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);

        $code = $tester->execute(['email' => 'cli-pro@example.com']);

        // L'exigence ne porte que sur l'admin interne (#312) : enrôler un pro
        // laisserait croire qu'il est protégé alors que rien ne le lui demandera.
        self::assertSame(Command::FAILURE, $code);
        self::assertStringNotContainsString('/second-facteur/enrolement', $tester->getDisplay());
    }

    private function commande(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:admin:second-facteur:enroler'));
    }

    private function seedAdmin(string $email): User
    {
        $user = new User($email, ['ROLE_ADMIN']);
        $this->users()->enregistrer($user);

        return $user;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
