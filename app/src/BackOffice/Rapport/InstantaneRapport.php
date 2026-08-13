<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
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
    private const VERSION = 1;

    private function __construct(
        private SaisieAudit $saisie,
        private ?Restitution $restitution,
        private ?ContexteCalcul $contexte,
        private Branding $branding,
        private ReferenceRapport $reference,
        private \DateTimeImmutable $genereLe,
    ) {
    }

    public static function creer(
        SaisieAudit $saisie,
        ?Restitution $restitution,
        ?ContexteCalcul $contexte,
        Branding $branding,
        ReferenceRapport $reference,
        \DateTimeImmutable $genereLe,
    ): self {
        return new self($saisie, $restitution, $contexte, $branding, $reference, $genereLe);
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
            ],
            'calcul' => $this->contexte?->toArray(),
            'marque' => [
                'couleur_hex' => $this->branding->couleurPrincipale()->hex(),
                'numero_rge' => $this->branding->numeroRge(),
                'telephone' => $this->branding->telephone(),
                'mentions_legales' => $this->branding->mentionsLegales(),
                'logo_url' => $this->branding->logoUrl(),
            ],
        ];
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
