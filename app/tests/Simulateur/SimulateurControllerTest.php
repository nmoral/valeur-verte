<?php

declare(strict_types=1);

namespace App\Tests\Simulateur;

use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Simulateur\SimulateurLeadRepository;
use App\Simulateur\UtilisationRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Simulateur public (#385) : la copie publique du widget, sur le domaine principal.
 *
 * Le rapport n'est délivré que par email : un seul formulaire (adresse → surfaces →
 * DPE → email obligatoire + case de conservation décochée) ; à la soumission, on
 * calcule, on envoie le rapport par mail, on incrémente un compteur d'usage
 * **anonyme**, et on ne conserve l'email en base **que si** la case est cochée.
 */
final class SimulateurControllerTest extends WebTestCase
{
    private const string HOTE = 'valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testLeFormulaireEstAffiche(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/simulateur');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-simulateur="estimation"]');
        self::assertSelectorExists('input[name="adresse"]');
        self::assertSelectorExists('input[name="surface_batie"]');
        self::assertSelectorExists('input[name="email"]');
        // La case de conservation existe et est décochée par défaut.
        $case = $client->getCrawler()->filter('input[type="checkbox"][name="consentement"]');
        self::assertSame(1, $case->count());
        self::assertNull($case->attr('checked'));
    }

    public function testUnRapportEstEnvoyeParEmailEtLUsageEstCompteSansConserverLEmail(): void
    {
        $client = $this->prepare(couvert: true);
        $usagesAvant = $this->usages($client)->compter();
        $leadsAvant = $this->leads($client)->compter();

        $this->soumettre($client, email: 'visiteur@example.com', consentement: false);

        self::assertResponseIsSuccessful();
        // Le rapport part par email vers l'adresse saisie.
        $envoyes = $this->mailer($client)->envoyes();
        self::assertCount(1, $envoyes);
        self::assertSame('visiteur@example.com', $envoyes[0]->destinataire());
        // Usage compté (anonyme), mais l'email n'est PAS conservé (case décochée).
        self::assertSame($usagesAvant + 1, $this->usages($client)->compter());
        self::assertSame($leadsAvant, $this->leads($client)->compter());
    }

    public function testAvecConsentementLEmailEstConserve(): void
    {
        $client = $this->prepare(couvert: true);
        $leadsAvant = $this->leads($client)->compter();

        $this->soumettre($client, email: 'oui@example.com', consentement: true);

        self::assertResponseIsSuccessful();
        self::assertSame($leadsAvant + 1, $this->leads($client)->compter());
    }

    public function testHorsCouvertureNEnvoiePasDeRapportMaisCompteLUsage(): void
    {
        $client = $this->prepare(couvert: false);
        $usagesAvant = $this->usages($client)->compter();

        $this->soumettre($client, email: 'visiteur@example.com', consentement: true);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->mailer($client)->envoyes());
        self::assertSame($usagesAvant + 1, $this->usages($client)->compter());
        self::assertSame(0, $this->leads($client)->compter());
    }

    public function testSansEmailLeRapportNestPasDelivre(): void
    {
        $client = $this->prepare(couvert: true);
        $leadsAvant = $this->leads($client)->compter();

        $this->soumettre($client, email: '', consentement: true);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->mailer($client)->envoyes());
        self::assertSame($leadsAvant, $this->leads($client)->compter());
    }

    public function testLAutocompletionRepondEnJson(): void
    {
        $client = static::createClient();
        $this->libererLimiteurs();
        $recherche = static::getContainer()->get(\App\Tests\Widget\Double\RechercheAdresseEnMemoire::class);
        self::assertInstanceOf(\App\Tests\Widget\Double\RechercheAdresseEnMemoire::class, $recherche);
        $recherche->definir(\App\Widget\AdresseSuggeree::creer('12 rue du Taur, Toulouse', self::CODE_INSEE));

        $client->request('GET', 'http://'.self::HOTE.'/simulateur/adresses?q=12 rue du Taur');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $donnees = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($donnees);
        self::assertSame(self::CODE_INSEE, $donnees[0]['code_insee']);
    }

    private function prepare(bool $couvert): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->libererLimiteurs();

        if ($couvert) {
            $this->coefficients($client)->definir(self::CODE_INSEE, CoefficientMaille::depuis(
                millesime: 2025,
                rayonKm: 10,
                n: 134,
                erreurMediane: 17.22,
                coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
                portee: PorteeEstimation::Local,
            ));
            $this->prix($client)->definir(self::CODE_INSEE, 1635.0);
        }

        return $client;
    }

    private function soumettre(KernelBrowser $client, string $email, bool $consentement): void
    {
        $parametres = [
            'adresse' => '12 rue du Taur, Toulouse',
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '95',
            'surface_terrain' => '300',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'email' => $email,
        ];
        if ($consentement) {
            $parametres['consentement'] = '1';
        }

        $client->request('POST', 'http://'.self::HOTE.'/simulateur', $parametres);
    }

    private function libererLimiteurs(string $ip = '127.0.0.1'): void
    {
        foreach (['limiter.simulateur_ip', 'limiter.simulateur_adresse_ip'] as $nom) {
            $factory = static::getContainer()->get($nom);
            self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
            $factory->create($ip)->reset();
        }
    }

    private function mailer(KernelBrowser $client): MailerEnMemoire
    {
        $double = $client->getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $double);

        return $double;
    }

    private function usages(KernelBrowser $client): UtilisationRepository
    {
        $depot = $client->getContainer()->get(UtilisationRepository::class);
        self::assertInstanceOf(UtilisationRepository::class, $depot);

        return $depot;
    }

    private function leads(KernelBrowser $client): SimulateurLeadRepository
    {
        $depot = $client->getContainer()->get(SimulateurLeadRepository::class);
        self::assertInstanceOf(SimulateurLeadRepository::class, $depot);

        return $depot;
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
