<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Branding\Couleur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BrandingController extends AbstractController
{
    #[Route('/marque', name: 'back_office_marque', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, BrandingRepository $brandings): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $branding = Branding::creer(
                    Couleur::depuisHex($request->request->getString('couleur')),
                    $request->request->getString('numero_rge'),
                    $request->request->getString('telephone'),
                    $request->request->getString('mentions_legales'),
                    $request->request->getString('logo_url'),
                    $request->request->getString('nom'),
                );
            } catch (\InvalidArgumentException $erreur) {
                return $this->render('back_office/marque.html.twig', [
                    'page_title' => 'Votre marque',
                    'nav_active' => 'branding',
                    'branding' => $brandings->courant(),
                    'erreur' => $erreur->getMessage(),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $brandings->enregistrer($branding);

            return $this->redirectToRoute('back_office_marque');
        }

        return $this->render('back_office/marque.html.twig', [
            'page_title' => 'Votre marque',
            'nav_active' => 'branding',
            'branding' => $brandings->courant(),
        ]);
    }
}
