<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Parcours particulier sur la page publique hébergée (#41, épic #6, décision D9).
 *
 * De l'adresse au premier chiffre, sur le sous-domaine `widget.`, sans compte :
 * le visiteur saisit son bien, obtient une estimation (valeur verte, fourchette,
 * effectif, rayon) SANS donner d'email, puis se voit proposer le mur de contact.
 * L'estimation réutilise le moteur du mode audit (EstimateurValeurVerte), servi
 * ici par les doubles en mémoire. Le premier chiffre ne consomme aucun quota et
 * ne persiste rien — la capture du lead et le PDF relèvent de #42.
 *
 * Principes non négociables : le mur de contact vient APRÈS le premier chiffre
 * (jamais avant), et le calcul économique / l'ajustement du pro sont exclus.
 */
final class ParcoursEstimationTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testLaPageAfficheLeFormulaireDEstimationAvecDpeCibleCParDefaut(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-widget="estimation"]');
        self::assertSelectorExists('input[name="adresse"]');
        self::assertSelectorExists('input[name="code_insee"]');
        self::assertSelectorExists('input[name="surface_batie"]');
        self::assertSelectorExists('input[name="surface_terrain"]');
        // L'étiquette se choisit sur une échelle de sept crans colorés (#290),
        // plus dans une liste déroulante.
        self::assertSelectorExists('input[type="radio"][name="dpe_avant"]');
        // DPE cible par défaut : C (§4).
        self::assertSelectorExists('input[name="dpe_apres"][value="C"][checked]');
        // Le mur de contact ne vient jamais avant le chiffre : pas d'email ici.
        self::assertSelectorNotExists('input[name="email"]');
    }

    public function testLePremierChiffreSAfficheAvecSonContexteSansEmail(): void
    {
        [$client, $token] = $this->preparer();

        $this->estimer($client, $token);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSelectorExists('[data-widget="valeur-verte"]');
        self::assertStringContainsString('€', $html);
        // Contexte : effectif, rayon, fourchette — comme la restitution pro.
        self::assertStringContainsString('134', $html);
        self::assertStringContainsString('10 km', $html);
        self::assertStringContainsString('fourchette', strtolower($html));
        // Le chiffre est obtenu sans qu'aucun email n'ait été demandé.
    }

    public function testLeMurDeContactVientApresLePremierChiffre(): void
    {
        [$client, $token] = $this->preparer();

        $this->estimer($client, $token);

        $html = (string) $client->getResponse()->getContent();
        self::assertSelectorExists('[data-widget="mur-contact"]');
        self::assertSelectorExists('[data-widget="mur-contact"] input[name="nom"]');
        self::assertSelectorExists('[data-widget="mur-contact"] input[name="email"]');
        $posChiffre = strpos($html, 'data-widget="valeur-verte"');
        $posMur = strpos($html, 'data-widget="mur-contact"');
        self::assertIsInt($posChiffre);
        self::assertIsInt($posMur);
        self::assertLessThan($posMur, $posChiffre, 'Le mur de contact doit suivre le premier chiffre, jamais le précéder.');
    }

    public function testUneCommuneHorsCouvertureLIndiqueSansChiffreNiMur(): void
    {
        // Aucun coefficient défini pour ce code INSEE : on ne fabrique pas de chiffre.
        [$client, $token] = $this->preparer(couvert: false);

        $this->estimer($client, $token);

        self::assertResponseIsSuccessful();
        $html = strtolower((string) $client->getResponse()->getContent());
        self::assertStringContainsString('indisponible', $html);
        self::assertSelectorNotExists('[data-widget="valeur-verte"]');
        // Pas de chiffre ⇒ pas de mur de contact (rien à débloquer).
        self::assertSelectorNotExists('[data-widget="mur-contact"]');
    }

    public function testUneAdresseNonChoisieDansLaListeEstRefuseeAvantToutChiffre(): void
    {
        // Sans code INSEE, la commune n'a jamais été résolue (#287) : le dire, au
        // lieu de laisser croire à un défaut de couverture du moteur.
        [$client, $token] = $this->preparer();

        $this->estimer($client, $token, codeInsee: '');

        self::assertResponseStatusCodeSame(422);
        $html = strtolower((string) $client->getResponse()->getContent());
        self::assertStringContainsString('liste', $html, "L'erreur doit inviter à choisir une adresse dans la liste.");
        self::assertStringNotContainsString('hors couverture', $html);
        self::assertSelectorNotExists('[data-widget="valeur-verte"]');
        self::assertSelectorNotExists('[data-widget="mur-contact"]');
    }

    public function testUneAdresseNonChoisieRendLeFormulaireAvecLaSaisieConservee(): void
    {
        // Le visiteur ne doit pas tout retaper pour une adresse à re-sélectionner.
        [$client, $token] = $this->preparer();

        $crawler = $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', [
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => '',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
        ]);

        self::assertSame('12 rue des Lilas, Toulouse', $crawler->filter('input[name="adresse"]')->attr('value'));
        self::assertSame('90', $crawler->filter('input[name="surface_batie"]')->attr('value'));
    }

    public function testUneSaisieInvalideEstRefusee(): void
    {
        [$client, $token] = $this->preparer();

        // Surface bâtie hors bornes de vraisemblance (§9.8).
        $this->estimer($client, $token, surfaceBatie: '5');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-widget="valeur-verte"]');
    }

    public function testLeResultatResteAuxCouleursDuPro(): void
    {
        [$client, $token] = $this->preparer(nom: 'Rénov Toulouse', couleur: '#B8480F');

        $this->estimer($client, $token);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Rénov Toulouse', $html);
        self::assertStringContainsString('#B8480F', $html);
    }

    public function testLeWidgetNAffichePasLeCalculEconomiqueNiLAjustement(): void
    {
        // Le premier chiffre public, c'est la valeur verte : pas le coût / reste à
        // charge (§économique pro), et surtout aucun ajustement du pro (§4).
        [$client, $token] = $this->preparer();

        $this->estimer($client, $token);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Coût de la rénovation', $html);
        self::assertSelectorNotExists('input[name="ajustement"]');
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function preparer(
        bool $couvert = true,
        string $nom = 'Agence Durand',
        string $couleur = '#0E4A5C',
    ): array {
        $client = static::createClient();
        // Le conteneur (et donc les doubles en mémoire) doit survivre du GET au POST.
        $client->disableReboot();

        $token = $this->creerPage($client, slug: 'agence-durand', couleur: $couleur, nom: $nom);
        // Le plafond anti-abus (#43) persiste entre tests : sans reset, l'IP par
        // défaut « 127.0.0.1 » partagée finit par saturer et un parcours légitime
        // récolterait un 429. Même convention que les tests d'inscription / mdp oublié.
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

    private function estimer(
        KernelBrowser $client,
        string $token,
        string $dpeAvant = 'E',
        string $dpeApres = 'C',
        string $surfaceBatie = '90',
        string $surfaceTerrain = '500',
        string $codeInsee = self::CODE_INSEE,
    ): void {
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', [
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => $codeInsee,
            'surface_batie' => $surfaceBatie,
            'surface_terrain' => $surfaceTerrain,
            'dpe_avant' => $dpeAvant,
            'dpe_apres' => $dpeApres,
            'jeton_chargement' => $this->jetonChargementValide(),
        ]);
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

    private function creerPage(
        KernelBrowser $client,
        string $slug,
        string $couleur,
        ?string $nom,
    ): string {
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenant = $tenants->nouveau();
        $tenantId = $tenant->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $branding = Branding::creer(Couleur::depuisHex($couleur), null, null, null, null, $nom);
        $branding->setTenantId($tenantId);
        $em->persist($branding);

        $token = bin2hex(random_bytes(5));
        $em->persist(PagePublique::creer($tenantId, $token, $slug));
        $em->flush();

        return $token;
    }
}
