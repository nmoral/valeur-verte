<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\EtudePubliee;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique de statut du modèle (#48) et carte de validation par département
 * (#224). Sans millésime publié (REG-2), il n'y a rien à montrer.
 */
final class PublicationController extends AbstractController
{
    #[Route('/valeur-verte-par-departement', name: 'publication', host: '%app.domaine%', methods: ['GET'])]
    public function __invoke(EtudePubliee $etudePubliee): Response
    {
        $etude = $etudePubliee->courante();
        if (null === $etude) {
            throw $this->createNotFoundException('Aucun millésime publié.');
        }

        $reponse = $this->render('publication/index.html.twig', ['etude' => $etude]);
        // Agréger 30 000 mailles à chaque visite d'une page publique n'aurait pas
        // de sens : le millésime ne change qu'à une publication manuelle. La page
        // ne dépend d'aucun état de session — on lève donc le « private » que le
        // noyau pose dès qu'une session existe, sans quoi le cache partagé serait
        // désactivé pour tout le monde.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setPublic();
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
