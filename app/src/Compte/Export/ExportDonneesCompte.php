<?php

declare(strict_types=1);

namespace App\Compte\Export;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\GrilleCoutRepository;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Widget\DemandeLead;
use App\Widget\DemandeLeadRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Psr\Clock\ClockInterface;

/**
 * Assemble l'export des données d'un compte (#309, RGPD — droits d'accès et de
 * portabilité). Rassemble, par tenant, tout ce qui appartient au pro dans un
 * arbre associatif de scalaires prêt à sérialiser en JSON : le **miroir en
 * lecture** du périmètre effacé par EffacementCompte (#308).
 *
 * Un seul assembleur pour les deux zones : le pro exporte son propre tenant
 * depuis « Mon compte », l'admin celui d'une demande reçue par email. Le tenant
 * est contraint **explicitement** par chaque lecteur `parTenant`, donc le
 * résultat ne dépend pas de l'état du filtre Doctrine (inactif côté admin).
 */
final readonly class ExportDonneesCompte
{
    public const SCHEMA = 'valeur-verte/export-compte/v1';

    public function __construct(
        private CompteRepository $comptes,
        private UserRepository $utilisateurs,
        private EntrepriseRepository $entreprises,
        private GrilleCoutRepository $grilles,
        private BouquetRepository $bouquets,
        private BrandingRepository $marques,
        private PagePubliqueRepository $pages,
        private RapportRepository $rapports,
        private DemandeLeadRepository $leads,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function exporter(int $tenantId): array
    {
        $entreprise = $this->entreprises->parTenant($tenantId);
        $grille = $this->grilles->parTenant($tenantId);
        $page = $this->pages->parTenant($tenantId);
        $compte = $this->comptes->pourTenant($tenantId);

        return [
            'schema' => self::SCHEMA,
            'genere_le' => $this->clock->now()->format(\DATE_ATOM),
            'tenant_id' => $tenantId,
            'compte' => null === $compte ? null : $this->compte($compte),
            'utilisateurs' => array_map(
                fn (User $u): array => $this->utilisateur($u),
                $this->utilisateurs->parTenant($tenantId),
            ),
            'entreprise' => null === $entreprise ? null : $this->entreprise($entreprise),
            'reglages' => [
                'grille_couts' => null === $grille ? null : $this->grille($grille),
                'bouquets' => array_map(
                    fn (Bouquet $b): array => $this->bouquet($b),
                    $this->bouquets->parTenant($tenantId),
                ),
                'marque' => $this->marque($this->marques->pourTenant($tenantId)),
                'page_publique' => null === $page ? null : $this->pagePublique($page),
            ],
            'rapports' => array_map(
                fn (Rapport $r): array => $this->rapport($r),
                $this->rapports->parTenant($tenantId),
            ),
            'leads_widget' => array_map(
                fn (DemandeLead $l): array => $this->lead($l),
                $this->leads->parTenant($tenantId),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compte(Compte $compte): array
    {
        return [
            'formule' => $compte->formule()->value,
            'cree_le' => $compte->creeLe()->format(\DATE_ATOM),
            'suspendu_le' => $compte->suspenduLe()?->format(\DATE_ATOM),
            'suppression_demandee_le' => $compte->suppressionDemandeeLe()?->format(\DATE_ATOM),
            'essai_prolonge_jusquau' => $compte->essaiProlongeJusquau()?->format(\DATE_ATOM),
            'desabonne_des_emails' => $compte->estDesabonneDesEmails(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function utilisateur(User $user): array
    {
        return [
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'email_verifie' => $user->emailEstVerifie(),
            'version_conditions_acceptee' => $user->versionConditionsAcceptee(),
            'conditions_acceptees_le' => $user->conditionsAccepteesLe()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entreprise(Entreprise $entreprise): array
    {
        return [
            'siret' => $entreprise->siret(),
            'raison_sociale' => $entreprise->raisonSociale(),
            'adresse' => $entreprise->adresse(),
            'code_naf' => $entreprise->codeNaf(),
            'effectif' => $entreprise->effectif(),
            'statut_rge' => $entreprise->statutRge()->value,
            'domaines_rge' => $entreprise->domainesRge(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function grille(GrilleCout $grille): array
    {
        $montants = [];
        foreach (Poste::cases() as $poste) {
            $montants[$poste->name] = $grille->montant($poste)->euros();
        }

        return $montants;
    }

    /**
     * @return array<string, mixed>
     */
    private function bouquet(Bouquet $bouquet): array
    {
        return [
            'nom' => $bouquet->nom(),
            'postes' => array_map(static fn (Poste $p): string => $p->name, $bouquet->postes()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marque(Branding $marque): array
    {
        return [
            'nom' => $marque->nom(),
            'couleur' => $marque->couleurPrincipale()->hex(),
            'numero_rge' => $marque->numeroRge(),
            'telephone' => $marque->telephone(),
            'mentions_legales' => $marque->mentionsLegales(),
            'logo_url' => $marque->logoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePublique(PagePublique $page): array
    {
        return [
            'slug' => $page->slug(),
            'token' => $page->token(),
            'active' => $page->estActive(),
            'suspendue' => $page->estSuspendue(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rapport(Rapport $rapport): array
    {
        $contact = $rapport->contact();

        return [
            'reference' => $rapport->reference(),
            'cree_le' => $rapport->creeLe()->format(\DATE_ATOM),
            'source' => $rapport->source()->value,
            'adresse' => $rapport->adresse(),
            'dpe_avant' => $rapport->dpeAvant()->value,
            'dpe_apres' => $rapport->dpeApres()->value,
            'valeur_verte_estimee' => $rapport->valeurVerteEstimee(),
            'pdf_url' => $rapport->pdfUrl(),
            'anonymise_le' => $rapport->anonymiseLe()?->format(\DATE_ATOM),
            'contact' => null === $contact ? null : [
                'nom' => $contact->nom(),
                'email' => $contact->email(),
                'telephone' => $contact->telephone(),
            ],
            'instantane' => $rapport->instantane()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lead(DemandeLead $lead): array
    {
        return [
            'contact_nom' => $lead->contactNom(),
            'contact_email' => $lead->contactEmail(),
            'instantane' => $lead->instantane(),
        ];
    }
}
