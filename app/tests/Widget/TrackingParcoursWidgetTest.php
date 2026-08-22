<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Tracking du parcours widget, côté serveur (#90, épic #6).
 *
 * Les étapes que le serveur **constate** lui-même sont journalisées sans dépendre
 * du navigateur : chargement de la page, premier chiffre affiché, mur de contact
 * affiché, lead soumis. Une même visite les relie par un identifiant de session
 * anonyme, porté par les formulaires (aucun cookie, aucun stockage navigateur).
 *
 * RGPD (non négociable) : la collecte reste anonyme — aucune donnée personnelle
 * dans la charge utile, en particulier jamais l'adresse du bien ni l'email.
 */
final class TrackingParcoursWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';
    private const string ADRESSE = '12 rue des Lilas, Toulouse';
    private const string EMAIL_VISITEUR = 'martin@exemple.fr';

    public function testLeChargementDeLaPageOuvreUneVisiteEtEstJournalise(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        $session = $this->sessionDuFormulaire($client);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $session);
        self::assertSame(
            [TypeEvenementWidget::WidgetCharge],
            $this->typesDeLaSession($session),
        );
    }

    public function testDeuxChargementsSontDeuxVisitesDistinctes(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $premiere = $this->sessionDuFormulaire($client);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $seconde = $this->sessionDuFormulaire($client);

        self::assertNotSame($premiere, $seconde);
    }

    public function testLePremierChiffreEtLeMurDeContactSontJournalisesDansLaMemeVisite(): void
    {
        [$client, $token] = $this->preparer();
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);

        $this->estimer($client, $token, $session);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                TypeEvenementWidget::WidgetCharge,
                TypeEvenementWidget::EstimationAffichee,
                TypeEvenementWidget::MurContactAffiche,
            ],
            $this->typesDeLaSession($session),
        );
    }

    public function testUneCommuneHorsCouvertureNaffichePasDeMurDeContact(): void
    {
        // L'abandon se lit comme l'absence de l'étape suivante : ici, pas de mur de
        // contact puisqu'il n'y a pas de chiffre à monnayer.
        [$client, $token] = $this->preparer(couvert: false);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);

        $this->estimer($client, $token, $session);

        self::assertSame(
            [TypeEvenementWidget::WidgetCharge, TypeEvenementWidget::EstimationAffichee],
            $this->typesDeLaSession($session),
        );
        $estimation = $this->evenementsDeLaSession($session)[1];
        self::assertSame(['disponible' => false], $estimation->donnees());
    }

    public function testUneSaisieInvalideNeJournalisePasDEstimationAffichee(): void
    {
        [$client, $token] = $this->preparer();
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);

        $this->estimer($client, $token, $session, surfaceBatie: '3');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([TypeEvenementWidget::WidgetCharge], $this->typesDeLaSession($session));
        // Le formulaire réaffiché garde la visite ouverte : même identifiant.
        self::assertSame($session, $this->sessionDuFormulaire($client));
    }

    public function testUneAdresseNonResolueNeJournalisePasDEstimationAffichee(): void
    {
        // Sans code INSEE (#287), rien n'a été estimé : compter l'étape ferait lire
        // le tunnel comme un échec de couverture, alors que l'adresse n'a pas été
        // choisie dans la liste.
        [$client, $token] = $this->preparer();
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);

        $this->estimer($client, $token, $session, codeInsee: '');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([TypeEvenementWidget::WidgetCharge], $this->typesDeLaSession($session));
    }

    public function testLaSoumissionDuLeadEstJournaliseeDansLaMemeVisite(): void
    {
        [$client, $token] = $this->preparer(avecPro: true);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);
        $this->estimer($client, $token, $session);

        $this->soumettreContact($client, $token, $session);

        self::assertResponseIsSuccessful();
        self::assertContains(TypeEvenementWidget::LeadSoumis, $this->typesDeLaSession($session));
    }

    public function testAucuneDonneePersonnelleNEntreDansLesEvenements(): void
    {
        [$client, $token] = $this->preparer(avecPro: true);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        $session = $this->sessionDuFormulaire($client);
        $this->estimer($client, $token, $session);
        $this->soumettreContact($client, $token, $session);

        $charges = json_encode(array_map(
            static fn (EvenementWidget $evenement) => $evenement->donnees(),
            $this->evenementsDeLaSession($session),
        ));
        self::assertIsString($charges);
        self::assertStringNotContainsString(self::ADRESSE, $charges);
        self::assertStringNotContainsString(self::EMAIL_VISITEUR, $charges);
        self::assertStringNotContainsString('127.0.0.1', $charges);
    }

    public function testUnIdentifiantDeVisiteForgeNestPasRepris(): void
    {
        // Le champ est public : on n'accepte que ce qui a la forme d'un identifiant,
        // et une valeur malformée ouvre simplement une nouvelle visite.
        [$client, $token] = $this->preparer();

        $this->estimer($client, $token, 'pas-un-identifiant');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->sessionDuFormulaire($client));
        self::assertSame([], $this->typesDeLaSession('pas-un-identifiant'));
    }

    private function sessionDuFormulaire(KernelBrowser $client): string
    {
        $champ = $client->getCrawler()->filter('input[name="session_parcours"]')->first();
        self::assertGreaterThan(0, $champ->count(), 'Le formulaire doit porter la visite en cours.');

        return (string) $champ->attr('value');
    }

    /**
     * @return list<TypeEvenementWidget>
     */
    private function typesDeLaSession(string $session): array
    {
        return array_map(
            static fn (EvenementWidget $evenement) => $evenement->type(),
            $this->evenementsDeLaSession($session),
        );
    }

    /**
     * @return list<EvenementWidget>
     */
    private function evenementsDeLaSession(string $session): array
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return (new EvenementWidgetRepository($registry))->pourSession($session);
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function preparer(bool $couvert = true, bool $avecPro = false): array
    {
        $client = static::createClient();
        // Le conteneur (et donc les doubles en mémoire) doit survivre du GET au POST.
        $client->disableReboot();

        $token = $this->creerPage($client, $avecPro);
        // Le plafond anti-abus (#43) persiste entre tests : sans reset, l'IP par
        // défaut partagée finit par saturer et un parcours légitime récolterait un 429.
        $this->libererLimiteursWidget($token);

        if ($couvert) {
            $this->coefficients($client)->definir(self::CODE_INSEE, CoefficientMaille::depuis(
                millesime: 2024,
                rayonKm: 10,
                n: 134,
                erreurMediane: 17.22,
                coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
                portee: PorteeEstimation::Local,
            ));
            $this->prix($client)->definir(self::CODE_INSEE, 1635.0);
        }

        return [$client, $token];
    }

    private function estimer(
        KernelBrowser $client,
        string $token,
        string $session,
        string $surfaceBatie = '90',
        string $codeInsee = self::CODE_INSEE,
    ): void {
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', [
            'adresse' => self::ADRESSE,
            'code_insee' => $codeInsee,
            'surface_batie' => $surfaceBatie,
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
            'session_parcours' => $session,
        ]);
    }

    private function soumettreContact(KernelBrowser $client, string $token, string $session): void
    {
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', [
            'nom' => 'Martin',
            'email' => self::EMAIL_VISITEUR,
            'consentement' => 'on',
            'adresse' => self::ADRESSE,
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
            'session_parcours' => $session,
        ]);
    }

    private function libererLimiteursWidget(string $token, string $ip = '127.0.0.1'): void
    {
        $conteneur = static::getContainer();
        $parIp = $conteneur->get('limiter.widget_ip');
        $parPage = $conteneur->get('limiter.widget_page');
        $parEmission = $conteneur->get('limiter.widget_emission');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parIp);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parPage);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parEmission);
        $parIp->create($ip)->reset();
        $parPage->create($token)->reset();
        $parEmission->create($ip)->reset();
    }

    private function jetonChargementValide(): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - JetonChargementWidget::AGE_MIN_SECONDES - 1)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }

    private function coefficients(KernelBrowser $client): FournisseurCoefficientMailleEnMemoire
    {
        $double = $client->getContainer()->get(FournisseurCoefficientMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurCoefficientMailleEnMemoire::class, $double);

        return $double;
    }

    private function prix(KernelBrowser $client): FournisseurPrixMailleEnMemoire
    {
        $double = $client->getContainer()->get(FournisseurPrixMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurPrixMailleEnMemoire::class, $double);

        return $double;
    }

    private function creerPage(KernelBrowser $client, bool $avecPro): string
    {
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), null, null, null, null, 'Agence Durand');
        $branding->setTenantId($tenantId);
        $em->persist($branding);

        $token = bin2hex(random_bytes(5));
        $em->persist(PagePublique::creer($tenantId, $token, 'agence-durand'));

        if ($avecPro) {
            // Destinataire de la notification de lead (#42).
            $users = $conteneur->get(UserRepository::class);
            self::assertInstanceOf(UserRepository::class, $users);
            $pro = new User('pro@demo.test', ['ROLE_PRO']);
            $pro->rattacherAuTenant($tenantId);
            $users->enregistrer($pro);
        }

        $em->flush();

        return $token;
    }
}
