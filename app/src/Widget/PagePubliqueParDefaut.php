<?php

declare(strict_types=1);

namespace App\Widget;

use App\Tenant\ConfigurationParDefaut;

/**
 * Page publique par défaut d'un compte (#38, épic #6, ONB-4) : à la création du
 * compte, son adresse publique est déjà ouverte, jeton compris.
 *
 * Rien à demander au pro pour l'ouvrir — le jeton est tiré, le slug est un
 * habillage qu'il changera s'il le souhaite — et rien à protéger : la page
 * publique ne consomme pas le quota d'essai (D9), seuls les audits le consomment.
 */
final readonly class PagePubliqueParDefaut implements ConfigurationParDefaut
{
    /**
     * Slug d'attente, purement décoratif : il n'entre pas dans la résolution de
     * la page et le pro le remplace par le nom qu'il veut voir dans son lien.
     */
    public const string SLUG_PAR_DEFAUT = 'estimation';

    public function __construct(
        private PagePubliqueRepository $pages,
        private GenerateurJetonPagePublique $jetons,
    ) {
    }

    public function provisionner(int $tenantId): void
    {
        $this->pages->enregistrer(
            PagePublique::creer($tenantId, $this->jetons->nouveau(), self::SLUG_PAR_DEFAUT),
        );
    }
}
