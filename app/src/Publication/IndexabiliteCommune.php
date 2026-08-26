<?php

declare(strict_types=1);

namespace App\Publication;

use App\BackOffice\Restitution\PorteeEstimation;

/**
 * Décision d'indexation SEO d'une page commune (#361, SEO Vague 2 #338). Sortie
 * consommée par le contrôleur (#362) pour piloter la meta ``robots`` de
 * ``_seo.html.twig`` : ``index,follow`` si la commune porte un contenu propre,
 * ``noindex,follow`` sinon — le repli reste crawlable pour laisser passer le
 * maillage montant (#364).
 *
 * Une commune n'est indexable que si son estimation est **réellement locale** et
 * non diluée dans le département. Tout repli EPCI/départemental, ou une maille
 * locale trop rapprochée du département par le pooling, produit un contenu quasi
 * identique aux communes voisines : l'indexer en masse (~34 700 communes) exposerait
 * à la pénalité *thin content*.
 *
 * La règle réutilise deux seuils moteur sans les redupliquer : le ``n ≥ 30``
 * (``min_local_sample``, §7) est déjà encodé dans la portée {@see PorteeEstimation::Local},
 * et le rapprochement de pooling suit le seuil ``SEUIL_RAPPROCHEMENT = 0.5`` de
 * {@see \App\BackOffice\Estimation\CoefficientMaille} (#261).
 */
enum IndexabiliteCommune: string
{
    case Indexable = 'index,follow';
    case Repli = 'noindex,follow';

    /**
     * Poids de pooling minimal pour qu'une maille locale porte encore un contenu
     * propre. Aligné sur ``SEUIL_RAPPROCHEMENT`` de {@see \App\BackOffice\Estimation\CoefficientMaille} (#261) :
     * en deçà, la maille est jugée « partiellement rapprochée » du département.
     */
    private const float POIDS_POOLING_MIN = 0.5;

    /**
     * Un ``poidsPooling`` absent signifie « aucun pooling » : maille pleinement
     * locale, donc indexable. Sous le seuil, la maille est trop rapprochée du
     * département pour porter un contenu propre → repli.
     */
    public static function depuis(PorteeEstimation $portee, ?float $poidsPooling): self
    {
        if (PorteeEstimation::Local !== $portee) {
            return self::Repli;
        }

        if (null !== $poidsPooling && $poidsPooling < self::POIDS_POOLING_MIN) {
            return self::Repli;
        }

        return self::Indexable;
    }

    /**
     * Valeur prête à poser dans ``<meta name="robots" content="…">``.
     */
    public function directiveRobots(): string
    {
        return $this->value;
    }

    public function estIndexable(): bool
    {
        return self::Indexable === $this;
    }
}
