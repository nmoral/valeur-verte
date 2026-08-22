<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Tenant\AccesTousTenants;
use App\Widget\PagePubliqueRepository;
use Psr\Clock\ClockInterface;

/**
 * Kill switch manuel des pages publiques (#268, épic #6).
 *
 * L'escalade automatique coupe au-delà du seuil critique ; il faut aussi pouvoir
 * couper **à la main** — abus signalé par un tiers, contenu litigieux, pro
 * injoignable — sans attendre qu'un seuil parle. Chaque décision est tracée au
 * journal d'audit (#50) : un kill switch anonyme n'est pas un kill switch, c'est
 * une panne.
 *
 * L'écriture traverse le cloisonnement (la page appartient à un pro, pas à l'admin)
 * : elle passe donc par {@see AccesTousTenants}, qui en laisse la trace (D3). Les
 * deux transitions sont idempotentes — recliquer ne réécrit ni ne re-trace.
 */
final readonly class AdministrationPagesPubliques
{
    public function __construct(
        private PagePubliqueRepository $pages,
        private JournalAudit $journal,
        private AccesTousTenants $acces,
        private ClockInterface $horloge,
    ) {
    }

    public function suspendre(string $token, string $auteur): bool
    {
        return $this->acces->executer('Suspension d\'une page publique (#268)', $auteur, function () use ($token, $auteur): bool {
            $page = $this->pages->trouverParJeton($token);
            if (null === $page || $page->estSuspendue()) {
                return false;
            }

            $page->suspendre($this->horloge->now());
            $this->pages->enregistrer($page);
            $this->tracer($auteur, 'page_publique.suspendue', $token, $page->tenantIdProprietaire());

            return true;
        });
    }

    public function reactiver(string $token, string $auteur): bool
    {
        return $this->acces->executer('Rétablissement d\'une page publique (#268)', $auteur, function () use ($token, $auteur): bool {
            $page = $this->pages->trouverParJeton($token);
            if (null === $page || !$page->estSuspendue()) {
                return false;
            }

            $page->reactiver();
            $this->pages->enregistrer($page);
            $this->tracer($auteur, 'page_publique.reactivee', $token, $page->tenantIdProprietaire());

            return true;
        });
    }

    private function tracer(string $auteur, string $action, string $token, int $tenantId): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: $action,
            cible: $token,
            contexte: ['tenant_id' => $tenantId],
            survenuLe: $this->horloge->now(),
        ));
    }
}
