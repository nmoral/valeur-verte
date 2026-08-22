<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Ce qui a fait sortir une page publique de son régime normal (#268, épic #6).
 *
 * Trois lectures d'un même pic : le volume d'estimations (page moissonnée), le
 * volume de leads (formulaire mitraillé), le volume de rejets des contrôles
 * préventifs (#43 — quelqu'un force la porte). Le motif nomme l'alerte : c'est ce
 * que lisent l'admin interne et le pro concerné.
 */
enum MotifPicWidget: string
{
    case Estimations = 'estimations';
    case Leads = 'leads';
    case Rejets = 'rejets';

    /**
     * Les types d'événements (#90) qui alimentent ce motif.
     *
     * @return non-empty-list<TypeEvenementWidget>
     */
    public function typesMesures(): array
    {
        return match ($this) {
            self::Estimations => [TypeEvenementWidget::EstimationAffichee],
            self::Leads => [TypeEvenementWidget::LeadSoumis],
            self::Rejets => [
                TypeEvenementWidget::PlafondAtteint,
                TypeEvenementWidget::PiegeDeclenche,
                TypeEvenementWidget::TurnstileEchoue,
            ],
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Estimations => 'estimations affichées',
            self::Leads => 'leads soumis',
            self::Rejets => 'requêtes rejetées par les contrôles anti-abus',
        };
    }
}
