<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform; use Doctrine\DBAL\Schema\Schema; use Doctrine\Migrations\AbstractMigration;
final class Version20260816020000 extends AbstractMigration {
 public function getDescription():string{return 'Normaliza solicitudes públicas en partidas y vincula contacto, sucursal y domicilio de entrega.';}
 public function up(Schema $schema):void{
  $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,'Solo MySQL o MariaDB.');
  $this->addSql('ALTER TABLE quote_request ADD client_contact_id INT DEFAULT NULL, ADD client_branch_id INT DEFAULT NULL, ADD delivery_address_id INT DEFAULT NULL, ADD customer_snapshot JSON DEFAULT NULL, ADD delivery_address_snapshot JSON DEFAULT NULL, ADD INDEX IDX_QR_CONTACT (client_contact_id), ADD INDEX IDX_QR_BRANCH (client_branch_id), ADD INDEX IDX_QR_DELIVERY_ADDRESS (delivery_address_id)');
  $this->addSql('ALTER TABLE quote_request ADD CONSTRAINT FK_QR_CONTACT FOREIGN KEY (client_contact_id) REFERENCES client_contacts (id) ON DELETE SET NULL, ADD CONSTRAINT FK_QR_BRANCH FOREIGN KEY (client_branch_id) REFERENCES client_branches (id) ON DELETE SET NULL, ADD CONSTRAINT FK_QR_DELIVERY_ADDRESS FOREIGN KEY (delivery_address_id) REFERENCES client_addresses (id) ON DELETE SET NULL');
  $this->addSql('CREATE TABLE quote_request_items (id INT AUTO_INCREMENT NOT NULL, quote_request_id INT NOT NULL, category_id INT NOT NULL, product_id INT NOT NULL, measurement_unit_id INT DEFAULT NULL, quantity INT NOT NULL, width NUMERIC(10,2) DEFAULT NULL, height NUMERIC(10,2) DEFAULT NULL, material VARCHAR(150) DEFAULT NULL, print_sides VARCHAR(30) DEFAULT NULL, finishes JSON DEFAULT NULL, characteristics JSON DEFAULT NULL, attachment_path VARCHAR(255) DEFAULT NULL, attachment_original_name VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_QRI_REQUEST (quote_request_id), INDEX IDX_QRI_CATEGORY (category_id), INDEX IDX_QRI_PRODUCT (product_id), INDEX IDX_QRI_UNIT (measurement_unit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
  $this->addSql('ALTER TABLE quote_request_items ADD CONSTRAINT FK_QRI_REQUEST FOREIGN KEY (quote_request_id) REFERENCES quote_request (id) ON DELETE CASCADE, ADD CONSTRAINT FK_QRI_CATEGORY FOREIGN KEY (category_id) REFERENCES product_categories (id), ADD CONSTRAINT FK_QRI_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id), ADD CONSTRAINT FK_QRI_UNIT FOREIGN KEY (measurement_unit_id) REFERENCES measurement_units (id)');
 }
 public function down(Schema $schema):void{
  $this->addSql('DROP TABLE quote_request_items'); $this->addSql('ALTER TABLE quote_request DROP FOREIGN KEY FK_QR_CONTACT, DROP FOREIGN KEY FK_QR_BRANCH, DROP FOREIGN KEY FK_QR_DELIVERY_ADDRESS'); $this->addSql('ALTER TABLE quote_request DROP client_contact_id, DROP client_branch_id, DROP delivery_address_id, DROP customer_snapshot, DROP delivery_address_snapshot');
 }
}
