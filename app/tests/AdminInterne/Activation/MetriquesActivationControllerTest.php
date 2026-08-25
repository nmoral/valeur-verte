<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Activation;

use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Onboarding\Parcours\EvenementInscription;
use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\TypeEvenementInscription;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La page admin des métriques d'activation (#111) restitue les taux dérivés des
 * horodatages. Zone « admin. », ROLE_ADMIN, comme les autres pages de pilotage.
 * Les comptes sont créés en 2024 pour que le verdict soit indépendant de l'horloge
 * réelle (comptes mûrs quoi qu'il arrive).
 */
final class MetriquesActivationControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/metriques-activation');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageRestitueLesTauxDActivation(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        // t1 activé (1 rapport audit à +30 min), t2 jamais activé.
        $this->seedCompte(1, '2024-01-01 09:00:00');
        $this->seedCompte(2, '2024-01-01 09:00:00');
        $this->seedRapport('m-1', 1, '2024-01-01 09:30:00');

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/metriques-activation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'activation');
        // Taux inscription → premier rapport : 1 / 2 = 50 %.
        self::assertSelectorTextContains('[data-metrique="premier-rapport"]', '50');
        self::assertSelectorTextContains('[data-metrique="premier-rapport"]', '1 / 2');
        // Délai médian : 30 min.
        self::assertSelectorTextContains('[data-metrique="delai-median"]', '30');
    }

    public function testLaPageRestitueLAbandonSurLEcranSiret(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        // 4 visites voient l'écran, 3 créent un compte → abandon 1/4 = 25 %.
        foreach ([1, 2, 3, 4] as $visite) {
            $this->seedEvenement($visite, TypeEvenementInscription::InscriptionAffichee);
        }
        foreach ([1, 2, 3] as $visite) {
            $this->seedEvenement($visite, TypeEvenementInscription::CompteCree);
        }

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/metriques-activation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-metrique="abandon-siret"]', '25');
    }

    public function testLaNavigationMarqueLaPageCourante(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/metriques-activation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.side a[href="/comptes"]');
        self::assertSelectorTextContains('nav.side a.on', 'Activation');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);
    }

    private function seedCompte(int $tenantId, string $creeLe): void
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);
        $repository->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable($creeLe)));
    }

    private function seedEvenement(int $visite, TypeEvenementInscription $type): void
    {
        $repository = self::getContainer()->get(EvenementInscriptionRepository::class);
        self::assertInstanceOf(EvenementInscriptionRepository::class, $repository);
        $session = str_pad((string) $visite, 32, '0', \STR_PAD_LEFT);
        $repository->enregistrer(
            EvenementInscription::survenu($session, $type, new \DateTimeImmutable('2026-08-25 09:00:00')),
        );
    }

    private function seedRapport(string $reference, int $tenantId, string $creeLe): void
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable($creeLe),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $repository->enregistrer($rapport);
    }
}
