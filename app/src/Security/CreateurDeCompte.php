<?php

declare(strict_types=1);

namespace App\Security;

use App\BackOffice\Entreprise\EnregistreurFicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\Tenant\TenantRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création d'un compte pro en self-service (ONB-1) : compte actif immédiatement,
 * sans validation manuelle. Provisionne le tenant du pro (D3, #54) et, si un
 * SIRET est fourni, rattache la fiche entreprise pré-remplie (ONB-2, #106).
 */
final class CreateurDeCompte
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TenantRepository $tenants,
        private readonly EnregistreurFicheEntreprise $fichesEntreprise,
    ) {
    }

    /**
     * @throws EmailDejaUtilise
     */
    public function creer(string $email, string $motDePasse, ?Siret $siret = null): User
    {
        if (null !== $this->utilisateurs->findOneBy(['email' => $email])) {
            throw new EmailDejaUtilise($email);
        }

        $tenantId = $this->tenants->nouveau()->id();
        \assert(null !== $tenantId);

        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $user->definirMotDePasse($this->hasher->hashPassword($user, $motDePasse));
        $this->utilisateurs->enregistrer($user);

        if (null !== $siret) {
            $this->fichesEntreprise->rattacher($tenantId, $siret);
        }

        return $user;
    }
}
