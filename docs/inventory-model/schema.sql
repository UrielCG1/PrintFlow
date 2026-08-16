-- PrintFlow inventory/cost/production target model — MySQL 8.0.16+
-- Timestamps are UTC by application/connection policy. No operational sample data.
SET NAMES utf8mb4;

CREATE TABLE measurement_units (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, base_unit_id BIGINT UNSIGNED NULL,
 code VARCHAR(20) NOT NULL, name VARCHAR(80) NOT NULL, symbol VARCHAR(20) NOT NULL,
 dimension_type VARCHAR(20) NOT NULL, conversion_factor DECIMAL(24,12) NOT NULL DEFAULT 1,
 decimal_scale TINYINT UNSIGNED NOT NULL DEFAULT 6, allows_fraction BOOLEAN NOT NULL DEFAULT TRUE,
 is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT uq_units_code UNIQUE(code), CONSTRAINT uq_units_name UNIQUE(name),
 CONSTRAINT fk_units_base FOREIGN KEY(base_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT ck_units_dimension CHECK(dimension_type IN ('COUNT','LENGTH','AREA','VOLUME','MASS','TIME')),
 CONSTRAINT ck_units_factor CHECK(conversion_factor > 0), CONSTRAINT ck_units_scale CHECK(decimal_scale <= 12),
 INDEX ix_units_dimension_active(dimension_type,is_active)
) ENGINE=InnoDB;

CREATE TABLE brands (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code), UNIQUE(name)) ENGINE=InnoDB;
CREATE TABLE manufacturers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code), UNIQUE(name)) ENGINE=InnoDB;
CREATE TABLE colors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, hex_value CHAR(7) NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code), UNIQUE(name)) ENGINE=InnoDB;
CREATE TABLE finishes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code), UNIQUE(name)) ENGINE=InnoDB;
CREATE TABLE adhesive_types (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code), UNIQUE(name)) ENGINE=InnoDB;

CREATE TABLE material_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NULL, code VARCHAR(40) NOT NULL,
 name VARCHAR(120) NOT NULL, description TEXT NULL, category_type VARCHAR(24) NOT NULL,
 inventory_controlled BOOLEAN NOT NULL DEFAULT TRUE, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 parent_scope BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(parent_id,0)) STORED,
 CONSTRAINT uq_material_categories_code UNIQUE(code), CONSTRAINT uq_material_categories_parent_name UNIQUE(parent_scope,name),
 CONSTRAINT fk_material_categories_parent FOREIGN KEY(parent_id) REFERENCES material_categories(id) ON DELETE RESTRICT,
 CONSTRAINT ck_material_category_self CHECK(parent_id IS NULL OR parent_id <> id),
 CONSTRAINT ck_material_category_type CHECK(category_type IN ('SUBSTRATE','INK','LAMINATE','ADHESIVE','CONSUMABLE','PACKAGING','SPARE_PART','CLEANING')),
 INDEX ix_material_categories_parent_active(parent_id,is_active,name)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_material_categories_no_cycle BEFORE UPDATE ON material_categories FOR EACH ROW
BEGIN
 DECLARE cursor_id BIGINT UNSIGNED; DECLARE depth_count INT DEFAULT 0;
 SET cursor_id = NEW.parent_id;
 WHILE cursor_id IS NOT NULL DO
  IF cursor_id = NEW.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Material category cycle'; END IF;
  SELECT parent_id INTO cursor_id FROM material_categories WHERE id=cursor_id;
  SET depth_count=depth_count+1;
  IF depth_count > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Material category hierarchy too deep'; END IF;
 END WHILE;
END$$
DELIMITER ;

