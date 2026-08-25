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
 * Consigne l'arrivée d'une demande RGPD reçue par email (#309) : droit invoqué
 * et date d'arrivée, d'où l'échéance à un mois. Écrit au journal — la première
 * moitié de la preuve « arrivée le / répondue le ».
 */
final class ConsignerDemandeRgpdController extends AbstractController
{
    #[Route('/rgpd/demande', name: 'admin_rgpd_demande', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function __invoke(
        Request $request,
        UserRepository $utilisateurs,
        SuiviDemandesRgpd $suivi,
    ): Response {
        if (!$this->isCsrfTokenValid('rgpd_demande', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('admin_rgpd', 'Session expirée, merci de réessayer.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $tenantId = $utilisateurs->parAdresse((string) $request->request->get('email', ''))?->getTenantId();
        $droit = DroitRgpd::tryFrom((string) $request->request->get('droit', ''));
        $recueLe = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('recue_le', ''));

        if (null === $tenantId || null === $droit || false === $recueLe) {
            $this->addFlash('admin_rgpd', 'Demande incomplète : vérifiez l\'adresse, le droit et la date de réception.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $suivi->consignerDemandeRecue($tenantId, $droit, $recueLe, $this->getUser()?->getUserIdentifier() ?? 'inconnu');
        $this->addFlash('admin_rgpd', 'Demande consignée : échéance à un mois enregistrée au journal.');

        return $this->redirectToRoute('admin_rgpd');
    }
}
