<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\GenerateurSecretTotp;
use App\Security\DeuxFacteurs\SecondFacteur;
use App\Security\DeuxFacteurs\SecondFacteurRepository;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plafond de tentatives sur le défi (#312).
 *
 * Six chiffres, c'est un million de possibilités — mais une fenêtre de tolérance
 * de 90 secondes et un attaquant qui tient déjà le mot de passe, c'est une
 * énumération qui aboutit si personne ne la borne. #307 a plafonné `/login` ;
 * sans équivalent ici, le second facteur ne ferait que déplacer la porte.
 */
final class PlafondDefiSecondFacteurTest extends WebTestCase
{
    private const ADMIN = 'http://admin.valeurverte.localhost';

    public function testLesTentativesInfructueusesFinissentParEtreRefusees(): void
    {
        $client = static::createClient();
        $user = $this->seedAdminAvecFacteur('plafond@example.com');
        $client->loginUser($user);

        for ($tentative = 1; $tentative <= 5; ++$tentative) {
            $this->soumettre($client, '000000');
            self::assertResponseStatusCodeSame(422, \sprintf('Tentative %d : refus ordinaire attendu.', $tentative));
        }

        $this->soumettre($client, '000000');

        // 429 et non 422 : la différence dit à un opérateur qui lit les journaux
        // qu'il regarde une énumération, pas une faute de frappe.
        self::assertResponseStatusCodeSame(429);
    }

    public function testUnCodeValideNePasseraPlusUneFoisLePlafondAtteint(): void
    {
        $client = static::createClient();
        $user = $this->seedAdminAvecFacteur('plafond-valide@example.com');
        $client->loginUser($user);
        for ($tentative = 1; $tentative <= 6; ++$tentative) {
            $this->soumettre($client, '000000');
        }

        $this->soumettre($client, $this->codeCourant($user));

        // Le plafond ne fait pas d'exception pour le bon code : sans quoi il
        // suffirait de le trouver pour que la borne n'ait servi à rien.
        self::assertResponseStatusCodeSame(429);
    }

    public function testUnAutreCompteNestPasPenaliseParLesEchecsDuPremier(): void
    {
        $client = static::createClient();
        $premier = $this->seedAdminAvecFacteur('plafond-a@example.com');
        $client->loginUser($premier);
        for ($tentative = 1; $tentative <= 6; ++$tentative) {
            $this->soumettre($client, '000000');
        }

        self::ensureKernelShutdown();
        $autre = static::createClient();
        $second = $this->seedAdminAvecFacteur('plafond-b@example.com');
        $autre->loginUser($second);

        $this->soumettre($autre, $this->codeCourant($second));

        // Plafonner l'IP seule permettrait de verrouiller à distance l'admin d'à
        // côté ; la borne porte sur le compte.
        self::assertResponseRedirects();
    }

    private function soumettre(KernelBrowser $client, string $code): void
    {
        $crawler = $client->request('GET', self::ADMIN.'/second-facteur');
        $client->submit($crawler->selectButton('Vérifier')->form(['code' => $code]));
    }

    private function codeCourant(User $user): string
    {
        $facteur = $this->facteurs()->actifPourUtilisateur((int) $user->getId());
        self::assertNotNull($facteur);

        return (new CodeTotp($facteur->secret()))->pour(new \DateTimeImmutable());
    }

    private function seedAdminAvecFacteur(string $email): User
    {
        $user = new User($email, ['ROLE_ADMIN']);
        $this->users()->enregistrer($user);

        $facteur = new SecondFacteur((int) $user->getId(), (new GenerateurSecretTotp())->nouveau());
        $facteur->activer(new \DateTimeImmutable());
        $this->facteurs()->enregistrer($facteur);

        return $user;
    }

    private function facteurs(): SecondFacteurRepository
    {
        $repository = self::getContainer()->get(SecondFacteurRepository::class);
        self::assertInstanceOf(SecondFacteurRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
