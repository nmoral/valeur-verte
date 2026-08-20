<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Politique de sécurité du contenu (CSP) de la page publique hébergée (#267, D9).
 *
 * La page vit **sur notre origine** et affiche du contenu configuré par le pro
 * (nom, couleur, logo) : ce qui s'y injecterait jouerait sur notre domaine, avec
 * nos cookies et notre réputation. La validation à la saisie (#267, {@see UrlLogo},
 * {@see \App\BackOffice\Branding\Couleur}) est la première barrière ; la CSP est la
 * seconde — elle borne ce que la page peut charger et exécuter même si une valeur
 * malveillante passait.
 *
 * Posée sur **toute réponse de la zone `widget.`** : le résultat d'estimation porte
 * le mur de contact, c'est le formulaire le plus intéressant à détourner, il serait
 * absurde de ne couvrir que la première page.
 *
 * Choix notables :
 * - `frame-ancestors 'none'` — D9 abandonne le widget en iframe, plus rien n'a de
 *   raison d'encadrer cette page (anti-clickjacking).
 * - `img-src 'self' https:` — le logo du pro pointe vers un hôte public arbitraire
 *   en https ({@see UrlLogo}) ; on ne peut pas le restreindre davantage tant qu'il
 *   n'est pas proxifié. `data:` reste fermé.
 * - `style-src 'self'` + `style-src-attr 'unsafe-inline'` — la couleur de marque
 *   voyage par un **attribut** `style` (variable CSS), qu'aucun nonce ni hash ne
 *   peut couvrir. On ouvre donc l'inline d'attribut seul : un `<style>` injecté
 *   reste interdit. Un navigateur sans `style-src-attr` retombe sur `style-src` et
 *   perd la couleur — dégradation cosmétique, jamais une page cassée.
 * - Turnstile (#43) a besoin de son script, de son iframe et de son origine : sans
 *   `frame-src`/`connect-src`, l'anti-abus casserait en silence.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class CspWidget
{
    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public function __invoke(ResponseEvent $event): void
    {
        if (!str_starts_with(strtolower($event->getRequest()->getHost()), 'widget.')) {
            return;
        }

        $event->getResponse()->headers->set('Content-Security-Policy', $this->politique());
    }

    private function politique(): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' ".self::TURNSTILE,
            "style-src 'self'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' https:",
            "connect-src 'self' ".self::TURNSTILE,
            'frame-src '.self::TURNSTILE,
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'none'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
