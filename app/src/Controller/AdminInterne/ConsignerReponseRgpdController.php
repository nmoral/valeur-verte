<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Rgpd\DroitRgpd;
use App\AdminInterne\Rgpd\SuiviDemandesRgpd;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consigne la réponse apportée à une demande RGPD (#309) : la date du jour, qui
 * prouve le respect du délai d'un mois. Écrit au journal — la seconde moitié de
 * la preuve « arrivée le / répondue le ».
 */
final class ConsignerReponseRgpdController extends AbstractController
{
    #[Route('/rgpd/reponse', name: 'admin_rgpd_reponse', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function __invoke(
        Request $request,
        UserRepository $utilisateurs,
        SuiviDemandesRgpd $suivi,
    ): Response {
        if (!$this->isCsrfTokenValid('rgpd_reponse', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('admin_rgpd', 'Session expirée, merci de réessayer.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $tenantId = $utilisateurs->parAdresse((string) $request->request->get('email', ''))?->getTenantId();
        $droit = DroitRgpd::tryFrom((string) $request->request->get('droit', ''));

        if (null === $tenantId || null === $droit) {
            $this->addFlash('admin_rgpd', 'Réponse incomplète : vérifiez l\'adresse et le droit invoqué.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $suivi->consignerReponse($tenantId, $droit, $this->getUser()?->getUserIdentifier() ?? 'inconnu');
        $this->addFlash('admin_rgpd', 'Réponse consignée au journal.');

        return $this->redirectToRoute('admin_rgpd');
    }
}
