<?php

declare(strict_types=1);

namespace App\Liste\ActionGroupee;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Résout une action de groupe par sa clé (déclarée via #[AsActionGroupee]).
 * Le LiveComponent ne transporte que des clés ; l'exécution vit côté serveur.
 */
final class RegistreActionsGroupees
{
    public function __construct(
        #[AutowireLocator('app.action_groupee', indexAttribute: 'cle')]
        private readonly ContainerInterface $actions,
    ) {
    }

    public function action(string $cle): ActionGroupee
    {
        if (!$this->actions->has($cle)) {
            throw new \InvalidArgumentException(\sprintf('Action de groupe inconnue : « %s ».', $cle));
        }

        $action = $this->actions->get($cle);
        \assert($action instanceof ActionGroupee);

        return $action;
    }
}
