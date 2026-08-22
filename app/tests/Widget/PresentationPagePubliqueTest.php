<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Mise en forme de la page publique hébergée (#290, épic #6, décision D9).
 *
 * La marque blanche est la promesse de la page : un visiteur doit y reconnaître le
 * pro, pas nous. Ce qui se teste ici n'est pas le goût — c'est ce dont la promesse
 * dépend et qu'une refonte de style pourrait casser en silence : une feuille de
 * style effectivement servie, l'absence de dépendance à un tiers, la couleur du pro
 * lisible quelle qu'elle soit, et ses mentions légales portées par la page.
 */
final class PresentationPagePubliqueTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';

    public function testLaPageChargeUneFeuilleDeStyleServieParNous(): void
    {
        // Sans feuille de style, la page est du HTML brut : la marque du pro
        // n'existe pas au rendu, quelle que soit la couleur enregistrée.
        $crawler = $this->afficher();

        $feuilles = $crawler->filter('link[rel="stylesheet"]')->extract(['href']);

        self::assertNotEmpty($feuilles, 'La page publique doit charger une feuille de style.');
        foreach ($feuilles as $href) {
            self::assertIsString($href);
            self::assertStringStartsWith(
                '/',
                $href,
                'La CSP de la zone est `style-src \'self\'` : une feuille tierce serait bloquée.',
            );
        }
    }

    public function testLaPageNeDependDAucunHoteTiersHorsTurnstile(): void
    {
        // Parti pris déjà tenu pour l'importmap (#90) et la BAN (#287) : une page
        // qui annonce une collecte anonyme et sans partage ne va rien chercher
        // ailleurs. Les polices comprises — d'autant que `default-src 'self'` les
        // bloquerait sans que rien ne le signale, sinon un rendu qui retombe en
        // police système sans qu'on sache pourquoi.
        $crawler = $this->afficher();

        $sources = array_merge(
            $crawler->filter('link')->extract(['href']),
            $crawler->filter('script')->extract(['src']),
            $crawler->filter('img')->extract(['src']),
        );

        foreach ($sources as $source) {
            self::assertIsString($source);
            if ('' === $source || str_starts_with($source, 'https://challenges.cloudflare.com')) {
                continue;
            }

            self::assertStringStartsWith('/', $source, \sprintf('Ressource tierce sur la page publique : « %s ».', $source));
        }
    }

    public function testUneMarqueClaireRendUnTexteEncreEtNonDuBlancSurBlanc(): void
    {
        // Le nom du pro et ses boutons se posent SUR sa couleur : en jaune vif, du
        // blanc dessus ne se lit plus. La page porte donc la couleur de texte
        // décidée au contraste, à côté de la couleur de marque.
        $style = (string) $this->afficher(couleur: '#FFD400')->filter('[data-page="widget"]')->attr('style');

        self::assertStringContainsString('#FFD400', $style);
        self::assertStringContainsString('#111820', $style, 'Sur une marque claire, le texte doit passer à l\'encre.');
        // Et dans l'autre sens : le chiffre héros ne s'écrit pas en jaune sur blanc.
        self::assertStringContainsString('--marque-encre: #111820', $style);
    }

    public function testUneMarqueSombreRendUnTexteBlanc(): void
    {
        $style = (string) $this->afficher(couleur: '#0E4A5C')->filter('[data-page="widget"]')->attr('style');

        self::assertStringContainsString('#FFFFFF', $style);
    }

    public function testLEtiquetteDpeSeChoisitSurUneEchelleColoreeDeSeptCrans(): void
    {
        // Une liste déroulante montre « A » et cache le reste : sur mobile elle
        // ouvre un sélecteur natif qui masque la page entière pour lire sept
        // lettres. Le DPE est une **échelle de couleurs** que tout le monde
        // reconnaît — on la montre, on ne la replie pas.
        $crawler = $this->afficher();

        self::assertCount(0, $crawler->filter('select[name="dpe_avant"]'));
        self::assertCount(7, $crawler->filter('input[type="radio"][name="dpe_avant"]'));
        self::assertCount(7, $crawler->filter('input[type="radio"][name="dpe_apres"]'));

        // Chaque cran porte sa couleur de classe : c'est ce qui rend l'échelle
        // lisible d'un coup d'œil plutôt que lettre à lettre.
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $classe) {
            self::assertCount(
                1,
                $crawler->filter('label[for="dpe_avant_'.$classe.'"].d-'.$classe),
                \sprintf('Le cran %s doit porter sa couleur de classe.', strtoupper($classe)),
            );
        }
    }

    public function testAucuneEtiquetteActuelleNestPreCochee(): void
    {
        // L'ancienne liste déroulante arrivait sur « A » — la meilleure classe.
        // Un visiteur pressé estimait donc une rénovation A → C, c'est-à-dire une
        // dégradation, sans avoir rien choisi. L'étiquette actuelle est un fait
        // que seul le visiteur connaît : elle se choisit, elle ne se devine pas.
        $crawler = $this->afficher();

        self::assertCount(0, $crawler->filter('input[name="dpe_avant"][checked]'));
    }

    public function testLObjectifEstPreCocheSurC(): void
    {
        // L'objectif, lui, a une valeur produit : le seuil C est l'effet que le
        // dispositif met en avant (#27). Il reste modifiable d'un geste.
        $crawler = $this->afficher();

        self::assertCount(1, $crawler->filter('input[name="dpe_apres"][value="C"][checked]'));
    }

    public function testLaPagePorteLesMentionsLegalesDuPro(): void
    {
        // Elles sont configurées (#36) et portées par le PDF depuis toujours ; la
        // page publique, elle, les passait à la trappe — c'est pourtant là que le
        // particulier lit un chiffre avant tout document.
        $crawler = $this->afficher(mentionsLegales: 'Estimation indicative, sans valeur d\'expertise.');

        self::assertStringContainsString(
            'Estimation indicative, sans valeur d\'expertise.',
            $crawler->filter('[data-marque="mentions"]')->text(),
        );
    }

    public function testLePiedDePagePorteLeRgeEtLeTelephoneDuPro(): void
    {
        $pied = $this->afficher(numeroRge: 'RGE-2026-31-0847', telephone: '05 61 00 00 00')
            ->filter('[data-marque="pied"]')->text();

        self::assertStringContainsString('RGE-2026-31-0847', $pied);
        self::assertStringContainsString('05 61 00 00 00', $pied);
    }

    public function testUnProSansMentionsNiRgeNAffichePasDeLigneVide(): void
    {
        // « Un rapport avant toute configuration » : une page publiée avant d'avoir
        // rempli sa marque ne doit pas afficher un pied à trous.
        $crawler = $this->afficher(nom: null, mentionsLegales: null, numeroRge: null, telephone: null);

        self::assertCount(0, $crawler->filter('[data-marque="mentions"]'));
        self::assertCount(0, $crawler->filter('[data-marque="pied"]'));
    }

    private function afficher(
        string $couleur = '#0E4A5C',
        ?string $nom = 'Agence Durand',
        ?string $numeroRge = null,
        ?string $telephone = null,
        ?string $mentionsLegales = null,
    ): Crawler {
        $client = static::createClient();
        $token = $this->creerPage($client, $couleur, $nom, $numeroRge, $telephone, $mentionsLegales);

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function creerPage(
        KernelBrowser $client,
        string $couleur,
        ?string $nom,
        ?string $numeroRge,
        ?string $telephone,
        ?string $mentionsLegales,
    ): string {
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $branding = Branding::creer(Couleur::depuisHex($couleur), $numeroRge, $telephone, $mentionsLegales, null, $nom);
        $branding->setTenantId($tenantId);
        $em->persist($branding);

        $token = bin2hex(random_bytes(5));
        $em->persist(PagePublique::creer($tenantId, $token, 'agence-durand'));
        $em->flush();

        return $token;
    }
}
