<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relaciona al titular de clientes persona física y conserva su contacto al convertirlos en empresa.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.');
        $this->addSql('ALTER TABLE clients ADD individual_holder_contact_id INT DEFAULT NULL');
        $this->addSql("UPDATE clients c INNER JOIN client_contacts cc ON cc.client_id=c.id AND cc.is_active=1 SET c.individual_holder_contact_id=cc.id WHERE c.client_type='INDIVIDUAL' AND cc.is_primary=1");
        $this->addSql("UPDATE clients c INNER JOIN client_contacts cc ON cc.client_id=c.id AND cc.is_active=1 SET c.individual_holder_contact_id=cc.id WHERE c.client_type='INDIVIDUAL' AND c.individual_holder_contact_id IS NULL AND cc.id=(SELECT MIN(cc2.id) FROM client_contacts cc2 WHERE cc2.client_id=c.id AND cc2.is_active=1)");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CLIENT_INDIVIDUAL_HOLDER ON clients (individual_holder_contact_id)');
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT FK_CLIENT_INDIVIDUAL_HOLDER FOREIGN KEY (individual_holder_contact_id) REFERENCES client_contacts (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients DROP FOREIGN KEY FK_CLIENT_INDIVIDUAL_HOLDER');
        $this->addSql('DROP INDEX UNIQ_CLIENT_INDIVIDUAL_HOLDER ON clients');
        $this->addSql('ALTER TABLE clients DROP individual_holder_contact_id');
    }
}
