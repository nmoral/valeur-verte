<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\EnrolementSecondFacteur;
use App\Security\DeuxFacteurs\SecondFacteurRepository;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran d'enrôlement (#312) : le QR à scanner, puis la confirmation par un code.
 * Il n'est atteignable qu'avec le lien émis en ligne de commande — et, en
 * profondeur, qu'avec une session d'administration, le sous-domaine `admin.`
 * exigeant déjà ROLE_ADMIN.
 */
final class EnrolementControllerTest extends WebTestCase
{
    private const ADMIN = 'http://admin.valeurverte.localhost';

    public function testLePremierEcranPresenteLeQrEtLeSecretRecopiable(): void
    {
        $client = static::createClient();
        $jeton = $this->ouvrirPour($client, 'enrol-qr@example.com');

        $client->request('GET', self::ADMIN.'/second-facteur/enrolement?jeton='.$jeton);

        self::assertResponseIsSuccessful();
        // QR en SVG inline : aucune ressource externe à autoriser dans la CSP,
        // aucune extension d'image côté PHP.
        self::assertSelectorExists('svg');
        // Le secret reste lisible en toutes lettres : certaines applications ne
        // scannent pas, et un QR qui ne passe pas laisserait l'admin sans recours.
        self::assertSelectorExists('.secret-totp');
    }

    public function testLesCodesDeSecoursNeSontPasReveslesAvantConfirmation(): void
    {
        $client = static::createClient();
        $jeton = $this->ouvrirPour($client, 'enrol-avant@example.com');

        $client->request('GET', self::ADMIN.'/second-facteur/enrolement?jeton='.$jeton);

        // Les révéler à l'affichage, c'est les donner à qui a intercepté le lien
        // sans jamais prouver qu'il tient le téléphone.
        self::assertSelectorNotExists('.codes-de-secours');
    }

    public function testUnCodeValideActiveLeFacteurEtRevelLesCodesDeSecours(): void
    {
        $client = static::createClient();
        $user = $this->seedAdmin('enrol-ok@example.com');
        $client->loginUser($user);
        $jeton = $this->ouvrir($user);

        $this->confirmer($client, $jeton, $this->codeCourant($jeton));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.codes-de-secours');
        self::assertCount(10, $client->getCrawler()->filter('.codes-de-secours li'));
        self::assertNotNull($this->facteurs()->actifPourUtilisateur((int) $user->getId()));
    }

    public function testUnCodeFauxEstRefuseSansConsommerLeLien(): void
    {
        $client = static::createClient();
        $user = $this->seedAdmin('enrol-faux@example.com');
        $client->loginUser($user);
        $jeton = $this->ouvrir($user);

        $this->confirmer($client, $jeton, '000000');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert');
        self::assertNull($this->facteurs()->actifPourUtilisateur((int) $user->getId()));

        // Le lien survit : sans cela, une faute de frappe obligerait à retourner
        // en SSH pour en émettre un autre.
        $this->confirmer($client, $jeton, $this->codeCourant($jeton));
        self::assertResponseIsSuccessful();
    }

    public function testUnLienInvalideNeDonneRien(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedAdmin('enrol-lien@example.com'));

        $client->request('GET', self::ADMIN.'/second-facteur/enrolement?jeton='.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testLEnrolementExigeUneSessionDAdministration(): void
    {
        $client = static::createClient();
        $jeton = $this->ouvrir($this->seedAdmin('enrol-anonyme@example.com'));

        $client->request('GET', self::ADMIN.'/second-facteur/enrolement?jeton='.$jeton);

        // Défense en profondeur : il faut le lien ET le mot de passe. Le lien seul,
        // intercepté dans un historique de terminal, n'ouvre rien.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function confirmer(KernelBrowser $client, string $jeton, string $code): void
    {
        $crawler = $client->request('GET', self::ADMIN.'/second-facteur/enrolement?jeton='.$jeton);
        $client->submit($crawler->selectButton('Activer')->form(['code' => $code]));
    }

    private function codeCourant(string $jeton): string
    {
        $secret = $this->enrolement()->secretAPresenter($jeton)->secret();

        return (new CodeTotp($secret))->pour(new \DateTimeImmutable());
    }

    private function ouvrirPour(KernelBrowser $client, string $email): string
    {
        $user = $this->seedAdmin($email);
        $client->loginUser($user);

        return $this->ouvrir($user);
    }

    private function ouvrir(User $user): string
    {
        return $this->enrolement()->ouvrirPour($user);
    }

    private function seedAdmin(string $email): User
    {
        $user = new User($email, ['ROLE_ADMIN']);
        $this->users()->enregistrer($user);

        return $user;
    }

    private function enrolement(): EnrolementSecondFacteur
    {
        $service = self::getContainer()->get(EnrolementSecondFacteur::class);
        self::assertInstanceOf(EnrolementSecondFacteur::class, $service);

        return $service;
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
