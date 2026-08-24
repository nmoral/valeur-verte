<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unicité réelle de l'adresse email (#331) : `utilisateur.email` est ramenée en
 * minuscules, et une colonne `email_canonique` unique porte désormais l'identité
 * du compte (alias `+tag` retiré, googlemail ramené à gmail, points ignorés chez
 * Gmail seulement).
 *
 * L'expression de reprise ci-dessous **duplique** volontairement la règle portée
 * par `App\Security\AdresseEmail` : c'est un calcul unique, joué une fois sur
 * l'existant. L'objet-valeur reste l'autorité — aucune écriture applicative ne
 * passe par ce SQL.
 *
 * ⚠️ L'index unique **échoue si deux comptes existants partagent une identité**
 * (le cas est réel : des comptes de test créés avec des alias). Passer
 * `app:comptes:doublons-email` avant de déployer et trancher chaque collision.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unicité réelle de l\'adresse email : utilisateur.email en minuscules + email_canonique unique — #331';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD email_canonique VARCHAR(180) DEFAULT NULL');

        // La casse ne distingue plus deux comptes : l'adresse de connexion elle-même
        // est ramenée en minuscules (le domaine est insensible à la casse par le DNS,
        // et aucun fournisseur grand public ne distingue la casse de la partie locale).
        $this->addSql('UPDATE utilisateur SET email = lower(email)');

        // Partie locale : « pro+devis » → « pro ». Le « +? » non gourmand exige au
        // moins un caractère AVANT le plus, si bien qu'une partie locale qui n'est
        // QUE l'alias (« +tag@… ») reste intacte.
        // Domaine : googlemail → gmail, puis points retirés chez Gmail seulement.
        $this->addSql(<<<'SQL'
            UPDATE utilisateur SET email_canonique =
                CASE WHEN split_part(email, '@', 2) IN ('gmail.com', 'googlemail.com')
                     THEN replace(regexp_replace(split_part(email, '@', 1), '^(.+?)\+.*$', '\1'), '.', '')
                     ELSE regexp_replace(split_part(email, '@', 1), '^(.+?)\+.*$', '\1')
                END
                || '@' ||
                CASE WHEN split_part(email, '@', 2) = 'googlemail.com'
                     THEN 'gmail.com'
                     ELSE split_part(email, '@', 2)
                END
            SQL);

        $this->addSql('ALTER TABLE utilisateur ALTER COLUMN email_canonique SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_utilisateur_email_canonique ON utilisateur (email_canonique)');
    }

    public function down(Schema $schema): void
    {
        // La casse d'origine est perdue, et c'est sans conséquence : elle ne
        // distinguait rien.
        $this->addSql('DROP INDEX uniq_utilisateur_email_canonique');
        $this->addSql('ALTER TABLE utilisateur DROP email_canonique');
    }
}
