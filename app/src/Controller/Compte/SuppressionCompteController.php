<?php

declare(strict_types=1);

namespace App\Controller\Compte;

use App\AdminInterne\Compte\AdministrationComptes;
use App\Compte\CompteRepository;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Résiliation en self-service (#308, RGPD, ONB-5) : depuis « Mon compte », un pro
 * programme la suppression de son compte sans contact humain. L'acte est protégé
 * par son mot de passe (pas par un email — une désinscription qui exige un email
 * détruit la confiance, #107). La demande n'efface rien tout de suite : elle ouvre
 * un délai de grâce (une suppression accidentelle ne se rattrape pas), au terme
 * duquel le finaliseur efface définitivement (app:compte:effacer-exigibles).
 *
 * Route épinglée à l'espace pro (`app.`) : l'admin interne n'y résilie pas — il
 * honore une demande d'effacement depuis /comptes. La demande elle-même, comme la
 * suspension (#47), est tracée au journal d'audit par AdministrationComptes.
 */
final class SuppressionCompteController extends AbstractController
{
    public function __construct(private readonly int $delaiDeGraceJours)
    {
    }

    #[Route('/mon-compte/suppression', name: 'compte_suppression', host: 'app.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        CompteRepository $comptes,
        AdministrationComptes $administration,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $tenantId = $user->getTenantId();
        \assert(null !== $tenantId);

        if ($request->isMethod('POST')) {
            $erreur = $this->traiterDemande($request, $administration, $hasher, $user, $tenantId);
            if (null === $erreur) {
                $this->addFlash('mon_compte', 'La suppression de votre compte est programmée. Vous pouvez encore l\'annuler pendant le délai de grâce.');

                return $this->redirectToRoute('compte_suppression');
            }

            return $this->rendre($comptes, $tenantId, $erreur, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->rendre($comptes, $tenantId);
    }

    private function traiterDemande(
        Request $request,
        AdministrationComptes $administration,
        UserPasswordHasherInterface $hasher,
        User $user,
        int $tenantId,
    ): ?string {
        if (!$this->isCsrfTokenValid('suppression_compte', (string) $request->request->get('_csrf_token'))) {
            return 'Session expirée, merci de réessayer.';
        }
        if (!$hasher->isPasswordValid($user, (string) $request->request->get('motdepasse', ''))) {
            return 'Mot de passe incorrect.';
        }

        $administration->demanderSuppression($tenantId, $user->getUserIdentifier());

        return null;
    }

    private function rendre(CompteRepository $comptes, int $tenantId, ?string $erreur = null, int $statut = Response::HTTP_OK): Response
    {
        $compte = $comptes->pourTenant($tenantId);
        $demandeLe = $compte?->suppressionDemandeeLe();

        return $this->render('compte/suppression.html.twig', [
            'en_attente' => null !== $demandeLe,
            'effacement_le' => $demandeLe?->add(new \DateInterval('P'.$this->delaiDeGraceJours.'D')),
            'delai_de_grace_jours' => $this->delaiDeGraceJours,
            'erreur' => $erreur,
        ], new Response('', $statut));
    }
}
