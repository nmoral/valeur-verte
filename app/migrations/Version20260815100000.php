<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Barème d\'aides national versionné (valide_du / valide_au) — #26';
    }

    public function up(Schema $schema): void
    {
        // Barème national : pas de tenant_id, il est maintenu par nous et non par le pro.
        $this->addSql(<<<'SQL'
            CREATE TABLE bareme_aides (
                id SERIAL NOT NULL,
                valide_du TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                valide_au TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                taux_pour_cent JSONB NOT NULL,
                plafonds JSONB NOT NULL,
                plafond_eco_ptz INT NOT NULL,
                taux_tva_pour_mille INT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_bareme_aides_valide_du ON bareme_aides (valide_du)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bareme_aides');
    }
}
