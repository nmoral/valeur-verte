<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\Widget\AdresseSuggeree;
use App\Widget\PagePubliqueRepository;
use App\Widget\PlafondWidget;
use App\Widget\RechercheAdresse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autocomplétion d'adresse de la page publique (#287, épic #6).
 *
 * Le formulaire ne peut chiffrer que s'il connaît la **commune** du bien : le
 * moteur travaille par maille communale (§10.6). Cet endpoint relaie la saisie
 * vers la BAN ({@see \App\Widget\RechercheAdresseViaBan}) et rend des adresses
 * portant leur code INSEE, que le navigateur reporte dans le champ caché.
 *
 * Le relais est **serveur**, pas navigateur : la CSP de la zone garde
 * `connect-src 'self'` ({@see \App\Widget\CspWidget}), l'IP du visiteur et
 * l'adresse qu'il tape ne partent pas chez un tiers, et l'appel devient
 * plafonnable comme les autres endpoints publics (#43).
 *
 * `priority: 1` : sans elle, `/{token}/{slug}` — dont le slug est libre —
 * happerait `/{token}/adresses` et servirait la page habillée à la place du JSON.
 */
final class AutocompletionAdresseController extends AbstractController
{
    /**
     * En deçà, la saisie ne discrimine rien : on ne relaie pas chaque frappe.
     */
    private const int LONGUEUR_MINIMALE = 3;

    #[Route(
        '/{token}/adresses',
        name: 'widget_adresses',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['GET'],
        priority: 1,
    )]
    public function __invoke(
        string $token,
        Request $request,
        PagePubliqueRepository $pages,
        PlafondWidget $plafond,
        RechercheAdresse $recherche,
    ): JsonResponse {
        $plafond->verifierAdresse($request->getClientIp());

        if (null === $pages->trouverActiveParJeton($token)) {
            throw $this->createNotFoundException('Page publique inconnue ou désactivée.');
        }

        $requete = trim($request->query->getString('q'));
        if (mb_strlen($requete) < self::LONGUEUR_MINIMALE) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(
            static fn (AdresseSuggeree $adresse): array => [
                'libelle' => $adresse->libelle(),
                'code_insee' => $adresse->codeInsee(),
            ],
            $recherche->suggerer($requete),
        ));
    }
}
