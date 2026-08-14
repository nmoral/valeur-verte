<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Bouquets types du pro (#35), cloisonnés par tenant. Source de la sélection en
 * rendez-vous (#29). Tant que le pro n'a rien configuré, la liste par défaut —
 * les trois bouquets types — est servie (ONB-3, « un rapport avant configuration »),
 * puis ses propres bouquets prennent le relais.
 *
 * @extends ServiceEntityRepository<BouquetEnregistre>
 */
class BouquetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BouquetEnregistre::class);
    }

    /**
     * @return list<Bouquet>
     */
    public function disponibles(): array
    {
        $enregistres = $this->findBy([], ['id' => 'ASC']);
        if ([] === $enregistres) {
            return self::defauts();
        }

        return array_map(static fn (BouquetEnregistre $b): Bouquet => $b->versBouquet(), $enregistres);
    }

    public function parNom(string $nom): ?Bouquet
    {
        foreach ($this->disponibles() as $bouquet) {
            if ($bouquet->nom() === $nom) {
                return $bouquet;
            }
        }

        return null;
    }

    public function enregistrer(Bouquet $bouquet): void
    {
        $manager = $this->getEntityManager();
        $existant = $this->findOneBy(['nom' => $bouquet->nom()]);
        if (null === $existant) {
            $manager->persist(BouquetEnregistre::depuis($bouquet));
        } else {
            $existant->mettreAJour($bouquet);
        }
        $manager->flush();
    }

    public function supprimer(string $nom): void
    {
        $existant = $this->findOneBy(['nom' => $nom]);
        if (null === $existant) {
            return;
        }

        $manager = $this->getEntityManager();
        $manager->remove($existant);
        $manager->flush();
    }

    /**
     * Matérialise des bouquets pour un tenant donné, tenant attribué
     * **explicitement** : au provisionnement d'un compte, le contexte tenant
     * n'est pas encore posé (cf. EnregistreurFicheEntreprise).
     */
    public function provisionner(int $tenantId, Bouquet ...$bouquets): void
    {
        $manager = $this->getEntityManager();
        foreach ($bouquets as $bouquet) {
            $enregistre = BouquetEnregistre::depuis($bouquet);
            $enregistre->setTenantId($tenantId);
            $manager->persist($enregistre);
        }
        $manager->flush();
    }

    /**
     * @return list<Bouquet>
     */
    private static function defauts(): array
    {
        return Bouquet::typesParDefaut();
    }
}
