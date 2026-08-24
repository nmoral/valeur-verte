<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodeIncorrect;
use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\EnrolementInvalide;
use App\Security\DeuxFacteurs\EnrolementSecondFacteur;
use App\Security\DeuxFacteurs\SecondFacteurRepository;
use App\Security\DeuxFacteurs\VerificateurSecondFacteur;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Enrôlement du second facteur (#312), déclenché **depuis le serveur**.
 *
 * L'enrôlement ne s'ouvre pas tout seul au premier accès : celui qui se
 * connecterait le premier enrôlerait son téléphone. Si le mot de passe est déjà
 * volé — l'hypothèse même qui motive l'issue — le voleur obtiendrait les deux
 * facteurs et évincerait l'admin légitime de son propre produit. Le second
 * facteur aurait alors aggravé le vol au lieu de le contenir.
 *
 * D'où un lien à usage unique émis en ligne de commande sur le VPS : tant qu'il
 * n'a pas été demandé, personne ne peut enrôler quoi que ce soit, fût-ce avec le
 * bon mot de passe.
 */
final class EnrolementSecondFacteurTest extends KernelTestCase
{
    public function testLeLienEmisOuvreUnSecretEnAttente(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('enrolement@example.com');

        $jeton = $this->enrolement()->ouvrirPour($user);
        $facteur = $this->enrolement()->secretAPresenter($jeton);

        self::assertFalse($facteur->estActif(), 'Rien n\'est actif avant confirmation.');
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $facteur->secret());
    }

    public function testRafraichirLaPageNeChangePasLeSecret(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('rafraichir@example.com');
        $jeton = $this->enrolement()->ouvrirPour($user);

        $premier = $this->enrolement()->secretAPresenter($jeton)->secret();

        // Un secret qui changerait à chaque affichage rendrait le QR déjà scanné
        // caduc : l'admin taperait un code calculé sur l'ancien secret.
        self::assertSame($premier, $this->enrolement()->secretAPresenter($jeton)->secret());
    }

    public function testConfirmerActiveLeFacteurEtRendLesCodesDeSecours(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('confirme@example.com');
        $jeton = $this->enrolement()->ouvrirPour($user);
        $facteur = $this->enrolement()->secretAPresenter($jeton);

        $codes = $this->enrolement()->confirmer($jeton, $this->code($facteur->secret()));

        self::assertCount(10, $codes);
        $recharge = $this->facteurs()->actifPourUtilisateur((int) $user->getId());
        self::assertNotNull($recharge);
        self::assertTrue($recharge->estActif());
    }

    public function testUnCodeFauxNActivePasEtNeConsommePasLeLien(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('code-faux@example.com');
        $jeton = $this->enrolement()->ouvrirPour($user);
        $secret = $this->enrolement()->secretAPresenter($jeton)->secret();

        try {
            $this->enrolement()->confirmer($jeton, '000000');
            self::fail('Un code faux doit être refusé.');
        } catch (CodeIncorrect) {
            // Le lien doit survivre à une faute de frappe, sinon il faut
            // retourner en SSH pour en émettre un nouveau.
            self::assertCount(10, $this->enrolement()->confirmer($jeton, $this->code($secret)));
        }
    }

    public function testLeLienNeSertQuUneFois(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('lien-unique@example.com');
        $jeton = $this->enrolement()->ouvrirPour($user);
        $secret = $this->enrolement()->secretAPresenter($jeton)->secret();
        $this->enrolement()->confirmer($jeton, $this->code($secret));

        $this->expectException(EnrolementInvalide::class);

        $this->enrolement()->secretAPresenter($jeton);
    }

    public function testUnLienInconnuEstRefuse(): void
    {
        self::bootKernel();

        $this->expectException(EnrolementInvalide::class);

        $this->enrolement()->secretAPresenter(str_repeat('a', 64));
    }

    public function testUnNouveauLienRevoqueLePrecedent(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('lien-rejoue@example.com');
        $premier = $this->enrolement()->ouvrirPour($user);

        $this->enrolement()->ouvrirPour($user);

        $this->expectException(EnrolementInvalide::class);
        $this->enrolement()->secretAPresenter($premier);
    }

    public function testUnReenrolementNonConfirmeNeCoupePasLAccesEnCours(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('reenrolement@example.com');
        $userId = (int) $user->getId();
        // Un premier téléphone est en service.
        $jeton = $this->enrolement()->ouvrirPour($user);
        $secretEnService = $this->enrolement()->secretAPresenter($jeton)->secret();
        $this->enrolement()->confirmer($jeton, $this->code($secretEnService));

        // L'admin entame un réenrôlement (nouveau téléphone) puis l'abandonne.
        $this->enrolement()->secretAPresenter($this->enrolement()->ouvrirPour($user));

        // Le téléphone en service doit continuer d'ouvrir : détruire l'ancien
        // facteur avant que le nouveau soit prouvé enfermerait l'admin dehors
        // sur un simple onglet refermé.
        $actif = $this->facteurs()->actifPourUtilisateur($userId);
        self::assertNotNull($actif);
        $maintenant = new \DateTimeImmutable();
        self::assertTrue(
            (new VerificateurSecondFacteur())->verifier($actif, $this->code($secretEnService), $maintenant),
        );
    }

    public function testUnReenrolementConfirmeRemplaceLAncienTelephone(): void
    {
        self::bootKernel();
        $user = $this->seedAdmin('reenrolement-ok@example.com');
        $userId = (int) $user->getId();
        $premier = $this->enrolement()->ouvrirPour($user);
        $ancienSecret = $this->enrolement()->secretAPresenter($premier)->secret();
        $this->enrolement()->confirmer($premier, $this->code($ancienSecret));

        $second = $this->enrolement()->ouvrirPour($user);
        $nouveauSecret = $this->enrolement()->secretAPresenter($second)->secret();
        $this->enrolement()->confirmer($second, $this->code($nouveauSecret));

        // Deux appareils valides dont un seul est connu du titulaire, c'est la
        // définition d'une porte dérobée : l'ancien doit cesser d'ouvrir.
        $actif = $this->facteurs()->actifPourUtilisateur($userId);
        self::assertNotNull($actif);
        self::assertSame($nouveauSecret, $actif->secret());
        self::assertCount(1, $this->facteurs()->findBy(['userId' => $userId]));
    }

    private function code(string $secret): string
    {
        return (new CodeTotp($secret))->pour(new \DateTimeImmutable());
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
