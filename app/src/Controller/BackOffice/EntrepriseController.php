<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\MiseAJourFicheEntreprise;
use App\BackOffice\Entreprise\SiretNonVerifie;
use App\Compte\SuiviCompte;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Entreprise » de l'espace pro (#311) : le pro consulte sa fiche et la
 * corrige lui-même. Un changement de SIRET passe par la re-vérification (#106),
 * le domaine s'en charge — ici on ne fait que traduire un refus en message.
 */
final class EntrepriseController extends AbstractController
{
    #[Route('/entreprise', name: 'back_office_entreprise', host: 'app.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        EntrepriseRepository $entreprises,
        MiseAJourFicheEntreprise $miseAJour,
        SuiviCompte $suivi,
    ): Response {
        if ($request->isMethod('POST')) {
            if ($suivi->estSuspendu()) {
                return $this->redirectToRoute('espace_pro');
            }

            try {
                $miseAJour->enregistrer(
                    $request->request->getString('siret'),
                    $request->request->getString('raison_sociale'),
                    $request->request->getString('adresse'),
                    $this->getUser()?->getUserIdentifier() ?? 'inconnu',
                );
            } catch (\InvalidArgumentException|SiretNonVerifie $erreur) {
                return $this->page($entreprises, [
                    'erreur' => $erreur->getMessage(),
                    'saisie' => [
                        'siret' => $request->request->getString('siret'),
                        'raison_sociale' => $request->request->getString('raison_sociale'),
                        'adresse' => $request->request->getString('adresse'),
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('entreprise', 'Votre fiche entreprise a été mise à jour.');

            return $this->redirectToRoute('back_office_entreprise');
        }

        return $this->page($entreprises);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(EntrepriseRepository $entreprises, array $extra = [], int $statut = Response::HTTP_OK): Response
    {
        return $this->render('back_office/entreprise.html.twig', [
            'page_title' => 'Votre entreprise',
            'nav_active' => 'entreprise',
            'entreprise' => $entreprises->courante(),
            ...$extra,
        ], new Response('', $statut));
    }
}
