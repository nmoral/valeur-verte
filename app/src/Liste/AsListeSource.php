<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Déclare un service comme source de liste, sous une clé stable réutilisée par le
 * LiveComponent (LiveProp non sérialisable → on passe la clé, pas le service).
 * L'autoconfiguration (Kernel) transforme l'attribut en tag « app.source_liste ».
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsListeSource
{
    public function __construct(
        public string $cle,
    ) {
    }
}
