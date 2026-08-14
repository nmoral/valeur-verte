<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\GrilleCout\Poste;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration des bouquets types (#35, §9.2) : le pro compose des combinaisons
 * nommées de postes, réutilisées en rendez-vous (#29). Tant qu'il n'a rien
 * configuré, les trois bouquets types par défaut sont proposés (ONB-3).
 */
final class BouquetsController extends AbstractController
{
    #[Route('/bouquets', name: 'back_office_bouquets', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, BouquetRepository $bouquets): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $bouquet = $this->composer($request);
            } catch (\InvalidArgumentException $erreur) {
                return $this->rendre($bouquets, $erreur->getMessage());
            }

            $bouquets->enregistrer($bouquet);

            return $this->redirectToRoute('back_office_bouquets');
        }

        return $this->rendre($bouquets);
    }

    #[Route('/bouquets/supprimer', name: 'back_office_bouquets_supprimer', methods: ['POST'])]
    public function supprimer(Request $request, BouquetRepository $bouquets): Response
    {
        $bouquets->supprimer($request->request->getString('nom'));

        return $this->redirectToRoute('back_office_bouquets');
    }

    private function composer(Request $request): Bouquet
    {
        $postes = array_map(
            static fn (mixed $nom): Poste => Poste::parNom(\is_string($nom) ? $nom : ''),
            $request->request->all('postes'),
        );

        return Bouquet::creer($request->request->getString('nom'), ...$postes);
    }

    private function rendre(BouquetRepository $bouquets, ?string $erreur = null): Response
    {
        $reponse = new Response('', null === $erreur ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->render('back_office/bouquets.html.twig', [
            'page_title' => 'Bouquets types',
            'nav_active' => 'bouquets',
            'bouquets' => $bouquets->disponibles(),
            'postes' => Poste::cases(),
            'erreur' => $erreur,
        ], $reponse);
    }
}
