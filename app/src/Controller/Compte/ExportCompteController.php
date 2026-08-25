<?php

declare(strict_types=1);

namespace App\Controller\Compte;

use App\Compte\Export\GenererExportCompte;
use App\Security\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export self-service des données d'un compte (#309, RGPD — droit à la
 * portabilité). Depuis « Mon compte », le pro télécharge l'intégralité de ses
 * données dans un JSON lisible et réutilisable, sans intermédiaire : la
 * portabilité s'exerce seul. L'acte est protégé par le mot de passe (comme la
 * suppression #308 — jamais par un email) et tracé au journal (rgpd.export_genere).
 *
 * Route épinglée à l'espace pro (`app.`) : l'admin interne exporte, lui, depuis
 * l'écran RGPD dédié, pour une demande arrivée par email.
 */
final class ExportCompteController extends AbstractController
{
    #[Route('/mon-compte/export', name: 'compte_export', host: 'app.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        GenererExportCompte $generer,
        UserPasswordHasherInterface $hasher,
        ClockInterface $clock,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $tenantId = $user->getTenantId();
        \assert(null !== $tenantId);

        if (!$request->isMethod('POST')) {
            return $this->render('compte/export.html.twig', ['erreur' => null]);
        }

        $erreur = $this->valider($request, $hasher, $user);
        if (null !== $erreur) {
            return $this->render('compte/export.html.twig', ['erreur' => $erreur], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $document = $generer->pour($tenantId, $user->getUserIdentifier());

        return $this->telechargement($document, $clock);
    }

    private function valider(Request $request, UserPasswordHasherInterface $hasher, User $user): ?string
    {
        if (!$this->isCsrfTokenValid('export_compte', (string) $request->request->get('_csrf_token'))) {
            return 'Session expirée, merci de réessayer.';
        }
        if (!$hasher->isPasswordValid($user, (string) $request->request->get('motdepasse', ''))) {
            return 'Mot de passe incorrect.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function telechargement(array $document, ClockInterface $clock): Response
    {
        $json = json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        \assert(false !== $json);

        $nomFichier = 'valeur-verte-export-'.$clock->now()->format('Y-m-d').'.json';

        $reponse = new Response($json);
        $reponse->headers->set('Content-Type', 'application/json; charset=utf-8');
        $reponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nomFichier),
        );

        return $reponse;
    }
}
