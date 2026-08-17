<?php

declare(strict_types=1);

namespace App;

use App\Liste\ActionGroupee\AsActionGroupee;
use App\Liste\AsListeSource;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // #[AsListeSource('cle')] → tag « app.source_liste » indexé par clé, consommé
        // par RegistreSources pour résoudre une source de liste depuis le LiveComponent.
        $container->registerAttributeForAutoconfiguration(
            AsListeSource::class,
            static function (ChildDefinition $definition, AsListeSource $attribut): void {
                $definition->addTag('app.source_liste', ['cle' => $attribut->cle]);
            },
        );

        // #[AsActionGroupee('cle')] → tag « app.action_groupee » indexé par clé.
        $container->registerAttributeForAutoconfiguration(
            AsActionGroupee::class,
            static function (ChildDefinition $definition, AsActionGroupee $attribut): void {
                $definition->addTag('app.action_groupee', ['cle' => $attribut->cle]);
            },
        );
    }
}
