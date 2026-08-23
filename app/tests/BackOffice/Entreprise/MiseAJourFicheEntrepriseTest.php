<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\MiseAJourFicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\SiretInvalide;
use App\BackOffice\Entreprise\SiretNonVerifie;
use App\BackOffice\Entreprise\StatutRge;
use App\Tenant\TenantContext;
use App\Tests\BackOffice\Entreprise\Double\RechercheEntrepriseEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mise à jour de la fiche entreprise par le pro (#311).
 *
 * La règle qui structure tout : **le SIRET identifie le redevable**. Raison
 * sociale et adresse se corrigent librement — le pro les connaît mieux que la
 * source ; un SIRET, lui, ne se change qu'après re-vérification auprès de l'API
 * entreprise (#106), sinon une facture partirait au mauvais nom. Chaque geste est
 * inscrit au journal d'audit (#50).
 */
final class MiseAJourFicheEntrepriseTest extends KernelTestCase
{
    private const TENANT = 7311;
    private const SIRET_INCONNU = '55555555555555';

    public function testUneCorrectionNeReVerifiePasUnSiretInchange(): void
    {
        // La fiche porte un SIRET que l'API ne connaît pas : si la mise à jour
        // le re-vérifiait, la correction serait refusée. Elle passe, donc le
        // SIRET inchangé n'est pas remis en cause — on ne punit pas un pro dont
        // l'établissement a disparu des annuaires pour une faute de frappe.
        $this->semerFiche(self::SIRET_INCONNU, 'Renov Habitat', '12 rue des Lilas, 31000 Toulouse');

        $this->miseAJour()->enregistrer(
            self::SIRET_INCONNU,
            'Rénov Habitat',
            '8 avenue des Cèdres, 31200 Toulouse',
            'pro@example.com',
        );

        $fiche = $this->relire();
        self::assertSame('Rénov Habitat', $fiche->raisonSociale());
        self::assertSame('8 avenue des Cèdres, 31200 Toulouse', $fiche->adresse());
        self::assertSame(self::SIRET_INCONNU, $fiche->siret());
        self::assertSame('entreprise.corrigee', $this->dernierEvenement()->action());
    }

    public function testUnSiretChangeEstReVerifieEtRapporteSaPropreIdentite(): void
    {
        $this->semerFiche(self::SIRET_INCONNU, 'Ancienne Raison', '1 rue d\'Avant, 31000 Toulouse');

        $this->miseAJour()->enregistrer(
            RechercheEntrepriseEnMemoire::SIRET_CONNU,
            'Ce que le pro a tapé',
            'Ce que le pro a tapé aussi',
            'pro@example.com',
        );

        // L'établissement n'est plus le même : c'est l'identité vérifiée qui fait
        // foi, pas la saisie — sans quoi le contrôle du SIRET ne servirait à rien.
        $fiche = $this->relire();
        self::assertSame(RechercheEntrepriseEnMemoire::SIRET_CONNU, $fiche->siret());
        self::assertSame('Renov Habitat', $fiche->raisonSociale());
        self::assertSame('12 rue des Lilas, 31000 Toulouse', $fiche->adresse());
        self::assertSame('43.29A', $fiche->codeNaf());

        $evenement = $this->dernierEvenement();
        self::assertSame('entreprise.siret_change', $evenement->action());
        self::assertSame(self::SIRET_INCONNU, $evenement->contexte()['siret_precedent'] ?? null);
        self::assertSame(RechercheEntrepriseEnMemoire::SIRET_CONNU, $evenement->contexte()['siret'] ?? null);
    }

    public function testUnSiretIntrouvableEstRefuseEtNeTouchePasLaFiche(): void
    {
        $this->semerFiche(
            RechercheEntrepriseEnMemoire::SIRET_CONNU,
            'Renov Habitat',
            '12 rue des Lilas, 31000 Toulouse',
        );

        try {
            $this->miseAJour()->enregistrer(self::SIRET_INCONNU, 'Renov Habitat', '12 rue des Lilas', 'pro@example.com');
            self::fail('Un SIRET invérifiable devait être refusé.');
        } catch (SiretNonVerifie) {
            // Attendu : la facturation se ferait au mauvais nom.
        }

        $fiche = $this->relire();
        self::assertSame(RechercheEntrepriseEnMemoire::SIRET_CONNU, $fiche->siret(), 'La fiche ne doit pas bouger.');
        self::assertSame([], $this->journal()->evenementsRecents(), 'Un refus ne s\'inscrit pas comme un changement.');
    }

    public function testUnSiretMalFormeEstRefuseAvantToutAppel(): void
    {
        $this->semerFiche(RechercheEntrepriseEnMemoire::SIRET_CONNU, 'Renov Habitat', '12 rue des Lilas');

        $this->expectException(SiretInvalide::class);

        $this->miseAJour()->enregistrer('123', 'Renov Habitat', '12 rue des Lilas', 'pro@example.com');
    }

    public function testUnProSansFicheEnObtientUneParSonSiretVerifie(): void
    {
        // Inscription avec un SIRET que l'API ignorait : aucune fiche n'avait été
        // créée (#106). L'écran doit lui donner un chemin, pas un cul-de-sac.
        $this->contexteTenant()->definir(self::TENANT);

        $this->miseAJour()->enregistrer(
            RechercheEntrepriseEnMemoire::SIRET_CONNU,
            '',
            '',
            'pro@example.com',
        );

        $fiche = $this->relire();
        self::assertSame(RechercheEntrepriseEnMemoire::SIRET_CONNU, $fiche->siret());
        self::assertSame('Renov Habitat', $fiche->raisonSociale());
        self::assertSame(self::TENANT, $fiche->getTenantId());
        self::assertSame('entreprise.siret_change', $this->dernierEvenement()->action());
    }

    private function semerFiche(string $siret, string $raisonSociale, string $adresse): void
    {
        $this->contexteTenant()->definir(self::TENANT);
        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis($siret),
            $raisonSociale,
            $adresse,
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
        $entreprise->setTenantId(self::TENANT);
        $this->entreprises()->enregistrer($entreprise);
    }

    private function relire(): Entreprise
    {
        $fiche = $this->entreprises()->findOneBy(['tenantId' => self::TENANT]);
        self::assertInstanceOf(Entreprise::class, $fiche);

        return $fiche;
    }

    private function dernierEvenement(): EvenementAudit
    {
        $evenements = $this->journal()->evenementsRecents(1);
        self::assertNotEmpty($evenements, 'Le geste doit être inscrit au journal d\'audit.');

        return $evenements[0];
    }

    private function journal(): JournalAuditDoctrine
    {
        $journal = $this->manager()->getRepository(EvenementAudit::class);
        self::assertInstanceOf(JournalAuditDoctrine::class, $journal);

        return $journal;
    }

    private function entreprises(): EntrepriseRepository
    {
        $repository = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $repository);

        return $repository;
    }

    private function miseAJour(): MiseAJourFicheEntreprise
    {
        $service = self::getContainer()->get(MiseAJourFicheEntreprise::class);
        self::assertInstanceOf(MiseAJourFicheEntreprise::class, $service);

        return $service;
    }

    private function contexteTenant(): TenantContext
    {
        $contexte = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $contexte);

        return $contexte;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
