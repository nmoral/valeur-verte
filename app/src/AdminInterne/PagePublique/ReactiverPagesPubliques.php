<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#268) : rétablit les pages publiques suspendues. Ne rallume pas
 * une page que le pro a lui-même retirée — les deux interrupteurs sont distincts.
 */
#[AsActionGroupee('pages_publiques.reactiver')]
final readonly class ReactiverPagesPubliques implements ActionGroupee
{
    public function __construct(
        private AdministrationPagesPubliques $administration,
        private Security $security,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $auteur = $this->security->getUser()?->getUserIdentifier() ?? 'inconnu';
        $nombre = 0;
        foreach ($identifiants as $identifiant) {
            if ($this->administration->reactiver($identifiant, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d page(s) rétablie(s).', $nombre);
    }
}
