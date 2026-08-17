<?php

declare(strict_types=1);

namespace App\Liste;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Résout une source de liste par sa clé (déclarée via #[AsListeSource]). Le
 * LiveComponent ne transporte que la clé dans ses LiveProps ; le registre fournit
 * l'implémentation concrète côté serveur à chaque rendu.
 */
final class RegistreSources
{
    public function __construct(
        #[AutowireLocator('app.source_liste', indexAttribute: 'cle')]
        private readonly ContainerInterface $sources,
    ) {
    }

    public function source(string $cle): SourceListe
    {
        if (!$this->sources->has($cle)) {
            throw new \InvalidArgumentException(\sprintf('Source de liste inconnue : « %s ».', $cle));
        }

        $source = $this->sources->get($cle);
        \assert($source instanceof SourceListe);

        return $source;
    }
}
