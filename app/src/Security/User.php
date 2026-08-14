<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte utilisateur du monolithe. Le rôle porte la zone accessible
 * (ROLE_PRO → espace pro, ROLE_ADMIN → admin interne). Le rattachement au
 * pro (tenant_id) viendra avec le multi-tenant (#54).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'utilisateur')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    #[ORM\Column]
    private string $password = '';

    // Le compte porte son tenant (D3) mais n'est PAS filtré : le login doit
    // retrouver les comptes sans cloisonnement. Null pour l'admin interne.
    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    /**
     * @param list<string> $roles
     */
    public function __construct(string $email, array $roles = [])
    {
        $this->email = $email;
        $this->roles = $roles;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function rattacherAuTenant(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        // L'email est requis et unique : il n'est jamais vide (invariant de création).
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    // Le hash participe à l'invalidation des sessions (#199) : le firewall compare
    // le mot de passe du token à celui rechargé et déconnecte s'ils diffèrent. Ne
    // pas retirer le mot de passe de la sérialisation du token sans préserver ce
    // signal (cf. InvalidationSessionTest).
    public function getPassword(): string
    {
        return $this->password;
    }

    public function definirMotDePasse(string $hash): void
    {
        $this->password = $hash;
    }
}
