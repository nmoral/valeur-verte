<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs\Double;

use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\GenerateurSecretTotp;
use App\Security\DeuxFacteurs\SecondFacteur;
use App\Security\DeuxFacteurs\SecondFacteurRepository;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Franchit le second facteur (#312) pour les tests qui portent sur autre chose.
 *
 * Depuis #312, `loginUser()` ne suffit plus à entrer dans la zone `admin.` : le
 * mot de passe ouvre la session, pas l'administration. Plutôt qu'une dérogation
 * en environnement de test — qui ferait passer la suite entière à côté de la
 * porte qu'on vient d'installer — ce trait **joue le vrai parcours** : il enrôle
 * un facteur actif et soumet un code valide. Un test d'administration exerce donc
 * la même séquence qu'un admin réel.
 */
trait PasseLeSecondFacteur
{
    private function passerLeSecondFacteur(KernelBrowser $client, User $admin): void
    {
        $userId = $admin->getId();
        self::assertNotNull($userId);

        $secret = (new GenerateurSecretTotp())->nouveau();
        $facteur = new SecondFacteur($userId, $secret);
        $facteur->activer(new \DateTimeImmutable());

        $facteurs = self::getContainer()->get(SecondFacteurRepository::class);
        self::assertInstanceOf(SecondFacteurRepository::class, $facteurs);
        $facteurs->enregistrer($facteur);

        $client->request('GET', 'http://admin.valeurverte.localhost/second-facteur');
        $client->submitForm('Vérifier', [
            'code' => (new CodeTotp($secret))->pour(new \DateTimeImmutable()),
        ]);
    }
}
