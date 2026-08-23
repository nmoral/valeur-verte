<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use App\Tests\BackOffice\Entreprise\Double\RechercheEntrepriseEnMemoire;
use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Entreprise » de l'espace pro (#311). La fiche était saisie une fois à
 * l'inscription puis figée — le menu l'annonçait lui-même « à venir ». Le pro doit
 * pouvoir la consulter et la corriger seul, un changement de SIRET restant soumis à
 * re-vérification.
 */
final class EntrepriseControllerTest extends WebTestCase
{
    use ConnecteUnPro;

    private const SIRET_EN_FICHE = '55555555555555';

    public function testLEcranAfficheLaFicheEtSesFaitsDeSource(): void
    {
        $client = static::createClient();
        $this->semerFiche($client);

        $client->request('GET', self::urlPro('/entreprise'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1, h2', 'Votre entreprise');
        self::assertSelectorTextContains('nav.side a.on', 'Entreprise');
        self::assertSelectorExists('input[name="raison_sociale"][value="Renov Habitat"]');
        self::assertSelectorExists('input[name="siret"][value="'.self::SIRET_EN_FICHE.'"]');
        // Faits de source : affichés, jamais saisissables.
        self::assertSelectorTextContains('body', '43.29A');
        self::assertSelectorTextContains('body', 'RGE');
        self::assertSelectorTextContains('body', 'Isolation des murs');
        self::assertSelectorNotExists('input[name="code_naf"]');
        self::assertSelectorNotExists('input[name="statut_rge"]');
    }

    public function testUnProSansFicheEstInviteASaisirSonSiret(): void
    {
        // SIRET inconnu de l'API à l'inscription (#106) : aucune fiche n'existe.
        // L'écran doit offrir un chemin, pas une page vide.
        $client = static::createClient();
        $this->connecterPro($client);

        $client->request('GET', self::urlPro('/entreprise'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="siret"]');
        self::assertSelectorTextContains('body', 'SIRET');
    }

    public function testCorrigerLaRaisonSocialeEtLAdresseLesPersiste(): void
    {
        $client = static::createClient();
        $tenantId = $this->semerFiche($client);

        $client->request('POST', self::urlPro('/entreprise'), [
            'siret' => self::SIRET_EN_FICHE,
            'raison_sociale' => 'Rénov Habitat SARL',
            'adresse' => '8 avenue des Cèdres, 31200 Toulouse',
        ]);

        self::assertResponseRedirects('/entreprise');
        $fiche = $this->relire($tenantId);
        self::assertSame('Rénov Habitat SARL', $fiche->raisonSociale());
        self::assertSame('8 avenue des Cèdres, 31200 Toulouse', $fiche->adresse());
    }

    public function testUneRaisonSocialeVideEstRefuseeAvecUnMessage(): void
    {
        $client = static::createClient();
        $tenantId = $this->semerFiche($client);

        $client->request('POST', self::urlPro('/entreprise'), [
            'siret' => self::SIRET_EN_FICHE,
            'raison_sociale' => '  ',
            'adresse' => '8 avenue des Cèdres, 31200 Toulouse',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'raison sociale');
        self::assertSame('Renov Habitat', $this->relire($tenantId)->raisonSociale(), 'La fiche ne doit pas bouger.');
    }

    public function testUnSiretIntrouvableEstRefuseSansToucherLaFiche(): void
    {
        $client = static::createClient();
        $tenantId = $this->semerFiche($client);

        $client->request('POST', self::urlPro('/entreprise'), [
            'siret' => '77777777777777',
            'raison_sociale' => 'Renov Habitat',
            'adresse' => '12 rue des Lilas, 31000 Toulouse',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'vérifi');
        self::assertSame(self::SIRET_EN_FICHE, $this->relire($tenantId)->siret());
    }

    public function testUnSiretMalFormeEstRefuseAvecUnMessage(): void
    {
        $client = static::createClient();
        $this->semerFiche($client);

        $client->request('POST', self::urlPro('/entreprise'), [
            'siret' => '123',
            'raison_sociale' => 'Renov Habitat',
            'adresse' => '12 rue des Lilas, 31000 Toulouse',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'SIRET');
    }

    public function testUnSiretVerifieRemplaceLIdentiteDeLaFiche(): void
    {
        $client = static::createClient();
        $tenantId = $this->semerFiche($client);

        $client->request('POST', self::urlPro('/entreprise'), [
            'siret' => RechercheEntrepriseEnMemoire::SIRET_CONNU,
            'raison_sociale' => 'Saisie ignorée',
            'adresse' => 'Adresse ignorée',
        ]);

        self::assertResponseRedirects('/entreprise');
        $fiche = $this->relire($tenantId);
        self::assertSame(RechercheEntrepriseEnMemoire::SIRET_CONNU, $fiche->siret());
        // L'identité vérifiée fait foi : c'est tout l'objet du contrôle.
        self::assertSame('Renov Habitat', $fiche->raisonSociale());
        self::assertSame('12 rue des Lilas, 31000 Toulouse', $fiche->adresse());
    }

    public function testUnProNeVoitPasLaFicheDUnAutre(): void
    {
        $client = static::createClient();
        $this->connecterPro($client, 'entreprise-voisin@example.com');
        $this->semerPourTenant(999_311, 'Entreprise Du Voisin');

        $client->request('GET', self::urlPro('/entreprise'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Entreprise Du Voisin');
    }

    private function semerFiche(KernelBrowser $client): int
    {
        $tenantId = $this->connecterPro($client);
        $this->semerPourTenant($tenantId, 'Renov Habitat');

        return $tenantId;
    }

    private function semerPourTenant(int $tenantId, string $raisonSociale): void
    {
        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis(self::SIRET_EN_FICHE),
            $raisonSociale,
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
        $entreprise->setTenantId($tenantId);
        $this->entreprises()->enregistrer($entreprise);
    }

    private function relire(int $tenantId): Entreprise
    {
        $fiche = $this->entreprises()->findOneBy(['tenantId' => $tenantId]);
        self::assertInstanceOf(Entreprise::class, $fiche);

        return $fiche;
    }

    private function entreprises(): EntrepriseRepository
    {
        $repository = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $repository);

        return $repository;
    }
}
