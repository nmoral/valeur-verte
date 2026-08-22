<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Rapport\RapportRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Tests\Notification\Double\MailerEnMemoire;
use App\Widget\DemandeLeadRepository;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use App\Widget\PurgeDemandesLeadWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Purge d'une demande de lead réellement confirmée (#292, épic #6).
 *
 * Le parcours complet — capture (#42), opt-in, confirmation — puis la purge :
 * la ligne `demande_lead` disparaît, mais le `Rapport(Source::Widget)` créé à la
 * confirmation, lui, reste intact (aucune cascade). Le lien d'opt-in déjà
 * consommé rend un 404 comme avant la purge : la disparition de la demande ne
 * change rien à ce que voit le visiteur.
 */
final class PurgeDemandesLeadApresConfirmationTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';
    private const string EMAIL_PRO = 'pro@demo.test';
    private const string EMAIL_VISITEUR = 'martin@exemple.fr';
    private const int GRACE_JOURS = 7;
    private const int GARDE_JOURS = 30;

    public function testLaDemandeConfirmeeEstPurgeeSansEmporterLeLead(): void
    {
        [$client, $token] = $this->preparer();
        $this->soumettreContact($client, $token);
        $lien = $this->lienDeConfirmation($client);
        $client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->rapports($client)->recents());

        $supprimees = $this->purge($client, 'now +'.(self::GARDE_JOURS + 1).' days')->executer();

        self::assertSame(1, $supprimees);
        self::assertCount(1, $this->rapports($client)->recents(), 'Le lead confirmé survit à la purge.');

        $client->request('GET', $lien);
        self::assertResponseStatusCodeSame(404, 'Un lien déjà consommé reste un 404 après la purge.');
    }

    private function purge(KernelBrowser $client, string $maintenant): PurgeDemandesLeadWidget
    {
        $registry = $client->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new PurgeDemandesLeadWidget(
            new DemandeLeadRepository($registry),
            new MockClock(new \DateTimeImmutable($maintenant)),
            self::GRACE_JOURS,
            self::GARDE_JOURS,
        );
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
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

        $users = $conteneur->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $pro = new User(self::EMAIL_PRO, ['ROLE_PRO']);
        $pro->rattacherAuTenant($tenantId);
        $users->enregistrer($pro);

        $em->flush();

        $this->libererLimiteursWidget($token);

        $this->coefficients($client)->definir(self::CODE_INSEE, CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        ));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        return [$client, $token];
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

    private function soumettreContact(KernelBrowser $client, string $token): void
    {
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', [
            'nom' => 'Martin',
            'email' => self::EMAIL_VISITEUR,
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
            'consentement' => 'on',
        ]);
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
