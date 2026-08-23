<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#308, RGPD) : programme l'effacement des comptes sélectionnés
 * pour honorer une demande d'effacement reçue par email. Même mécanisme et même
 * délai de grâce que le self-service — la suppression définitive est finalisée par
 * le cron, jamais ici. Chaque demande est tracée au journal d'audit par le service.
 */
#[AsActionGroupee('comptes.supprimer')]
final readonly class DemanderSuppressionComptes implements ActionGroupee
{
    public function __construct(
        private AdministrationComptes $administration,
        private Security $security,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $auteur = $this->security->getUser()?->getUserIdentifier() ?? 'inconnu';
        $nombre = 0;
        foreach ($identifiants as $identifiant) {
            if ($this->administration->demanderSuppression((int) $identifiant, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d compte(s) programmé(s) pour effacement.', $nombre);
    }
}
