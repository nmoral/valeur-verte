<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\AdminInterne\Millesime\DecisionMillesime;
use App\AdminInterne\Millesime\LectureMillesimes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publication manuelle des millésimes (REG-2, #130). Un millésime calculé n'est
 * jamais servi tant qu'il n'a pas été activé ici. Zone « admin. », ROLE_ADMIN,
 * chaque décision tracée au journal d'audit (#50).
 */
final class MillesimesController extends AbstractController
{
    #[Route('/millesimes', name: 'admin_millesimes', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function liste(LectureMillesimes $lecture): Response
    {
        return $this->render('admin/millesimes.html.twig', [
            'millesimes' => $lecture->tous(),
        ]);
    }

    #[Route('/millesimes/{millesime}/publier', name: 'admin_millesime_publier', host: 'admin.%app.domaine%', requirements: ['millesime' => '\d{4}'], methods: ['POST'])]
    public function publier(int $millesime, DecisionMillesime $decision, LectureMillesimes $lecture): Response
    {
        try {
            $publiees = $decision->publier($millesime, $this->auteur());
        } catch (\RuntimeException $erreur) {
            return $this->erreur($lecture, $erreur);
        }

        $this->addFlash('succes', \sprintf('Millésime %d publié — %d maille(s) servie(s).', $millesime, $publiees));

        return $this->redirectToRoute('admin_millesimes');
    }

    #[Route('/millesimes/{millesime}/abandonner', name: 'admin_millesime_abandonner', host: 'admin.%app.domaine%', requirements: ['millesime' => '\d{4}'], methods: ['POST'])]
    public function abandonner(int $millesime, DecisionMillesime $decision, LectureMillesimes $lecture): Response
    {
        try {
            $decision->abandonner($millesime, $this->auteur());
        } catch (\RuntimeException $erreur) {
            return $this->erreur($lecture, $erreur);
        }

        $this->addFlash('succes', \sprintf('Millésime %d abandonné — rien n\'a été publié.', $millesime));

        return $this->redirectToRoute('admin_millesimes');
    }

    #[Route('/millesimes/{millesime}/mailles/{codeInsee}', name: 'admin_millesime_maille', host: 'admin.%app.domaine%', requirements: ['millesime' => '\\d{4}', 'codeInsee' => '\\w{5}'], methods: ['GET'])]
    public function maille(
        int $millesime,
        string $codeInsee,
        LectureMillesimes $lecture,
        SeuilsSupervisionRepository $seuils,
    ): Response {
        $fiche = $lecture->fiche($codeInsee, $millesime, $seuils->courants());
        if (null === $fiche) {
            throw $this->createNotFoundException('Cette commune n\'a pas ete calculee sous ce millesime.');
        }

        return $this->render('admin/millesime_maille.html.twig', ['fiche' => $fiche]);
    }

    #[Route('/millesimes/{millesime}/bloquees', name: 'admin_millesime_bloquees', host: 'admin.%app.domaine%', requirements: ['millesime' => '\d{4}'], methods: ['GET'])]
    public function bloquees(int $millesime, LectureMillesimes $lecture): Response
    {
        return $this->render('admin/millesime_bloquees.html.twig', [
            'millesime' => $millesime,
            'bloquees' => $lecture->maillesBloquees($millesime),
        ]);
    }

    #[Route('/millesimes/{millesime}/bloquees/{codeInsee}/debloquer', name: 'admin_millesime_debloquer', host: 'admin.%app.domaine%', requirements: ['millesime' => '\d{4}', 'codeInsee' => '\w{5}'], methods: ['POST'])]
    public function debloquer(
        int $millesime,
        string $codeInsee,
        Request $request,
        DecisionMillesime $decision,
        LectureMillesimes $lecture,
    ): Response {
        try {
            $decision->debloquer($codeInsee, $millesime, $this->auteur());
        } catch (\RuntimeException $erreur) {
            return $this->render('admin/millesime_bloquees.html.twig', [
                'millesime' => $millesime,
                'bloquees' => $lecture->maillesBloquees($millesime),
                'erreur' => $erreur->getMessage(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash('succes', \sprintf('Maille %s débloquée sur le millésime %d.', $codeInsee, $millesime));

        return $this->redirectToRoute('admin_millesime_bloquees', ['millesime' => $millesime]);
    }

    private function erreur(LectureMillesimes $lecture, \RuntimeException $erreur): Response
    {
        return $this->render('admin/millesimes.html.twig', [
            'millesimes' => $lecture->tous(),
            'erreur' => $erreur->getMessage(),
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function auteur(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'inconnu';
    }
}
