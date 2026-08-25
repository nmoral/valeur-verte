<?php

declare(strict_types=1);

namespace App\AdminInterne\Activation;

use App\BackOffice\Rapport\Source;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Métriques d'activation du parcours self-service (#111). Elles se lisent des
 * horodatages déjà en base (compte.cree_le, rapport.cree_le) — aucune
 * instrumentation nouvelle. Le « premier rapport » est un rapport produit par le
 * pro en mode audit ; un rapport widget (lead capté par la page publique) ne vaut
 * pas activation. Lecture seule en DBAL, hors filtre multi-tenant, comme le
 * compteur de rapports (#47).
 */
final readonly class CalculMetriquesActivation
{
    private const int FENETRE_JOURS = 30;

    public function __construct(
        private Connection $connexion,
        private ClockInterface $horloge,
    ) {
    }

    public function calculer(): MetriquesActivation
    {
        return new MetriquesActivation(
            $this->premierRapport(),
            $this->delaiMedianMinutes(),
            $this->troisRapportsAJTrente(),
        );
    }

    private function premierRapport(): Taux
    {
        $total = self::entier($this->connexion->fetchOne('SELECT count(*) FROM compte'));
        $actives = self::entier($this->connexion->fetchOne(
            'SELECT count(*) FROM compte c
             WHERE EXISTS (
                 SELECT 1 FROM rapport r
                 WHERE r.tenant_id = c.tenant_id AND r.source = :audit
             )',
            ['audit' => Source::Audit->value],
        ));

        return new Taux($actives, $total);
    }

    private function delaiMedianMinutes(): ?int
    {
        $mediane = $this->connexion->fetchOne(
            'SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY delai_minutes)
             FROM (
                 SELECT extract(epoch FROM (min(r.cree_le) - c.cree_le)) / 60 AS delai_minutes
                 FROM compte c
                 JOIN rapport r ON r.tenant_id = c.tenant_id AND r.source = :audit
                 GROUP BY c.tenant_id, c.cree_le
             ) premiers',
            ['audit' => Source::Audit->value],
        );

        if (!is_numeric($mediane)) {
            return null;
        }

        return (int) round((float) $mediane);
    }

    private function troisRapportsAJTrente(): Taux
    {
        $seuilMaturite = $this->horloge->now()
            ->sub(new \DateInterval('P'.self::FENETRE_JOURS.'D'))
            ->format('Y-m-d H:i:s');

        $murs = self::entier($this->connexion->fetchOne(
            'SELECT count(*) FROM compte WHERE cree_le <= :seuil',
            ['seuil' => $seuilMaturite],
        ));

        $qualifies = self::entier($this->connexion->fetchOne(
            'SELECT count(*) FROM (
                 SELECT c.tenant_id
                 FROM compte c
                 JOIN rapport r ON r.tenant_id = c.tenant_id AND r.source = :audit
                     AND r.cree_le <= c.cree_le + make_interval(days => :fenetre)
                 WHERE c.cree_le <= :seuil
                 GROUP BY c.tenant_id
                 HAVING count(*) >= 3
             ) qualifies',
            ['audit' => Source::Audit->value, 'fenetre' => self::FENETRE_JOURS, 'seuil' => $seuilMaturite],
        ));

        return new Taux($qualifies, $murs);
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
