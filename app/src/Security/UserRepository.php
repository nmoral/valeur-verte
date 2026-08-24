<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Retrouve un compte par son **identité** (#331), pas par la chaîne saisie :
     * casse, alias « +tag », points Gmail et googlemail désignent le même compte.
     * Une saisie qui n'est pas une adresse ne trouve rien — ce n'est pas une
     * erreur, seulement une tentative qui échoue.
     */
    public function parAdresse(string $saisie): ?User
    {
        if (!AdresseEmail::estValide($saisie)) {
            return null;
        }

        return $this->findOneBy(['emailCanonique' => AdresseEmail::depuis($saisie)->canonique()]);
    }

    /**
     * Point d'entrée du firewall (`app_user_provider`) : Symfony s'en sert dès que
     * le repository implémente {@see UserLoaderInterface}, à la place de la
     * recherche sur une propriété. La connexion passe donc par la même identité
     * que l'inscription — sans quoi un pro pourrait s'inscrire avec une écriture
     * et se voir refuser la porte avec une autre.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->parAdresse($identifier);
    }

    public function enregistrer(User $user): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($user);
        $manager->flush();
    }

    /**
     * Supprime les utilisateurs rattachés à un tenant (#308, effacement de
     * compte) : un compte pourra porter plusieurs licences, toutes partent avec
     * lui. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }
}
