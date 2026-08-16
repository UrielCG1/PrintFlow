-- Catálogos técnicos iniciales. No crea proveedores, costos ni existencias.
SET @now = UTC_TIMESTAMP(6);

INSERT INTO measurement_units(code,name,symbol,dimension_type,conversion_factor,decimal_scale,allows_fraction,is_active,created_at,updated_at) VALUES
('PZA','Pieza','pza','COUNT',1,0,FALSE,TRUE,@now,@now),
('ROLLO','Rollo','rollo','COUNT',1,0,FALSE,TRUE,@now,@now),
('CAJA','Caja','caja','COUNT',1,0,FALSE,TRUE,@now,@now),
('CARTUCHO','Cartucho','cart','COUNT',1,0,FALSE,TRUE,@now,@now),
('M','Metro','m','LENGTH',1,6,TRUE,TRUE,@now,@now),
('ML','Metro lineal','ml','LENGTH',1,6,TRUE,TRUE,@now,@now),
('CM','Centímetro','cm','LENGTH',0.01,6,TRUE,TRUE,@now,@now),
('MM','Milímetro','mm','LENGTH',0.001,6,TRUE,TRUE,@now,@now),
('M2','Metro cuadrado','m²','AREA',1,6,TRUE,TRUE,@now,@now),
('L','Litro','L','VOLUME',1,6,TRUE,TRUE,@now,@now),
('ML_VOL','Mililitro','mL','VOLUME',0.001,6,TRUE,TRUE,@now,@now),
('KG','Kilogramo','kg','MASS',1,6,TRUE,TRUE,@now,@now),
('G','Gramo','g','MASS',0.001,6,TRUE,TRUE,@now,@now),
('MIN','Minuto','min','TIME',1,6,TRUE,TRUE,@now,@now),
('H','Hora','h','TIME',60,6,TRUE,TRUE,@now,@now);

UPDATE measurement_units u JOIN measurement_units b ON b.code = CASE
 WHEN u.dimension_type='LENGTH' THEN 'M' WHEN u.dimension_type='AREA' THEN 'M2'
 WHEN u.dimension_type='VOLUME' THEN 'L' WHEN u.dimension_type='MASS' THEN 'KG'
 WHEN u.dimension_type='TIME' THEN 'MIN' END
SET u.base_unit_id=b.id
WHERE u.dimension_type <> 'COUNT';

-- PZA/ROLLO/CAJA/CARTUCHO no comparten conversión universal. Sus equivalencias
-- se registran exclusivamente en material_variant_conversions.

INSERT INTO material_categories(parent_id,code,name,description,category_type,inventory_controlled,is_active,created_at,updated_at) VALUES
(NULL,'SUBSTRATES','Sustratos',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(NULL,'CUT_VINYL','Viniles de corte',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(NULL,'LAMINATES','Laminados',NULL,'LAMINATE',TRUE,TRUE,@now,@now),
(NULL,'INKS','Tintas',NULL,'INK',TRUE,TRUE,@now,@now),
(NULL,'CONSUMABLES','Consumibles',NULL,'CONSUMABLE',TRUE,TRUE,@now,@now),
(NULL,'MOUNTING','Materiales de montaje',NULL,'ADHESIVE',TRUE,TRUE,@now,@now),
(NULL,'CLEANING','Limpieza y mantenimiento',NULL,'CLEANING',TRUE,TRUE,@now,@now);

SET @substrates=(SELECT id FROM material_categories WHERE code='SUBSTRATES');
INSERT INTO material_categories(parent_id,code,name,description,category_type,inventory_controlled,is_active,created_at,updated_at) VALUES
(@substrates,'PRINTABLE_VINYL','Viniles imprimibles',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(@substrates,'BANNERS','Lonas',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(@substrates,'PAPERS','Papeles',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(@substrates,'FILMS','Películas',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now),
(@substrates,'RIGID_MEDIA','Materiales rígidos',NULL,'SUBSTRATE',TRUE,TRUE,@now,@now);

INSERT INTO product_categories(parent_id,code,name,description,is_active,created_at,updated_at) VALUES
(NULL,'BANNER_PRINT','Impresión en lona',NULL,TRUE,@now,@now),
(NULL,'VINYL_PRINT','Impresión en vinil',NULL,TRUE,@now,@now),
(NULL,'LABELS','Etiquetas y calcomanías',NULL,TRUE,@now,@now),
(NULL,'SIGNWRITING','Rotulación',NULL,TRUE,@now,@now),
(NULL,'SIGNAGE','Señalización',NULL,TRUE,@now,@now),
(NULL,'DECORATION','Decoración',NULL,TRUE,@now,@now),
(NULL,'AUTOMOTIVE_PROTECTION','Protección automotriz',NULL,TRUE,@now,@now),
(NULL,'INSTALLATION_SERVICES','Servicios de instalación',NULL,TRUE,@now,@now);
