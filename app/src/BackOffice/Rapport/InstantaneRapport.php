<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\BackOffice\Restitution\Restitution;

/**
 * Instantané immuable du contexte d'un rapport (#33, §9.1, décision D5).
 *
 * Sérialisé en JSONB, il fige l'intégralité de ce qui a produit le PDF — saisie,
 * restitution, coefficients employés, marque du pro, référence — pour que le
 * document soit **régénéré à l'identique** indéfiniment, jamais recalculé. Un
 * client qui revient 18 mois plus tard retrouve le même chiffre, expliqué par le
 * même contexte, quel que soit l'état courant des tables de paramètres.
 */
final readonly class InstantaneRapport
{
    /**
     * v2 (#26) : ajoute le chiffrage dérivé et le barème d'aides. v3 (#27) : ajoute
     * l'effet de seuil. Les instantanés antérieurs en sont dépourvus et se relisent
     * sans — ils n'affichent ni reste à charge ni table de seuil, ce qui est exact
     * plutôt qu'inventé.
     */
    private const VERSION = 3;

    private function __construct(
        private SaisieAudit $saisie,
        private ?Restitution $restitution,
        private ?ContexteCalcul $contexte,
        private Branding $branding,
        private ReferenceRapport $reference,
        private \DateTimeImmutable $genereLe,
        private ?Bouquet $bouquet = null,
        private ?Chiffrage $chiffrage = null,
        private ?BaremeAides $baremeAides = null,
        private ?EffetDeSeuil $effetDeSeuil = null,
    ) {
    }

    public static function creer(
        SaisieAudit $saisie,
        ?Restitution $restitution,
        ?ContexteCalcul $contexte,
        Branding $branding,
        ReferenceRapport $reference,
        \DateTimeImmutable $genereLe,
        ?Bouquet $bouquet = null,
        ?Chiffrage $chiffrage = null,
        ?BaremeAides $baremeAides = null,
        ?EffetDeSeuil $effetDeSeuil = null,
    ): self {
        return new self(
            $saisie,
            $restitution,
            $contexte,
            $branding,
            $reference,
            $genereLe,
            $bouquet,
            $chiffrage,
            $baremeAides,
            $effetDeSeuil,
        );
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public static function fromArray(array $donnees): self
    {
        $calcul = $donnees['calcul'] ?? null;

        return new self(
            self::saisieDepuis(self::sousTableau($donnees['saisie'])),
            self::restitutionDepuis($donnees['restitution'] ?? null),
            null === $calcul ? null : ContexteCalcul::fromArray(self::contexteDepuis($calcul)),
            self::brandingDepuis(self::sousTableau($donnees['marque'])),
            ReferenceRapport::depuis(self::texte($donnees['reference'])),
            new \DateTimeImmutable(self::texte($donnees['genere_le'])),
            self::bouquetDepuis($donnees['bouquet'] ?? null),
            self::chiffrageDepuis($donnees['chiffrage'] ?? null),
            self::baremeDepuis($donnees['bareme_aides'] ?? null, self::texte($donnees['genere_le'])),
            self::effetDeSeuilDepuis($donnees['effet_de_seuil'] ?? null),
        );
    }

    public function saisie(): SaisieAudit
    {
        return $this->saisie;
    }

    public function restitution(): ?Restitution
    {
        return $this->restitution;
    }

    public function contexte(): ?ContexteCalcul
    {
        return $this->contexte;
    }

    public function branding(): Branding
    {
        return $this->branding;
    }

    public function reference(): ReferenceRapport
    {
        return $this->reference;
    }

    public function genereLe(): \DateTimeImmutable
    {
        return $this->genereLe;
    }

    public function bouquet(): ?Bouquet
    {
        return $this->bouquet;
    }

    /**
     * Effet de seuil C figé (#27). Null sur les instantanés antérieurs à v3.
     */
    public function effetDeSeuil(): ?EffetDeSeuil
    {
        return $this->effetDeSeuil;
    }

    /**
     * Chiffrage tel que la grille du pro le donnait au moment de l'audit. Null sur
     * les instantanés antérieurs à #26.
     */
    public function chiffrage(): ?Chiffrage
    {
        return $this->chiffrage;
    }

    /**
     * Barème d'aides appliqué au moment de l'audit. Null sur les instantanés
     * antérieurs à #26.
     */
    public function baremeAides(): ?BaremeAides
    {
        return $this->baremeAides;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'genere_le' => $this->genereLe->format(\DateTimeInterface::ATOM),
            'reference' => $this->reference->valeur(),
            'saisie' => [
                'adresse' => $this->saisie->adresse(),
                'surface_batie' => $this->saisie->surfaceBatie(),
                'surface_terrain' => $this->saisie->surfaceTerrain(),
                'dpe_avant' => $this->saisie->dpeAvant()->value,
                'dpe_apres' => $this->saisie->dpeApres()->value,
                'nombre_pieces' => $this->saisie->nombrePieces(),
            ],
            'restitution' => null === $this->restitution ? null : [
                'montant_euros' => $this->restitution->montantEuros(),
                'borne_basse_euros' => $this->restitution->borneBasseEuros(),
                'borne_haute_euros' => $this->restitution->borneHauteEuros(),
                'n' => $this->restitution->n(),
                'rayon_km' => $this->restitution->rayonKm(),
                'millesime' => $this->restitution->millesime(),
                'prix_base_euros' => $this->restitution->prixBaseEuros(),
                'prix_saisi_par_le_pro' => $this->restitution->prixSaisiParLePro(),
                'mode' => $this->restitution->portee()->value,
                'palier' => $this->restitution->palier()->value,
                'bandes_appliquees' => $this->restitution->bandesAppliquees(),
                'partiellement_rapprochee' => $this->restitution->estPartiellementRapprochee(),
            ],
            'calcul' => $this->contexte?->toArray(),
            'marque' => [
                'couleur_hex' => $this->branding->couleurPrincipale()->hex(),
                'numero_rge' => $this->branding->numeroRge(),
                'telephone' => $this->branding->telephone(),
                'mentions_legales' => $this->branding->mentionsLegales(),
                'logo_url' => $this->branding->logoUrl(),
                'nom' => $this->branding->nom(),
            ],
            'bouquet' => null === $this->bouquet ? null : [
                'nom' => $this->bouquet->nom(),
                'postes' => array_map(static fn (Poste $poste): string => $poste->name, $this->bouquet->postes()),
            ],
            'chiffrage' => $this->chiffrage?->montantsDerives(),
            'bareme_aides' => $this->baremeAides?->toArray(),
            'effet_de_seuil' => $this->effetDeSeuil?->toArray(),
        ];
    }

    private static function effetDeSeuilDepuis(mixed $effet): ?EffetDeSeuil
    {
        if (null === $effet) {
            return null;
        }

        \assert(\is_array($effet));
        $paliers = [];
        foreach ($effet as $palier) {
            $palier = self::sousTableau($palier);
            $paliers[] = [
                'classe' => self::texte($palier['classe']),
                'gain_euros' => self::entier($palier['gain_euros']),
                'est_seuil' => self::booleen($palier['est_seuil']),
                'est_cible' => self::booleen($palier['est_cible']),
            ];
        }

        return EffetDeSeuil::fromArray($paliers);
    }

    private static function chiffrageDepuis(mixed $chiffrage): ?Chiffrage
    {
        if (null === $chiffrage) {
            return null;
        }

        $montants = [];
        foreach (self::sousTableau($chiffrage) as $poste => $euros) {
            $montants[$poste] = Montant::fromEuros(self::entier($euros));
        }

        return Chiffrage::figee($montants);
    }

    private static function baremeDepuis(mixed $bareme, string $genereLe): ?BaremeAides
    {
        if (null === $bareme) {
            return null;
        }

        // La période est reconstruite depuis la date de génération : le barème figé
        // vaut pour ce rapport, sa fenêtre de validité d'origine n'a plus d'usage.
        return BaremeAides::fromArray(
            self::sousTableau($bareme),
            PeriodeValidite::depuis(new \DateTimeImmutable($genereLe)),
        );
    }

    /**
     * @param array<string, mixed> $saisie
     */
    private static function saisieDepuis(array $saisie): SaisieAudit
    {
        return SaisieAudit::depuis(
            self::texte($saisie['adresse']),
            self::entier($saisie['surface_batie']),
            self::entier($saisie['surface_terrain']),
            Dpe::from(self::texte($saisie['dpe_avant'])),
            Dpe::from(self::texte($saisie['dpe_apres'])),
            self::entierOuNull($saisie['nombre_pieces']),
        );
    }

    private static function restitutionDepuis(mixed $restitution): ?Restitution
    {
        if (null === $restitution) {
            return null;
        }

        $restitution = self::sousTableau($restitution);
        $rayonKm = self::entierOuNull($restitution['rayon_km']);

        return Restitution::depuis(
            self::entier($restitution['montant_euros']),
            self::entier($restitution['borne_basse_euros']),
            self::entier($restitution['borne_haute_euros']),
            self::entier($restitution['n']),
            $rayonKm,
            self::entier($restitution['millesime']),
            self::entier($restitution['prix_base_euros']),
            self::booleen($restitution['prix_saisi_par_le_pro']),
            PorteeEstimation::depuis(self::texteOuNull($restitution['mode'] ?? null), $rayonKm),
            PalierFiabilite::depuis(self::texteOuNull($restitution['palier'] ?? null)),
            self::mappingTexte($restitution['bandes_appliquees'] ?? []),
            self::booleen($restitution['partiellement_rapprochee'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $marque
     */
    private static function brandingDepuis(array $marque): Branding
    {
        return Branding::creer(
            Couleur::depuisHex(self::texte($marque['couleur_hex'])),
            self::texteOuNull($marque['numero_rge']),
            self::texteOuNull($marque['telephone']),
            self::texteOuNull($marque['mentions_legales']),
            self::texteOuNull($marque['logo_url']),
            // Rétrocompat : les anciens instantanés n'ont pas de nom d'entreprise.
            self::texteOuNull($marque['nom'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function sousTableau(mixed $valeur): array
    {
        \assert(\is_array($valeur));

        // Réindexation en clés chaînes : le JSONB rend un tableau associatif, ce
        // qui rétablit le type attendu sans annotation inline (fragile au style).
        $tableau = [];
        foreach ($valeur as $cle => $sousValeur) {
            $tableau[(string) $cle] = $sousValeur;
        }

        return $tableau;
    }

    /**
     * Bandes DPE appliquées (#169), relues du JSONB : mapping classe → bande,
     * toutes valeurs textuelles. Absent des instantanés antérieurs → mapping vide.
     *
     * @return array<string, string>
     */
    private static function mappingTexte(mixed $valeur): array
    {
        \assert(\is_array($valeur));

        $mapping = [];
        foreach ($valeur as $cle => $sousValeur) {
            \assert(\is_string($sousValeur));
            $mapping[(string) $cle] = $sousValeur;
        }

        return $mapping;
    }

    /**
     * @return array{coef_dpe_avant: float|int, coef_dpe_apres: float|int, erreur_mediane: float|int}
     */
    private static function contexteDepuis(mixed $calcul): array
    {
        $calcul = self::sousTableau($calcul);
        \assert(is_numeric($calcul['coef_dpe_avant']));
        \assert(is_numeric($calcul['coef_dpe_apres']));
        \assert(is_numeric($calcul['erreur_mediane']));

        return [
            'coef_dpe_avant' => 0 + $calcul['coef_dpe_avant'],
            'coef_dpe_apres' => 0 + $calcul['coef_dpe_apres'],
            'erreur_mediane' => 0 + $calcul['erreur_mediane'],
        ];
    }

    private static function bouquetDepuis(mixed $bouquet): ?Bouquet
    {
        if (null === $bouquet) {
            return null;
        }

        $bouquet = self::sousTableau($bouquet);
        $postes = $bouquet['postes'];
        \assert(\is_array($postes));

        return Bouquet::creer(
            self::texte($bouquet['nom']),
            ...array_map(static fn (mixed $nom): Poste => Poste::parNom(self::texte($nom)), array_values($postes)),
        );
    }

    private static function texte(mixed $valeur): string
    {
        \assert(\is_string($valeur));

        return $valeur;
    }

    private static function texteOuNull(mixed $valeur): ?string
    {
        \assert(null === $valeur || \is_string($valeur));

        return $valeur;
    }

    private static function entier(mixed $valeur): int
    {
        \assert(\is_int($valeur));

        return $valeur;
    }

    private static function entierOuNull(mixed $valeur): ?int
    {
        \assert(null === $valeur || \is_int($valeur));

        return $valeur;
    }

    private static function booleen(mixed $valeur): bool
    {
        \assert(\is_bool($valeur));

        return $valeur;
    }
}
