<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Regle\RegleOnPage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Applique toutes les règles on-page (#345) à une page et rend la liste de leurs
 * {@see Constat}, un par règle, dans l'ordre d'injection. Les règles sont découvertes
 * par le tag `app.regle_seo_on_page` — en ajouter une = créer une classe
 * {@see RegleOnPage}, rien à câbler ici.
 */
final readonly class AuditeurOnPage
{
    /**
     * @param iterable<RegleOnPage> $regles
     */
    public function __construct(
        #[AutowireIterator('app.regle_seo_on_page')]
        private iterable $regles,
    ) {
    }

    /**
     * @return list<Constat>
     */
    public function auditer(PageAuditee $page): array
    {
        $constats = [];
        foreach ($this->regles as $regle) {
            $constats[] = $regle->evaluer($page);
        }

        return $constats;
    }
}
