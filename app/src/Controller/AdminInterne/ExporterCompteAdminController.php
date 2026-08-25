<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\Compte\Export\GenererExportCompte;
use App\Compte\Export\ReponseExportJson;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export d'un compte côté admin (#309) : pour une demande d'accès ou de
 * portabilité reçue par email, l'admin télécharge le JSON du compte identifié
 * par l'adresse du pro. L'acte est tracé (rgpd.export_genere) — ce qui vaut
 * aussi non-silence de l'accès inter-tenant (D3).
 */
final class ExporterCompteAdminController extends AbstractController
{
    #[Route('/rgpd/export', name: 'admin_rgpd_export', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function __invoke(
        Request $request,
        UserRepository $utilisateurs,
        GenererExportCompte $generer,
        ClockInterface $clock,
    ): Response {
        if (!$this->isCsrfTokenValid('rgpd_export', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('admin_rgpd', 'Session expirée, merci de réessayer.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $tenantId = $utilisateurs->parAdresse((string) $request->request->get('email', ''))?->getTenantId();
        if (null === $tenantId) {
            $this->addFlash('admin_rgpd', 'Aucun compte pro ne correspond à cette adresse.');

            return $this->redirectToRoute('admin_rgpd');
        }

        $document = $generer->pour($tenantId, $this->getUser()?->getUserIdentifier() ?? 'inconnu');

        return ReponseExportJson::pour($document, $clock->now());
    }
}
