<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde des endpoints coûteux de la page publique (#43 + #268, épic #6).
 *
 * Rassemble les quatre contrôles préventifs (#43) dans l'ordre du moins cher au
 * plus cher — plafond, honeypot, jeton de chargement, puis seulement l'appel réseau
 * à Turnstile — et **journalise chaque rejet** comme un événement de la page (#90).
 * Sans cette trace, un matraquage intégralement bloqué resterait invisible : les
 * contrôles préventifs feraient leur travail sans que personne n'apprenne jamais
 * que la page est attaquée. C'est cette trace que mesure l'anti-abus réactif (#268).
 *
 * Le rejet reste un rejet : l'exception d'origine est relancée telle quelle, le
 * visiteur voit le même 429 ou 422 qu'avant.
 */
final readonly class GardeAntiAbusWidget
{
    public function __construct(
        private PlafondWidget $plafond,
        private PiegeAntiBotWidget $piege,
        private JetonChargementWidget $jetons,
        private VerificateurTurnstile $turnstile,
        private PagePubliqueRepository $pages,
        private JournalParcoursWidget $journal,
        private SessionParcoursWidget $sessions,
    ) {
    }

    /**
     * @throws HttpException au premier contrôle qui refuse la requête
     */
    public function verifier(Request $request, string $token): void
    {
        $this->controler($request, $token, TypeEvenementWidget::PlafondAtteint, function () use ($request, $token): void {
            $this->plafond->verifier($request->getClientIp(), $token);
        });

        $this->controler($request, $token, TypeEvenementWidget::PiegeDeclenche, function () use ($request): void {
            $this->piege->verifier($request);
        });

        $this->controler($request, $token, TypeEvenementWidget::JetonRefuse, function () use ($request): void {
            if (!$this->jetons->estRecevable($request->request->getString('jeton_chargement'))) {
                throw new UnprocessableEntityHttpException();
            }
        });

        $this->controler($request, $token, TypeEvenementWidget::TurnstileEchoue, function () use ($request): void {
            $this->turnstile->verifier($request->request->getString('cf-turnstile-response'), $request->getClientIp());
        });
    }

    /**
     * @param callable(): void $controle
     *
     * @throws HttpException si le contrôle refuse la requête
     */
    private function controler(Request $request, string $token, TypeEvenementWidget $rejet, callable $controle): void
    {
        try {
            $controle();
        } catch (HttpException $refus) {
            $this->journaliser($request, $token, $rejet);

            throw $refus;
        }
    }

    /**
     * La page n'est résolue qu'ici — donc jamais sur le chemin nominal, et jamais
     * avant le plafond : un flot de requêtes refusées ne doit pas se payer en
     * lectures de base. Une page inconnue ou déjà coupée n'a rien à journaliser.
     */
    private function journaliser(Request $request, string $token, TypeEvenementWidget $rejet): void
    {
        $page = $this->pages->trouverActiveParJeton($token);
        if (null === $page) {
            return;
        }

        $this->journal->enregistrer(
            $page,
            $this->sessions->reprendreOuDemarrer($request->request->getString('session_parcours')),
            $rejet,
        );
    }
}
