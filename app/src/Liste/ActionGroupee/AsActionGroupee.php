<?php

declare(strict_types=1);

namespace App\Liste\ActionGroupee;

/**
 * Déclare un service comme action de groupe sous une clé stable, réutilisée par le
 * LiveComponent (liste blanche des actions autorisées) et par le registre.
 * L'autoconfiguration (Kernel) transforme l'attribut en tag « app.action_groupee ».
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsActionGroupee
{
    public function __construct(
        public string $cle,
    ) {
    }
}
