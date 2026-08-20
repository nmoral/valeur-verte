<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Tests\Notification\Double\MailerEnMemoire;
use App\Widget\DemandeLead;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Capture du lead sur la page publique hébergée + double opt-in (#42, épic #6).
 *
 * Après le premier chiffre (#41), le visiteur laisse nom + email et consent. Rien
 * n'est révélé au pro à ce stade : une **demande à confirmer** est enregistrée et
 * un email d'opt-in part. Ce n'est qu'au **clic de confirmation** que le lead
 * devient un Rapport(Source::Widget) visible du pro (#37), que le pro est notifié,
 * et que le PDF (figé à la saisie) devient téléchargeable. Aucun quota consommé.
 *
 * Jeton de confirmation : usage unique + expirant, empreinte SHA-256 stockée
 * (pattern #194) — le brut ne vit que dans le lien de l'email.
 */
final class CaptureLeadTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';
    private const string EMAIL_PRO = 'pro@demo.test';
    private const string EMAIL_VISITEUR = 'martin@exemple.fr';

    public function testSansConsentementLaCaptureEstRefusee(): void
    {
        [$client, $token] = $this->preparer();

        $this->soumettreContact($client, $token, consentement: false);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->mailer($client)->envoyes(), 'Aucun email sans consentement.');
        self::assertCount(0, $this->rapports($client)->recents(), 'Aucun lead sans consentement.');
    }

    public function testUneCaptureValideEnregistreUneDemandeAConfirmerEtEnvoieUnOptIn(): void
    {
        [$client, $token] = $this->preparer();

        $this->soumettreContact($client, $token);

        self::assertResponseIsSuccessful();
        // Page « vérifiez votre boîte mail », pas encore le détail chiffré.
        self::assertSelectorExists('[data-widget="opt-in-envoye"]');

        // Un seul email, au visiteur, portant le lien de confirmation.
        $envoyes = $this->mailer($client)->envoyes();
        self::assertCount(1, $envoyes);
        self::assertSame(self::EMAIL_VISITEUR, $envoyes[0]->destinataire());
        self::assertStringContainsString('/contact/confirmer/', $envoyes[0]->corpsHtml());

        // Tant que non confirmé : pas de lead visible du pro, pas de notif pro.
        self::assertCount(0, $this->rapports($client)->recents());
    }

    public function testLaConfirmationCreeLeLeadWidgetEtNotifieLePro(): void
    {
        [$client, $token] = $this->preparer();
        $this->soumettreContact($client, $token);
        $lien = $this->lienDeConfirmation($client);

        $client->request('GET', $lien);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-widget="lead-confirme"]');
        // Le PDF (figé à la saisie) est offert au téléchargement sur cette page.
        self::assertSelectorExists('a[data-widget="pdf"]');

        // Le lead existe désormais, rattaché au tenant de la page, avec le contact.
        $rapports = $this->rapports($client)->recents();
        self::assertCount(1, $rapports);
        self::assertTrue($rapports[0]->estUnLead());
        self::assertSame(Source::Widget, $rapports[0]->source());
        self::assertSame(self::EMAIL_VISITEUR, $rapports[0]->contact()?->email());

        // Le pro est notifié par email (en plus de la liste #37).
        $destinataires = array_map(static fn ($e) => $e->destinataire(), $this->mailer($client)->envoyes());
        self::assertContains(self::EMAIL_PRO, $destinataires);
    }

    public function testLePdfDuLeadConfirmeEstTelechargeable(): void
    {
        [$client, $token] = $this->preparer();
        $this->soumettreContact($client, $token);
        $client->request('GET', $this->lienDeConfirmation($client));
        $hrefPdf = $client->getCrawler()->filter('a[data-widget="pdf"]')->attr('href');
        self::assertIsString($hrefPdf);

        $client->request('GET', $hrefPdf);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testUnJetonDeConfirmationInconnuEstRejete(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact/confirmer/'.bin2hex(random_bytes(32)));

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->rapports($client)->recents());
    }

    public function testLaConfirmationEstAUsageUnique(): void
    {
        [$client, $token] = $this->preparer();
        $this->soumettreContact($client, $token);
        $lien = $this->lienDeConfirmation($client);

        $client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        $client->request('GET', $lien);

        self::assertResponseStatusCodeSame(404, 'Un jeton déjà consommé ne reconfirme pas.');
        self::assertCount(1, $this->rapports($client)->recents(), 'Un seul lead, jamais dupliqué.');
    }

    public function testUnJetonDeConfirmationExpireEstRejete(): void
    {
        [$client, $token, $tenantId] = $this->preparer();

        // Demande figée dans le passé (expirée) : la garde d'expiration doit primer
        // avant toute création de lead. L'instantané n'a pas à être exploitable ici.
        $jetonBrut = bin2hex(random_bytes(32));
        $conteneur = $client->getContainer();
        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $demande = DemandeLead::creer(
            $tenantId,
            hash('sha256', $jetonBrut),
            new \DateTimeImmutable('-1 hour'),
            'Martin',
            self::EMAIL_VISITEUR,
            [],
            new \DateTimeImmutable('-2 hour'),
        );
        $em->persist($demande);
        $em->flush();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact/confirmer/'.$jetonBrut);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->rapports($client)->recents());
    }

    private function libererLimiteursWidget(string $token, string $ip = '127.0.0.1'): void
    {
        $conteneur = static::getContainer();
        $parIp = $conteneur->get('limiter.widget_ip');
        $parPage = $conteneur->get('limiter.widget_page');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parIp);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parPage);
        $parIp->create($ip)->reset();
        $parPage->create($token)->reset();
    }

    /**
     * @return array{0: KernelBrowser, 1: string, 2: int}
     */
    private function preparer(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $branding = Branding::creer(Couleur::depuisHex('#B8480F'), null, null, null, null, 'Rénov Toulouse');
        $branding->setTenantId($tenantId);
        $em->persist($branding);

        $em->persist(PagePublique::creer($tenantId, $token = bin2hex(random_bytes(5)), 'renov-toulouse'));

        // Le pro du tenant : destinataire de la notification de lead.
        $users = $conteneur->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $pro = new User(self::EMAIL_PRO, ['ROLE_PRO']);
        $pro->rattacherAuTenant($tenantId);
        $users->enregistrer($pro);

        $em->flush();

        // Le plafond anti-abus (#43) persiste entre tests : reset de l'IP par défaut
        // partagée pour qu'une capture légitime ne récolte pas un 429 (cf. #43).
        $this->libererLimiteursWidget($token);

        $this->coefficients($client)->definir(self::CODE_INSEE, CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        ));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        return [$client, $token, $tenantId];
    }

    private function soumettreContact(KernelBrowser $client, string $token, bool $consentement = true): void
    {
        $champs = [
            'nom' => 'Martin',
            'email' => self::EMAIL_VISITEUR,
            // Le mur de contact reporte les entrées de l'estimation (champs cachés)
            // pour figer l'instantané au moment de la demande.
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
        ];
        if ($consentement) {
            $champs['consentement'] = 'on';
        }

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', $champs);
    }

    private function jetonChargementValide(): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - JetonChargementWidget::AGE_MIN_SECONDES - 1)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }

    private function lienDeConfirmation(KernelBrowser $client): string
    {
        $corps = $this->mailer($client)->envoyes()[0]->corpsHtml();
        if (1 !== preg_match('#https?://[^"\'\s]+/contact/confirmer/[a-f0-9]+#', $corps, $m)) {
            self::fail('Lien de confirmation introuvable dans l\'email d\'opt-in.');
        }

        return $m[0];
    }

    private function mailer(KernelBrowser $client): MailerEnMemoire
    {
        $double = $client->getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $double);

        return $double;
    }

    private function rapports(KernelBrowser $client): RapportRepository
    {
        $repo = $client->getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repo);

        return $repo;
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
}