CREATE TABLE materials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id BIGINT UNSIGNED NOT NULL,
 default_inventory_unit_id BIGINT UNSIGNED NOT NULL, default_consumption_unit_id BIGINT UNSIGNED NOT NULL,
 manufacturer_id BIGINT UNSIGNED NULL, code VARCHAR(80) NOT NULL, name VARCHAR(180) NOT NULL, description TEXT NULL,
 material_type VARCHAR(30) NOT NULL, is_stock_item BOOLEAN NOT NULL DEFAULT TRUE,
 is_purchasable BOOLEAN NOT NULL DEFAULT TRUE, is_consumable BOOLEAN NOT NULL DEFAULT TRUE,
 is_hazardous BOOLEAN NOT NULL DEFAULT FALSE, requires_lot_control BOOLEAN NOT NULL DEFAULT FALSE,
 requires_expiration_control BOOLEAN NOT NULL DEFAULT FALSE, default_waste_percentage DECIMAL(7,4) NOT NULL DEFAULT 0,
 storage_conditions TEXT NULL, technical_notes TEXT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT uq_materials_code UNIQUE(code), CONSTRAINT fk_materials_category FOREIGN KEY(category_id) REFERENCES material_categories(id) ON DELETE RESTRICT,
 CONSTRAINT fk_materials_inv_unit FOREIGN KEY(default_inventory_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_materials_cons_unit FOREIGN KEY(default_consumption_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_materials_manufacturer FOREIGN KEY(manufacturer_id) REFERENCES manufacturers(id) ON DELETE RESTRICT,
 CONSTRAINT ck_materials_waste CHECK(default_waste_percentage BETWEEN 0 AND 100),
 INDEX ix_materials_category_active(category_id,is_active), INDEX ix_materials_name(name), INDEX ix_materials_active_name(is_active,name)
) ENGINE=InnoDB;

CREATE TABLE material_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_id BIGINT UNSIGNED NOT NULL, brand_id BIGINT UNSIGNED NULL,
 color_id BIGINT UNSIGNED NULL, finish_id BIGINT UNSIGNED NULL, adhesive_type_id BIGINT UNSIGNED NULL,
 width_unit_id BIGINT UNSIGNED NULL, length_unit_id BIGINT UNSIGNED NULL, thickness_unit_id BIGINT UNSIGNED NULL,
 grammage_unit_id BIGINT UNSIGNED NULL, volume_unit_id BIGINT UNSIGNED NULL, weight_unit_id BIGINT UNSIGNED NULL,
 purchase_unit_id BIGINT UNSIGNED NOT NULL, inventory_unit_id BIGINT UNSIGNED NOT NULL, consumption_unit_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(80) NOT NULL, manufacturer_sku VARCHAR(100) NULL, barcode VARCHAR(80) NULL,
 width DECIMAL(16,6) NULL, length DECIMAL(16,6) NULL, thickness DECIMAL(16,6) NULL,
 grammage DECIMAL(16,6) NULL, volume DECIMAL(16,6) NULL, weight DECIMAL(16,6) NULL,
 units_per_package DECIMAL(20,6) NOT NULL DEFAULT 1, purchase_to_inventory_factor DECIMAL(24,12) NOT NULL,
 inventory_to_consumption_factor DECIMAL(24,12) NOT NULL, coverage_area DECIMAL(20,6) NULL,
 reference_cost_mxn DECIMAL(19,6) NULL, minimum_stock DECIMAL(20,6) NOT NULL DEFAULT 0,
 maximum_stock DECIMAL(20,6) NULL, reorder_point DECIMAL(20,6) NOT NULL DEFAULT 0,
 reorder_quantity DECIMAL(20,6) NOT NULL DEFAULT 0, lead_time_days SMALLINT UNSIGNED NULL,
 lot_controlled BOOLEAN NOT NULL, expiration_controlled BOOLEAN NOT NULL, shelf_life_days INT UNSIGNED NULL,
 indoor_outdoor VARCHAR(12) NULL, durability_months SMALLINT UNSIGNED NULL,
 is_default BOOLEAN NOT NULL DEFAULT FALSE, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 default_material_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_default AND is_active THEN material_id ELSE NULL END) STORED,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT uq_variants_code UNIQUE(code), CONSTRAINT uq_variants_barcode UNIQUE(barcode), CONSTRAINT uq_variants_default UNIQUE(default_material_key),
 CONSTRAINT fk_variants_material FOREIGN KEY(material_id) REFERENCES materials(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_brand FOREIGN KEY(brand_id) REFERENCES brands(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_color FOREIGN KEY(color_id) REFERENCES colors(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_finish FOREIGN KEY(finish_id) REFERENCES finishes(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_adhesive FOREIGN KEY(adhesive_type_id) REFERENCES adhesive_types(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_purchase_unit FOREIGN KEY(purchase_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_inventory_unit FOREIGN KEY(inventory_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_consumption_unit FOREIGN KEY(consumption_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_width_unit FOREIGN KEY(width_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_length_unit FOREIGN KEY(length_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_thickness_unit FOREIGN KEY(thickness_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_grammage_unit FOREIGN KEY(grammage_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_volume_unit FOREIGN KEY(volume_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_variants_weight_unit FOREIGN KEY(weight_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT ck_variants_dimensions CHECK((width IS NULL OR width>0) AND (length IS NULL OR length>0) AND (thickness IS NULL OR thickness>0) AND (grammage IS NULL OR grammage>0) AND (volume IS NULL OR volume>0) AND (weight IS NULL OR weight>0)),
 CONSTRAINT ck_variants_factors CHECK(units_per_package>0 AND purchase_to_inventory_factor>0 AND inventory_to_consumption_factor>0),
 CONSTRAINT ck_variants_stock CHECK(minimum_stock>=0 AND reorder_point>=0 AND reorder_quantity>=0 AND (maximum_stock IS NULL OR maximum_stock>=minimum_stock)),
 INDEX ix_variants_material_active(material_id,is_active), INDEX ix_variants_sku(manufacturer_sku), INDEX ix_variants_brand(brand_id)
) ENGINE=InnoDB;

CREATE TABLE material_variant_conversions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_variant_id BIGINT UNSIGNED NOT NULL,
 from_unit_id BIGINT UNSIGNED NOT NULL, to_unit_id BIGINT UNSIGNED NOT NULL, factor DECIMAL(24,12) NOT NULL,
 is_bidirectional BOOLEAN NOT NULL DEFAULT TRUE, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT uq_variant_conversion UNIQUE(material_variant_id,from_unit_id,to_unit_id),
 CONSTRAINT fk_conversion_variant FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE CASCADE,
 CONSTRAINT fk_conversion_from FOREIGN KEY(from_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT fk_conversion_to FOREIGN KEY(to_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CONSTRAINT ck_conversion CHECK(from_unit_id<>to_unit_id AND factor>0)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(80) NOT NULL,
 legal_name VARCHAR(180) NOT NULL, commercial_name VARCHAR(180) NULL, tax_id VARCHAR(20) NULL,
 contact_name VARCHAR(160) NULL, email VARCHAR(180) NULL, phone VARCHAR(40) NULL, website VARCHAR(255) NULL,
 street VARCHAR(160) NULL, external_number VARCHAR(30) NULL, internal_number VARCHAR(30) NULL,
 neighborhood VARCHAR(120) NULL, city VARCHAR(120) NULL, state VARCHAR(120) NULL, postal_code VARCHAR(20) NULL,
 country CHAR(2) NULL, payment_terms_days SMALLINT UNSIGNED NULL, lead_time_days SMALLINT UNSIGNED NULL,
 minimum_order_amount DECIMAL(19,4) NULL, notes TEXT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(code), UNIQUE(tax_id),
 CHECK(minimum_order_amount IS NULL OR minimum_order_amount>=0), INDEX ix_suppliers_active_name(is_active,legal_name)
) ENGINE=InnoDB;

CREATE TABLE supplier_material_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL, material_variant_id BIGINT UNSIGNED NOT NULL,
 purchase_unit_id BIGINT UNSIGNED NOT NULL, supplier_sku VARCHAR(100) NULL,
 supplier_description VARCHAR(255) NULL, package_quantity DECIMAL(20,6) NOT NULL DEFAULT 1,
 unit_cost_mxn DECIMAL(19,6) NOT NULL, minimum_order_quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
 lead_time_days SMALLINT UNSIGNED NULL, last_purchase_cost_mxn DECIMAL(19,6) NULL, last_purchase_date DATE NULL,
 valid_from DATETIME(6) NOT NULL, valid_until DATETIME(6) NULL, is_preferred BOOLEAN NOT NULL DEFAULT FALSE,
 priority SMALLINT UNSIGNED NOT NULL DEFAULT 100, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 preferred_variant_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_preferred AND is_active THEN material_variant_id ELSE NULL END) STORED,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT uq_supplier_variant UNIQUE(supplier_id,material_variant_id), CONSTRAINT uq_supplier_sku UNIQUE(supplier_id,supplier_sku),
 CONSTRAINT uq_preferred_variant UNIQUE(preferred_variant_key),
 FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT, FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 FOREIGN KEY(purchase_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK(package_quantity>0 AND unit_cost_mxn>=0 AND minimum_order_quantity>=0), CHECK(valid_until IS NULL OR valid_until>valid_from),
 INDEX ix_offerings_variant_active(material_variant_id,is_active,priority), INDEX ix_offerings_supplier_active(supplier_id,is_active)
) ENGINE=InnoDB;

CREATE TABLE product_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NULL, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL,
 description TEXT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(code), FOREIGN KEY(parent_id) REFERENCES product_categories(id) ON DELETE RESTRICT, CHECK(parent_id IS NULL OR parent_id<>id),
 INDEX ix_product_categories_parent(parent_id,is_active,name)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_product_categories_no_cycle BEFORE UPDATE ON product_categories FOR EACH ROW
BEGIN
 DECLARE cursor_id BIGINT UNSIGNED; DECLARE depth_count INT DEFAULT 0;
 SET cursor_id = NEW.parent_id;
 WHILE cursor_id IS NOT NULL DO
  IF cursor_id = NEW.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Product category cycle'; END IF;
  SELECT parent_id INTO cursor_id FROM product_categories WHERE id=cursor_id;
  SET depth_count=depth_count+1;
  IF depth_count > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Product category hierarchy too deep'; END IF;
 END WHILE;
END$$
DELIMITER ;

CREATE TABLE products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id BIGINT UNSIGNED NOT NULL, sale_unit_id BIGINT UNSIGNED NOT NULL,
 production_unit_id BIGINT UNSIGNED NOT NULL, dimension_unit_id BIGINT UNSIGNED NULL,
 production_time_unit_id BIGINT UNSIGNED NULL, code VARCHAR(80) NOT NULL, name VARCHAR(180) NOT NULL, description TEXT NULL,
 product_type VARCHAR(20) NOT NULL, base_price_mxn DECIMAL(19,4) NULL, tax_category_code VARCHAR(40) NULL,
 requires_production BOOLEAN NOT NULL DEFAULT FALSE, requires_installation BOOLEAN NOT NULL DEFAULT FALSE,
 default_width DECIMAL(16,6) NULL, default_height DECIMAL(16,6) NULL, minimum_sale_quantity DECIMAL(20,6) NOT NULL DEFAULT 1,
 default_waste_percentage DECIMAL(7,4) NOT NULL DEFAULT 0, estimated_production_time DECIMAL(16,6) NULL,
 is_stock_item BOOLEAN NOT NULL DEFAULT FALSE, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(code), FOREIGN KEY(category_id) REFERENCES product_categories(id) ON DELETE RESTRICT,
 FOREIGN KEY(sale_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT, FOREIGN KEY(production_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 FOREIGN KEY(dimension_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 FOREIGN KEY(production_time_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK(product_type IN('MANUFACTURED','RESALE','SERVICE','CONFIGURABLE')), CHECK(base_price_mxn IS NULL OR base_price_mxn>=0),
 CHECK(minimum_sale_quantity>0 AND default_waste_percentage BETWEEN 0 AND 100),
 INDEX ix_products_category_active(category_id,is_active), INDEX ix_products_active_name(is_active,name)
) ENGINE=InnoDB;

CREATE TABLE bill_of_material_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, material_id BIGINT UNSIGNED NULL,
 material_variant_id BIGINT UNSIGNED NULL, measurement_unit_id BIGINT UNSIGNED NOT NULL, quantity DECIMAL(20,6) NOT NULL,
 waste_percentage DECIMAL(7,4) NOT NULL DEFAULT 0, is_variant_required BOOLEAN NOT NULL DEFAULT FALSE,
 is_optional BOOLEAN NOT NULL DEFAULT FALSE, is_substitutable BOOLEAN NOT NULL DEFAULT FALSE, sequence SMALLINT UNSIGNED NOT NULL,
 calculation_method VARCHAR(30) NOT NULL, calculation_method_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 calculation_parameters JSON NULL, notes TEXT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(product_id,sequence), FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(material_id) REFERENCES materials(id) ON DELETE RESTRICT, FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 FOREIGN KEY(measurement_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK((material_id IS NULL)<>(material_variant_id IS NULL)), CHECK(quantity>=0 AND waste_percentage BETWEEN 0 AND 100),
 CHECK(calculation_method IN('FIXED','AREA','LENGTH','PERIMETER','VOLUME','PERCENTAGE','AREA_YIELD','PERIMETER_SPACING','SHEET_LAYOUT','ROLL_LAYOUT')),
 CHECK(calculation_method_version > 0),
 INDEX ix_bom_product_active(product_id,is_active), INDEX ix_bom_material(material_id), INDEX ix_bom_variant(material_variant_id)
) ENGINE=InnoDB;

CREATE TABLE production_processes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, default_equipment_id BIGINT UNSIGNED NULL, time_unit_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(40) NOT NULL, name VARCHAR(140) NOT NULL, description TEXT NULL, process_type VARCHAR(40) NOT NULL,
 setup_time DECIMAL(16,6) NOT NULL DEFAULT 0, run_time DECIMAL(16,6) NOT NULL DEFAULT 0,
 is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code),
 FOREIGN KEY(time_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT, CHECK(setup_time>=0 AND run_time>=0)
) ENGINE=InnoDB;

CREATE TABLE equipment (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, width_unit_id BIGINT UNSIGNED NULL, speed_unit_id BIGINT UNSIGNED NULL,
 code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, equipment_type VARCHAR(50) NOT NULL, brand VARCHAR(100) NULL, model VARCHAR(100) NULL,
 maximum_media_width DECIMAL(16,6) NULL, maximum_speed DECIMAL(16,6) NULL, color_configuration VARCHAR(100) NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE(code),
 FOREIGN KEY(width_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT, FOREIGN KEY(speed_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK((maximum_media_width IS NULL OR maximum_media_width>0) AND (maximum_speed IS NULL OR maximum_speed>0)), INDEX ix_equipment_type_active(equipment_type,is_active)
) ENGINE=InnoDB;
ALTER TABLE production_processes ADD CONSTRAINT fk_process_default_equipment FOREIGN KEY(default_equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT;

CREATE TABLE equipment_processes (
 equipment_id BIGINT UNSIGNED NOT NULL, process_id BIGINT UNSIGNED NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 PRIMARY KEY(equipment_id,process_id), FOREIGN KEY(equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT,
 FOREIGN KEY(process_id) REFERENCES production_processes(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE material_equipment_compatibility (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_variant_id BIGINT UNSIGNED NOT NULL, equipment_id BIGINT UNSIGNED NOT NULL,
 process_id BIGINT UNSIGNED NOT NULL, compatibility_status VARCHAR(20) NOT NULL, maximum_width DECIMAL(16,6) NULL,
 width_unit_id BIGINT UNSIGNED NULL, recommended_speed DECIMAL(16,6) NULL, speed_unit_id BIGINT UNSIGNED NULL,
 recommended_temperature DECIMAL(10,4) NULL, recommended_pressure DECIMAL(12,4) NULL,
 icc_profile VARCHAR(255) NULL, blade_type VARCHAR(100) NULL, cut_force DECIMAL(12,4) NULL, passes SMALLINT UNSIGNED NULL,
 requires_liner BOOLEAN NOT NULL DEFAULT FALSE, requires_lamination BOOLEAN NOT NULL DEFAULT FALSE,
 requires_test BOOLEAN NOT NULL DEFAULT FALSE, notes TEXT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(material_variant_id,equipment_id,process_id), FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 FOREIGN KEY(equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT, FOREIGN KEY(process_id) REFERENCES production_processes(id) ON DELETE RESTRICT,
 FOREIGN KEY(width_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT, FOREIGN KEY(speed_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK(compatibility_status IN('APPROVED','CONDITIONAL','REJECTED','UNTESTED')),
 INDEX ix_compat_equipment_process(equipment_id,process_id,is_active)
) ENGINE=InnoDB;

CREATE TABLE product_processes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, process_id BIGINT UNSIGNED NOT NULL,
 equipment_id BIGINT UNSIGNED NULL, time_unit_id BIGINT UNSIGNED NOT NULL, sequence SMALLINT UNSIGNED NOT NULL,
 setup_time DECIMAL(16,6) NOT NULL DEFAULT 0, run_time DECIMAL(16,6) NOT NULL DEFAULT 0,
 external_service BOOLEAN NOT NULL DEFAULT FALSE, notes TEXT NULL, is_required BOOLEAN NOT NULL DEFAULT TRUE,
 is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(product_id,sequence), FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(process_id) REFERENCES production_processes(id) ON DELETE RESTRICT, FOREIGN KEY(equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT,
 FOREIGN KEY(time_unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT, CHECK(setup_time>=0 AND run_time>=0), INDEX ix_product_process_route(product_id,is_active,sequence)
) ENGINE=InnoDB;

CREATE TABLE inventory_lots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_variant_id BIGINT UNSIGNED NOT NULL,
 internal_lot_number VARCHAR(100) NOT NULL, manufacturer_lot_number VARCHAR(100) NULL,
 manufactured_at DATE NULL, expires_at DATE NULL, received_unit_cost_mxn DECIMAL(19,6) NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 UNIQUE(internal_lot_number), FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 CHECK(expires_at IS NULL OR manufactured_at IS NULL OR expires_at>=manufactured_at), INDEX ix_lots_expiry(material_variant_id,expires_at)
) ENGINE=InnoDB;

CREATE TABLE inventory_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_variant_id BIGINT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NULL, unit_id BIGINT UNSIGNED NOT NULL, responsible_user_id INT NULL,
 movement_type VARCHAR(30) NOT NULL, quantity DECIMAL(20,6) NOT NULL, unit_cost_mxn DECIMAL(19,6) NULL,
 source_type VARCHAR(50) NOT NULL, source_id BIGINT UNSIGNED NULL, source_number VARCHAR(100) NULL,
 is_provisional_receipt BOOLEAN NOT NULL DEFAULT FALSE, negative_stock_authorized_by INT NULL,
 negative_stock_reason VARCHAR(255) NULL, occurred_at DATETIME(6) NOT NULL, notes TEXT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 FOREIGN KEY(lot_id) REFERENCES inventory_lots(id) ON DELETE RESTRICT,
 FOREIGN KEY(unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 FOREIGN KEY(responsible_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(negative_stock_authorized_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(quantity<>0), CHECK(unit_cost_mxn IS NULL OR unit_cost_mxn>=0),
 CHECK((negative_stock_authorized_by IS NULL AND negative_stock_reason IS NULL) OR (negative_stock_authorized_by IS NOT NULL AND negative_stock_reason IS NOT NULL)),
 CHECK(movement_type IN('PURCHASE','RECEIPT','PRODUCTION_CONSUMPTION','SALE','RETURN','ADJUSTMENT_IN','ADJUSTMENT_OUT','WASTE','RESERVATION','RELEASE')),
 INDEX ix_movements_stock(material_variant_id,occurred_at), INDEX ix_movements_lot(lot_id,occurred_at),
 INDEX ix_movements_source(source_type,source_id)
) ENGINE=InnoDB;

CREATE TABLE production_material_usages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, service_order_item_id INT NOT NULL,
 material_variant_id BIGINT UNSIGNED NOT NULL, lot_id BIGINT UNSIGNED NULL, unit_id BIGINT UNSIGNED NOT NULL,
 inventory_movement_id BIGINT UNSIGNED NULL, measured_by INT NULL,
 planned_quantity DECIMAL(20,6) NOT NULL, actual_quantity DECIMAL(20,6) NULL,
 posted_quantity DECIMAL(20,6) NOT NULL, waste_quantity DECIMAL(20,6) NULL,
 quantity_source VARCHAR(20) NOT NULL, measurement_method VARCHAR(30) NULL,
 waste_reason VARCHAR(255) NULL, calculation_method VARCHAR(30) NOT NULL,
 calculation_method_version SMALLINT UNSIGNED NOT NULL, calculation_snapshot JSON NOT NULL,
 measured_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(service_order_item_id) REFERENCES service_order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 FOREIGN KEY(lot_id) REFERENCES inventory_lots(id) ON DELETE RESTRICT,
 FOREIGN KEY(unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 FOREIGN KEY(inventory_movement_id) REFERENCES inventory_movements(id) ON DELETE RESTRICT,
 FOREIGN KEY(measured_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(planned_quantity>=0 AND posted_quantity>=0 AND (actual_quantity IS NULL OR actual_quantity>=0) AND (waste_quantity IS NULL OR waste_quantity>=0)),
 CHECK(waste_quantity IS NULL OR actual_quantity IS NOT NULL),
 CHECK(actual_quantity IS NULL OR waste_quantity IS NULL OR waste_quantity<=actual_quantity),
 CHECK((quantity_source='ESTIMATED' AND actual_quantity IS NULL AND posted_quantity=planned_quantity AND measured_by IS NULL AND measured_at IS NULL)
    OR (quantity_source IN('MEASURED','DERIVED','MACHINE') AND actual_quantity IS NOT NULL AND posted_quantity=actual_quantity)),
 CHECK(quantity_source IN('ESTIMATED','MEASURED','DERIVED','MACHINE')),
 CHECK(measurement_method IS NULL OR measurement_method IN('DIRECT','DELIVERY_MINUS_RETURN','ROLL_LENGTH','WEIGHT_DIFFERENCE','PIECE_COUNT','MACHINE_REPORT')),
 INDEX ix_usage_order_item(service_order_item_id), INDEX ix_usage_variant(material_variant_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE material_cost_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, material_variant_id BIGINT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NULL,
 unit_id BIGINT UNSIGNED NOT NULL, cost_type VARCHAR(24) NOT NULL,
 unit_cost_mxn DECIMAL(19,6) NOT NULL, effective_from DATETIME(6) NOT NULL, effective_until DATETIME(6) NULL,
 source_type VARCHAR(50) NULL, source_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT, FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
 FOREIGN KEY(unit_id) REFERENCES measurement_units(id) ON DELETE RESTRICT,
 CHECK(unit_cost_mxn>=0), CHECK(effective_until IS NULL OR effective_until>effective_from),
 CHECK(cost_type IN('REFERENCE','LAST_PURCHASE','MOVING_AVERAGE','SUPPLIER','LEGACY')),
 INDEX ix_cost_history_variant_date(material_variant_id,cost_type,effective_from), INDEX ix_cost_history_supplier(supplier_id,material_variant_id,effective_from)
) ENGINE=InnoDB;

-- Proyección transaccional del promedio móvil actual; el historial anterior
-- conserva cada cambio y permite auditar/reconstruir el valor.
CREATE TABLE material_variant_costs (
 material_variant_id BIGINT UNSIGNED PRIMARY KEY,
 on_hand_quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
 inventory_value_mxn DECIMAL(19,6) NOT NULL DEFAULT 0,
 moving_average_cost_mxn DECIMAL(19,6) NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(material_variant_id) REFERENCES material_variants(id) ON DELETE RESTRICT,
 CHECK(on_hand_quantity>=0 AND inventory_value_mxn>=0 AND moving_average_cost_mxn>=0)
) ENGINE=InnoDB;

-- Stock projection. Physical stock excludes reservations; availability subtracts them.
-- RESERVATION usa cantidad negativa y RELEASE cantidad positiva.
CREATE VIEW inventory_balances AS
SELECT material_variant_id, lot_id,
 SUM(CASE WHEN movement_type IN('RESERVATION','RELEASE') THEN 0 ELSE quantity END) AS on_hand,
 -SUM(CASE WHEN movement_type IN('RESERVATION','RELEASE') THEN quantity ELSE 0 END) AS reserved,
 SUM(quantity) AS available
FROM inventory_movements GROUP BY material_variant_id,lot_id;
