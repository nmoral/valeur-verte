<?php

declare(strict_types=1);

namespace App\AdminInterne\Rgpd;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Psr\Clock\ClockInterface;

/**
 * Suivi des demandes RGPD reçues par email (#309) : consignation de l'arrivée
 * d'une demande et de sa réponse. Le support est le **journal d'audit** existant
 * (pas d'entité ni d'écran dédié), append-only : deux événements — reçue, puis
 * traitée — suffisent à montrer, six mois plus tard, quand une demande est
 * arrivée et quand elle a reçu réponse dans le délai d'un mois.
 *
 * L'effacement n'entre pas dans ce périmètre : il a son propre parcours (#308).
 */
final readonly class SuiviDemandesRgpd
{
    public function __construct(
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Consigne une demande reçue : le droit invoqué, sa date d'arrivée et
     * l'échéance légale à un mois. L'événement est daté de l'instant de la
     * consignation, mais porte la date d'arrivée réelle (l'email a pu précéder).
     */
    public function consignerDemandeRecue(int $tenantId, DroitRgpd $droit, \DateTimeImmutable $recueLe, string $auteur): void
    {
        $echeance = $recueLe->add(new \DateInterval('P1M'));

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'rgpd.demande_recue',
            cible: 'compte#'.$tenantId,
            contexte: [
                'tenant_id' => $tenantId,
                'droit' => $droit->value,
                'recue_le' => $recueLe->format(\DATE_ATOM),
                'echeance_le' => $echeance->format(\DATE_ATOM),
            ],
            survenuLe: $this->clock->now(),
        ));
    }

    /**
     * Consigne la réponse apportée à une demande : la date de réponse est
     * l'instant présent — la preuve que le délai a été tenu.
     */
    public function consignerReponse(int $tenantId, DroitRgpd $droit, string $auteur): void
    {
        $reponseLe = $this->clock->now();

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'rgpd.demande_traitee',
            cible: 'compte#'.$tenantId,
            contexte: [
                'tenant_id' => $tenantId,
                'droit' => $droit->value,
                'reponse_le' => $reponseLe->format(\DATE_ATOM),
            ],
            survenuLe: $reponseLe,
        ));
    }
}
