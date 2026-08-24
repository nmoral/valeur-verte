<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le critère d'acceptation de #312, pris au mot : « un mot de passe d'admin volé
 * ne suffit pas à ouvrir l'admin interne ».
 *
 * Après le mot de passe, l'admin est **connecté mais pas autorisé** : c'est la
 * zone entière qui reste fermée, pas quelques pages sensibles. Protéger page par
 * page, c'est se condamner à en oublier une — et celle qu'on oublie est justement
 * celle qu'on n'avait pas vue.
 */
final class ZoneAdminFermeeSansSecondFacteurTest extends WebTestCase
{
    private const ADMIN = 'http://admin.valeurverte.localhost';
    private const APP = 'http://app.valeurverte.localhost';

    public function testUnAdminConnecteMaisNonValideNeVoitAucunePageDeLaZone(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedAdmin('zone-admin@example.com'));

        foreach (['/', '/comptes', '/journal-audit', '/millesimes'] as $chemin) {
            $client->request('GET', self::ADMIN.$chemin);
            self::assertResponseRedirects(
                self::ADMIN.'/second-facteur',
                null,
                \sprintf('La page %s doit renvoyer au second facteur.', $chemin),
            );
        }
    }

    public function testLaPageDuSecondFacteurResteJoignable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedAdmin('zone-defi@example.com'));

        $client->request('GET', self::ADMIN.'/second-facteur');

        // Sans quoi la redirection boucle sur elle-même.
        self::assertResponseIsSuccessful();
    }

    public function testLaDeconnexionResteJoignable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedAdmin('zone-logout@example.com'));

        $client->request('GET', self::ADMIN.'/logout');

        // Un admin coincé devant le second facteur doit pouvoir partir.
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/second-facteur', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testLEspaceProNestPasConcerne(): void
    {
        $client = static::createClient();
        $user = new User('zone-pro@example.com', ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);
        $client->loginUser($user);

        $client->request('GET', self::APP.'/mon-compte');

        // L'exigence ne porte que sur l'admin interne : le périmètre exposé par un
        // compte pro est le sien seul, et l'imposer à un artisan au premier
        // rendez-vous est un frein d'activation (#111).
        self::assertResponseIsSuccessful();
    }

    public function testLaSondeDeSanteNestPasConcernee(): void
    {
        $client = static::createClient();

        $client->request('GET', self::ADMIN.'/sante');

        // La santé applicative (#313) doit rester lisible par une sonde, qui n'a
        // ni session ni second facteur.
        self::assertResponseIsSuccessful();
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
