<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Bouquet\Bouquet;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BouquetsController extends AbstractController
{
    #[Route('/bouquets', name: 'back_office_bouquets', methods: ['GET'])]
    public function __invoke(): Response
    {
        $bouquets = [Bouquet::renovationAmpleurStandard()];

        return $this->render('back_office/bouquets.html.twig', [
            'page_title' => 'Bouquets types',
            'nav_active' => 'bouquets',
            'bouquets' => $bouquets,
        ]);
    }
}
