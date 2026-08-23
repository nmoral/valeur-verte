<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Psr\Clock\ClockInterface;

/**
 * Mise à jour de la fiche entreprise par le pro lui-même (#311).
 *
 * Deux régimes, séparés par ce que la donnée engage :
 *
 * - **raison sociale et adresse** : librement corrigeables. Le pro les connaît
 *   mieux que l'annuaire, et une orthographe fausse n'engage personne d'autre.
 * - **SIRET** : il identifie le redevable — il ne change qu'après re-vérification
 *   auprès de l'API entreprise (#106), et c'est alors l'identité vérifiée qui fait
 *   foi, pas la saisie. Un SIRET **inchangé** n'est jamais re-vérifié : une source
 *   muette bloquerait la correction d'une faute de frappe.
 *
 * Chaque geste est inscrit au journal d'audit (#50) : la fiche alimentera la
 * facturation (#107, #60), ses changements doivent être retraçables.
 */
final readonly class MiseAJourFicheEntreprise
{
    public function __construct(
        private EntrepriseRepository $entreprises,
        private FicheEntrepriseParSiret $fiches,
        private JournalAudit $journal,
        private ClockInterface $horloge,
    ) {
    }

    /**
     * @throws SiretInvalide             si la saisie ne forme pas un SIRET
     * @throws SiretNonVerifie           si le SIRET **nouveau** est introuvable
     * @throws \InvalidArgumentException si la raison sociale ou l'adresse est refusée
     */
    public function enregistrer(
        string $siretSaisi,
        string $raisonSociale,
        string $adresse,
        string $acteur,
    ): Entreprise {
        $siret = Siret::depuis($siretSaisi);
        $courante = $this->entreprises->courante();

        if (null !== $courante && $courante->siret() === $siret->valeur()) {
            $courante->corriger($raisonSociale, $adresse);
            $this->entreprises->enregistrer($courante);
            $this->tracer($acteur, 'entreprise.corrigee', $courante, []);

            return $courante;
        }

        $fiche = $this->fiches->parSiret($siret);
        if (null === $fiche) {
            throw SiretNonVerifie::pour($siret);
        }

        $siretPrecedent = $courante?->siret();
        $entreprise = $courante ?? Entreprise::depuisFiche($fiche);
        $entreprise->remplacerPar($fiche);
        $this->entreprises->enregistrer($entreprise);
        $this->tracer($acteur, 'entreprise.siret_change', $entreprise, ['siret_precedent' => $siretPrecedent]);

        return $entreprise;
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function tracer(string $acteur, string $action, Entreprise $entreprise, array $contexte): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: $acteur,
            action: $action,
            cible: 'entreprise#'.($entreprise->getTenantId() ?? 0),
            contexte: [
                ...$contexte,
                'siret' => $entreprise->siret(),
                'raison_sociale' => $entreprise->raisonSociale(),
                'adresse' => $entreprise->adresse(),
            ],
            survenuLe: $this->horloge->now(),
        ));
    }
}
