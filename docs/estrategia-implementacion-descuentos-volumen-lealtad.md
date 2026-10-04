# Prompt de implementación: costeo por volumen y descuentos aditivos

## Rol y objetivo

Actúa como arquitecto de software, desarrollador Symfony/PHP, especialista en bases de datos y diseñador frontend. Implementa de extremo a extremo en este repositorio el nuevo motor de costeo y descuentos para cotizaciones de OoxCorp.

La solución debe ser segura, auditable, reproducible y configurable por administradores. No entregues únicamente entidades o pantallas aisladas: completa migraciones, dominio, servicios, permisos, formularios, vistas, PDF, correos, revisiones, aceptación, órdenes de servicio y pruebas.

Antes de modificar código:

1. Inspecciona el estado real del repositorio y sus cambios sin confirmar; conserva todo trabajo ajeno al alcance.
2. Revisa las entidades, migraciones y flujos actuales antes de elegir nombres o ubicaciones definitivas.
3. Usa migraciones nuevas. No edites migraciones que ya puedan haberse ejecutado.
4. Mantén `CommercialCategory` como línea de negocio y `operations` exclusivamente para producción/taller.
5. Mantén el nombre interno del proyecto PrintFlow. La marca visible al público es OoxCorp.
6. No preguntes de nuevo por decisiones que este documento ya declara cerradas.

## Decisiones de negocio cerradas

- La línea de negocio es `CommercialCategory`.
- La tabla `operations` no participa en el cálculo comercial.
- La fórmula de cada línea produce una **cantidad de costeo normalizada**.
- Esa misma cantidad se utiliza para:
  - calcular el precio bruto de la partida;
  - seleccionar la regla de precio unitario vigente; y
  - sumar el volumen elegible de la línea de negocio.
- El volumen se suma entre todas las partidas de la misma línea de negocio dentro de la cotización.
- El descuento por volumen obtenido para una línea se aplica solamente a las partidas de esa línea.
- Los descuentos por volumen, lealtad y adicional sí pueden coexistir.
- Los descuentos son **aditivos sobre su base original**. Nunca se calculan sucesivamente sobre saldos ya reducidos.
- El descuento por lealtad depende de la clase efectiva A, B o C y es independiente del descuento por volumen.
- Esta independencia sustituye expresamente la interpretación inicial de una matriz volumen + clase: la clase no elige ni modifica el tramo de volumen.
- “Por clase de cliente” se implementa y se presenta como `LOYALTY`; no existe un cuarto tipo `CUSTOMER_CLASS` porque ambos nombres describen el mismo descuento confirmado.
- El descuento adicional es manual, exige motivo y solamente lo puede aplicar un administrador.
- Lealtad se aplica automáticamente y no abre una revisión por cotización; el control administrativo ocurre al asignar la clase y configurar su porcentaje. La acción autenticada del administrador que agrega el descuento adicional constituye su autorización y no requiere una segunda aprobación.
- Todo descuento por volumen mayor que cero requiere aprobación explícita de un administrador.
- Una cotización formal no se puede emitir/enviar ni aceptar mientras su descuento por volumen vigente no esté aprobado.
- `/cotizar` sí debe enviar inmediatamente al solicitante un correo y PDF con costos y descuentos provisionales. Ambos deben aclarar de forma destacada que el equipo revisará la solicitud y enviará la cotización final.
- Los clientes existentes, clientes nuevos y prospectos públicos comienzan en clase C.
- Un contacto hereda dinámicamente la clase del cliente cuando no tiene override. Si se asigna, por ejemplo, clase B al contacto, el cliente conserva su propia clase C.
- Solamente un administrador puede cambiar la clase de un cliente, el override de un contacto o los porcentajes de las clases.
- El descuento provisional basado en `ClientCategory.discount_percentage` se elimina por completo del cálculo nuevo. `ClientCategory` permanece únicamente como segmentación.

## Hallazgos del sistema actual que deben respetarse/corregirse

- `App\Application\Quotations\QuotationManager::applyData()` es actualmente el punto principal de armado y cálculo de cotizaciones.
- `App\Application\Catalog\CommercialItemPriceResolver` es el resolvedor utilizado para precios. `App\Service\Catalog\CatalogPriceResolver` duplica la responsabilidad y contiene una llamada obsoleta; consolida la lógica en un solo resolvedor probado y elimina o retira de servicio la implementación duplicada.
- `item_price_rules` representa tramos de **precio unitario por cantidad**. No la reutilices como tabla de descuentos.
- `Quotation` conserva hoy un único porcentaje global, insuficiente para el nuevo desglose.
- `QuotationItem` no conserva todavía todos los operandos, la versión de fórmula ni asignaciones de descuento.
- La clase efectiva del contacto debe residir en `ClientContact`, no en `Common\Contact`, porque depende de la relación persona-cliente.
- Refactoriza `ClientManager::update()` para que cliente, contactos anidados, overrides y auditoría se persistan dentro de una sola transacción.

## Modelo de datos objetivo

Adapta los nombres exactos a las convenciones existentes, pero conserva estas responsabilidades y restricciones.

### 1. Catálogo de clases de cliente

Crea `client_classes` y la entidad correspondiente con al menos:

| Campo | Regla |
|---|---|
| `id` | PK |
| `code` | `CHAR(1)`, único, solamente A/B/C |
| `name` | Nombre visible |
| `loyalty_discount_percent` | `DECIMAL(7,4)`, entre 0 y 100 |
| `config_revision` | Entero positivo que aumenta automáticamente al cambiar la configuración |
| `is_active` | Booleano |
| `is_system` | Booleano para proteger A/B/C |
| `created_at`, `updated_at` | UTC |

Reglas:

- Inserta A, B y C mediante migración idempotente.
- Como los porcentajes definitivos no fueron proporcionados, inicialízalos en `0.0000`; no inventes valores comerciales.
- El administrador puede modificar nombre visible y porcentaje. Mientras el motor esté habilitado, A/B/C deben permanecer activas.
- Los códigos A/B/C son inmutables y las filas de sistema no se eliminan físicamente.
- Si en el futuro se habilita desactivación, ninguna clase se puede desactivar mientras esté referenciada por clientes/contactos activos y C jamás puede desactivarse mientras sea la predeterminada.
- Añade `clients.client_class_id` como FK `NOT NULL` con `ON DELETE RESTRICT`.
- Migra todos los clientes existentes a C sin inferir nada desde `ClientCategory`.
- Añade `client_contacts.client_class_override_id` como FK nullable con `ON DELETE RESTRICT`.
- `NULL` significa herencia dinámica; no copies C físicamente a todos los contactos.
- Implementa un resolvedor único de clase efectiva: override del contacto seleccionado, en su ausencia clase del cliente.

### 2. Catálogo de tipos de descuento

Crea `discount_types` con:

- `id`, `code` único e inmutable, `name`, `description`, `display_order`, `is_active`, `is_system`, timestamps;
- códigos de sistema: `VOLUME`, `LOYALTY`, `ADDITIONAL`;
- nombres visibles: “Por volumen”, “Por lealtad” y “Descuento adicional”.

No agregues “amigos y familiares” ni otros tipos. El código define el comportamiento; no permitas que editar el catálogo convierta un tipo automático en manual o desactive controles de autorización. Las filas referenciadas no se borran.
Los tres tipos de sistema permanecen activos mientras el motor esté habilitado; la administración del catálogo sólo puede cambiar los textos permitidos, no sus códigos, semántica ni controles.

### 3. Perfiles de cálculo por línea de negocio

Crea una configuración unívoca por `CommercialCategory`, por ejemplo `commercial_costing_profiles`:

- FK a `commercial_categories`, única;
- `calculation_method` con uno de los métodos tipados admitidos;
- FK a la unidad de medida normalizada de costeo;
- `profile_revision` entero positivo, incrementado automáticamente ante cualquier cambio de método, unidad o parámetros;
- `strategy_version` entero positivo definido por la implementación PHP, nunca editable libremente desde un formulario;
- `parameters_json` para parámetros validados, nunca para código ejecutable;
- `is_active`, timestamps.

Implementa estrategias PHP tipadas registradas en un `CostingCalculationRegistry`. No evalúes expresiones, PHP, SQL ni fórmulas arbitrarias almacenadas en la base. Un cambio real del algoritmo exige una nueva `strategy_version`, pruebas de regresión y recálculo explícito de documentos mutables.

Métodos iniciales:

| Método estable | Línea esperada | Cantidad de costeo y volumen elegible |
|---|---|---|
| `DIGITAL_AREA` | Impresión digital | `largo × ancho × piezas`, convirtiendo largo y ancho a metros antes de multiplicar; resultado en m² |
| `OFFSET_SHEET_COLOR_THOUSAND` | Offset | `múltiplos_de_carta × total_de_colores × ceil(piezas / 1000)` |
| `PROMOTIONAL_PIECE_PERSONALIZATION` | Promocionales | `piezas + número_de_posiciones_de_personalización` |
| `SCREEN_SIZE_HUNDRED_INK` | Serigrafía | `factor_de_tamaño × ceil(piezas / 100) × número_de_tintas` |

Detalles obligatorios:

- Define DTOs de entrada por método y valida presencia, tipo, positividad y límites razonables.
- En digital, normaliza explícitamente mm/cm/m a metros; no asumas unidad por el nombre del atributo.
- En offset, `total_de_colores` debe incluir los colores cobrables según los datos comerciales existentes.
- En promocionales, la personalización numérica significa cantidad de posiciones personalizadas.
- En serigrafía, guarda en `parameters_json` un mapa validado de tamaño a factor, con valores decimales positivos.
- Conserva en el snapshot todos los operandos, conversiones, resultado, unidad, método y versión.
- Usa `Brick\Math\BigDecimal`; no uses `float` para cantidades, precios, porcentajes ni impuestos.
- Si una categoría activa no tiene perfil válido, falla con un mensaje accionable. No calcules silenciosamente con una fórmula distinta.

No asocies perfiles mediante comparación libre del nombre. Utiliza `commercial_categories.code` o IDs existentes verificados. Si los códigos productivos no se conocen desde el repositorio, entrega la pantalla de configuración y un diagnóstico de preparación; no inventes un mapeo destructivo.

#### Contrato de operandos y captura

No dejes que cada estrategia busque características por etiqueta visible. Define roles/códigos técnicos protegidos y mapea cada perfil a ellos. Para la primera versión, el contrato mínimo es:

| Método | Datos requeridos | Fuente estable |
|---|---|---|
| Digital | piezas, ancho terminado, alto terminado | `ordered_quantity` + características protegidas actuales `FINISHED_WIDTH_CM` y `FINISHED_HEIGHT_CM`; el sufijo fija cm y el resolvedor convierte a m antes de multiplicar |
| Offset | piezas, múltiplos de carta, total de colores cobrables | `ordered_quantity` + nuevos roles `LETTER_SHEET_MULTIPLES` y `TOTAL_PRINT_COLORS` |
| Promocionales | piezas, posiciones de personalización | `ordered_quantity` + rol `PERSONALIZATION_POSITION_COUNT` |
| Serigrafía | piezas, tamaño, número de tintas | `ordered_quantity` + roles `SCREEN_SIZE_CODE` y `INK_COUNT`; el código de tamaño se resuelve contra el mapa versionado del perfil |

Implementa esos roles mediante las características configurables ya existentes, con códigos inmutables, tipo de entrada, unidad y opciones compatibles. Si se decide admitir dimensiones distintas de las características actuales en cm, cada valor debe transportar su `measurement_unit_id` y usar el convertidor de unidades; nunca deduzcas mm/cm/m de una etiqueta escrita por el usuario.

Actualiza de manera consistente:

- formularios administrativos y públicos;
- endpoint de metadatos/previsualización de `/cotizar`;
- DTOs y validadores del servidor;
- serialización de `request_details`/snapshots;
- mensajes de campos faltantes;
- configuración/activación de artículos comerciales.

El perfil declara qué rol satisface cada operando y valida cardinalidad. Piezas y conteos son enteros positivos, salvo posiciones de personalización que pueden ser cero; múltiplos/factores aceptan decimal positivo. Los máximos técnicos configurables deben estar validados y versionados. Aunque `piezas + posiciones` es una unidad compuesta poco convencional, fue confirmada como fórmula inicial y no debe reinterpretarse como multiplicación ni como suma de precios sin una nueva decisión de negocio.

#### Compatibilidad entre cantidad y precio

La unidad del resultado del perfil es también la unidad en que se expresa `CommercialItem.base_price` y sus `item_price_rules`. Exige una de estas dos condiciones, sin conversiones implícitas:

1. la unidad del artículo coincide exactamente con `costing_unit_id`; o
2. ambas unidades pertenecen a la misma dimensión, tienen una cadena de conversión válida y el precio/umbrales se convierten de forma explícita y probada a la unidad canónica antes del lookup.

Para las unidades comerciales compuestas sin conversión universal, exige coincidencia exacta. Verifica o crea mediante seed idempotente las unidades protegidas necesarias: `M2` para digital y códigos contextuales estables para millar/tamaño/color, pieza/personalización y ciento/tamaño/tinta, usando la dimensión `COUNT` y factor 1 cuando corresponda. No cambies automáticamente la unidad o el precio de un artículo existente: repórtalo como incompatibilidad y bloquéalo en readiness hasta que un administrador haga la conversión comercial consciente.

### 4. Reglas de descuento por volumen

Crea `volume_discount_rules` con:

- FK al perfil/categoría comercial;
- FK al tipo `VOLUME` o comportamiento equivalente protegido;
- `min_volume DECIMAL(18,6)` mayor que cero;
- `discount_percent DECIMAL(7,4)` entre 0 y 100;
- `config_revision` entero positivo incrementado automáticamente al cambiar porcentaje o estado;
- `is_active`, timestamps;
- índices para buscar por categoría, activo y volumen mínimo;
- `UNIQUE (costing_profile_id, min_volume)` para todas las filas, activas o inactivas. Para reutilizar un umbral se edita/reactiva su fila; no se crea un duplicado.

La búsqueda es: para el volumen agregado de una categoría, seleccionar la regla activa con el mayor `min_volume` menor o igual al volumen. No es necesario guardar máximo: el siguiente mínimo define implícitamente el límite superior. Si no hay regla aplicable, el descuento de esa categoría es 0% y no requiere aprobación.

Valida transaccionalmente que, al aumentar el volumen mínimo, el porcentaje no disminuya. Ejecuta la validación al crear, editar, activar o desactivar, considerando sólo el conjunto activo y bloqueándolo siempre en el mismo orden para evitar carreras/deadlocks. Protege ediciones concurrentes con bloqueo adecuado. No incluyas clase A/B/C en esta matriz: volumen y lealtad son descuentos independientes.

Ejemplo únicamente ilustrativo, **no datos para sembrar**:

| Línea | Volumen mínimo | Descuento |
|---|---:|---:|
| Impresión digital | 1 m² | 0% |
| Impresión digital | 20 m² | 3% |
| Impresión digital | 50 m² | 6% |
| Impresión digital | 100 m² | 9% |

Si dos partidas digitales generan 30 m² y 25 m², el agregado es 55 m²: se selecciona 6% y se aplica solamente sobre el subtotal bruto original de esas dos partidas.

### 5. Cantidad solicitada y cantidad de costeo

Evita que `QuotationItem.quantity` siga mezclando piezas capturadas y cantidad normalizada. Adopta `DECIMAL(18,6)` como escala canónica de cantidades comerciales y migra conjuntamente `quotation_items.quantity`, `item_price_rules.min_quantity`, `service_order_items.quantity`, DTOs, setters, normalizadores, comparadores, repositorios y snapshots que hoy usan cuatro decimales. Implementa además una transición compatible:

- añade `ordered_quantity DECIMAL(18,6) NULL` para la cantidad solicitada por el usuario y `calculation_origin` (`CALCULATED` o `LEGACY_UNKNOWN`);
- conserva `quantity` como cantidad de costeo normalizada si ello minimiza el riesgo de compatibilidad, o renómbrala mediante una migración segura si el análisis demuestra que no rompe integraciones;
- añade unidad de costeo y snapshot de cálculo a la partida;
- en toda partida nueva `ordered_quantity` es obligatoria por dominio, aunque la columna permanezca nullable para soportar historia;
- para datos históricos, reconstruye `ordered_quantity` sólo cuando un snapshot confiable demuestre el dato original. En partidas M2/AUTO antiguas, `quantity` puede ser ya el área y no las piezas: déjala nula con `LEGACY_UNKNOWN`. Nunca etiquetes un área histórica como cantidad de piezas ni recalcules documentos emitidos.

El resultado del servicio de cálculo debe incluir al menos:

- cantidad solicitada;
- cantidad de costeo;
- unidad normalizada;
- método y versión;
- operandos originales y normalizados;
- explicación legible para UI/PDF;
- hash canónico del cálculo.

No redondees a cuatro decimales antes de resolver precios o volumen. Conserva seis decimales hasta la frontera definida por la unidad y prueba valores que habrían seleccionado un tramo distinto con la precisión anterior.

### 6. Aplicaciones y asignaciones de descuento

Crea `quotation_discount_applications` para congelar cada descuento calculado:

| Campo | Propósito |
|---|---|
| `quotation_id` | Cotización |
| `pricing_calculation_version` | Debe coincidir con la versión vigente de la cotización |
| `discount_type_id` | VOLUME, LOYALTY o ADDITIONAL |
| `scope` | `BUSINESS_LINE` o `QUOTATION` |
| `commercial_category_id` | Obligatoria para `BUSINESS_LINE`, nula para `QUOTATION` |
| `scope_key` | Columna generada/inmutable (`CATEGORY:<id>` o `QUOTE`), nunca aceptada desde el navegador |
| `source_volume_rule_id` | Regla origen, sólo volumen |
| `source_client_class_id` | Clase efectiva origen, sólo lealtad |
| `percentage` | Porcentaje congelado |
| `base_amount` | Base monetaria original elegible |
| `discount_amount` | Importe calculado |
| `reason` | Obligatorio para adicional |
| `created_by_user_id` | Obligatorio para adicional |
| `context_snapshot` | Códigos, nombres, regla, clase, volúmenes y versiones usados |
| `display_order`, timestamps | Presentación y auditoría |

Impón consistencia entre alcance, categoría, regla, clase, motivo y actor con validación de dominio y `CHECK` de base de datos donde aplique. Usa `UNIQUE (quotation_id, pricing_calculation_version, discount_type_id, scope_key)`. `scope_key` debe derivarse en la base o en una única fábrica de dominio y jamás poder divergir de `scope`/`commercial_category_id`.

Crea además `quotation_item_discount_allocations`:

- FK a la aplicación y a la partida;
- base bruta original de la partida;
- importe asignado a esa partida;
- unicidad aplicación + partida.

Genera asignaciones para **todos** los tipos:

- `VOLUME`: sólo partidas de la categoría de la aplicación;
- `LOYALTY`: todas las partidas sobre el subtotal bruto original individual;
- `ADDITIONAL`: todas las partidas sobre el subtotal bruto original individual.

Calcula primero el importe de la aplicación sobre su base agregada. Para asignarlo, calcula `round(subtotal_bruto_partida × porcentaje / 100, 2)` por cada partida elegible y reconcilia la diferencia contra el importe agregado en la última partida, ordenada determinísticamente por `line_number` e ID. Verifica que la suma de asignaciones sea exactamente igual al importe de la aplicación. Así se demuestra que volumen afecta sólo su línea, se controla que ninguna partida quede negativa y se pueden copiar netos a órdenes.

Los descuentos de documentos ya emitidos son snapshots inmutables. En borradores o solicitudes, conserva sólo el conjunto vigente de aplicaciones/asignaciones y reemplázalo atómicamente al recalcular; registra el antes/después en auditoría. Cada fila vigente lleva la versión de cálculo para detectar mezclas parciales.

### 7. Revisión del descuento por volumen

Crea en `quotations`:

- `pricing_engine_version` con valores explícitos `LEGACY` o `V2`;
- `pricing_calculation_version` entero que aumenta ante cualquier cambio del cálculo total;
- `pricing_hash CHAR(64)` y fingerprint de configuración;
- `volume_calculation_version` entero que aumenta **solamente** cuando cambia el fingerprint relevante para aprobación de volumen;
- `volume_hash CHAR(64) NULL`.

Crea un historial, por ejemplo `quotation_volume_discount_reviews`, con:

- cotización, `volume_calculation_version` y `review_attempt`;
- hash canónico de las partidas, cantidades de costeo, bases, volúmenes, reglas y aplicaciones de volumen;
- estado `PENDING`, `APPROVED`, `REJECTED` o `INVALIDATED`;
- origen `SYSTEM`, `PUBLIC` o `USER`; solicitante nullable para flujos públicos/sistema y quién/cuándo solicitó la revisión;
- quién/cuándo aprobó o rechazó;
- notas y motivo de invalidación;
- `UNIQUE (quotation_id, volume_calculation_version, review_attempt)`.

Reglas:

- Si existe al menos una aplicación `VOLUME` mayor que 0%, el estado inicial es `PENDING`.
- Sin descuento de volumen positivo, la revisión es `NOT_REQUIRED` a nivel de proyección/servicio y no hace falta una aprobación ficticia.
- Las transiciones admitidas son `PENDING → APPROVED|REJECTED|INVALIDATED` y `APPROVED|REJECTED → INVALIDATED` si cambia el fingerprint. Para reconsiderar un rechazo sin cambiar el cálculo se crea `review_attempt + 1` en `PENDING`; nunca se borra el intento anterior.
- Aprobar o rechazar requiere permiso de administrador, CSRF y bloqueo transaccional de la cotización.
- Antes de aprobar, vuelve a verificar que el hash almacenado coincide con el cálculo persistido.
- Un cambio que altere partidas, línea, operandos, cantidad de costeo, precio, base, perfil, regla seleccionada o importe de volumen invalida la aprobación y crea una nueva versión pendiente.
- Cambiar sólo el descuento adicional, la clase/lealtad u otro dato ajeno al fingerprint de volumen incrementa `pricing_calculation_version`, pero conserva `volume_calculation_version` y su aprobación si el hash de volumen permanece idéntico.
- Una configuración modificada no debe cambiar silenciosamente una cotización. Marca el cálculo como desactualizado y exige recálculo; si cambia el hash de volumen, invalida la aprobación.
- Usa bloqueo pesimista o control optimista consistente en aprobar, rechazar, emitir, enviar y aceptar para impedir carreras.

### Hashes y detección de configuración obsoleta

Usa SHA-256 sobre JSON canónico UTF-8. Ordena claves de objetos recursivamente, listas de partidas por `line_number`/ID y conjuntos de configuración por código/ID estable. Serializa decimales como strings con escala fija, conserva `null` y booleanos, y excluye textos visibles, timestamps y datos aleatorios.

- `pricing_hash` incluye operandos, cantidades, unidad, precio/regla, subtotales, clase efectiva, porcentajes, aplicaciones, impuesto y revisiones de configuración.
- `volume_hash` incluye únicamente datos que el administrador aprueba: partidas elegibles, categoría, cantidad de costeo, base bruta, unidad, perfil/revisión/estrategia, regla/revisión, volumen, porcentaje e importe.

Incluye `profile_revision`, `strategy_version`, `config_revision` de reglas y de clase en el fingerprint correspondiente. Antes de emitir/enviar/aceptar, reconstruye un fingerprint liviano contra la configuración vigente. Si no coincide, bloquea con “requiere recálculo”; no actualices miles de cotizaciones en segundo plano ni recalcules durante el envío.

## Motor de cálculo

Centraliza el flujo en servicios de dominio; controladores, Twig y JavaScript nunca son la autoridad del precio.

Servicios recomendados:

- `EffectiveClientClassResolver`;
- `CostingCalculationRegistry` y una estrategia por método;
- `VolumeDiscountRuleResolver`;
- `QuotationDiscountCalculator`;
- `QuotationDiscountApprovalService`;
- un único resolvedor de precio unitario consolidado.

Orden canónico:

1. Resolver cliente, contacto comercial y clase efectiva.
2. Validar especificaciones de cada partida.
3. Calcular y congelar cantidad de costeo por fórmula.
4. Resolver precio unitario usando esa cantidad de costeo y las reglas actuales de precio.
5. Calcular subtotal bruto de cada partida: `cantidad_de_costeo × precio_unitario`.
6. Agrupar cantidades de costeo y subtotales brutos por `CommercialCategory`.
7. Resolver una regla de volumen por categoría y crear sus aplicaciones/asignaciones.
8. Crear el descuento de lealtad sobre el subtotal bruto original completo usando la clase efectiva.
9. Incorporar, si existe, el descuento adicional autorizado sobre el subtotal bruto original completo.
10. Sumar descuentos, calcular base gravable, IVA y total.
11. Persistir snapshots, asignaciones, versiones, hashes y estado de revisión dentro de una transacción.

### Matemática y redondeo

Para una cotización con subtotal bruto original `S`, subtotal original de la línea `L` y porcentajes `pv`, `pl` y `pa`:

```text
descuento_volumen_linea = round(L × pv / 100, 2)
descuento_lealtad       = round(S × pl / 100, 2)
descuento_adicional     = round(S × pa / 100, 2)
descuento_total         = suma de importes anteriores
base_gravable           = S - descuento_total
IVA                     = round(base_gravable × tax_rate, 2)
total                   = base_gravable + IVA
```

Conserva el contrato actual: `Quotation.tax_rate` es una fracción entre 0 y 1 (`0.1600` representa 16%). No la dividas entre 100 ni migres su representación. Los porcentajes de descuento sí se almacenan en escala 0..100 y por eso sus fórmulas dividen entre 100.

Ejemplo: si `S = $10,000`, la línea digital representa `$6,000`, volumen es 6%, clase B está configurada con 4% y un administrador agrega 2%:

- volumen: `$6,000 × 6% = $360`;
- lealtad: `$10,000 × 4% = $400`;
- adicional: `$10,000 × 2% = $200`;
- descuento total: `$960`, no `$925.25` ni otro resultado compuesto.

Utiliza precisión interna suficiente y `RoundingMode::HALF_UP` únicamente en fronteras monetarias definidas. Documenta las escalas. Rechaza la operación si la suma de porcentajes aplicables a cualquier partida supera 100% o si alguna partida/base final resulta negativa; no recortes silenciosamente el descuento.

## Ciclo de vida y bloqueos

Define una política única, reutilizada por todos los controladores y servicios:

- **Solicitud/borrador:** se puede recalcular y editar.
- **Solicitud pública provisional:** puede enviar inmediatamente correo y PDF provisionales aunque el volumen esté pendiente.
- **Emisión formal:** bloqueada si el descuento por volumen actual requiere aprobación y no está `APPROVED`.
- **Envío formal:** bloqueado por la misma política.
- **Aceptación administrativa y aceptación mediante token público:** bloqueadas por la misma política y por cualquier cálculo desactualizado.
- **Emitida/aceptada/rechazada/cancelada:** importes, fórmulas, aplicaciones y snapshots son inmutables; una modificación debe crear una nueva revisión de cotización.

Aplica los guardas tanto en interfaz como en backend. Ocultar o desactivar un botón no sustituye la autorización del servidor. Los tokens de aceptación no deben habilitarse ni enviarse en un documento provisional.

Modifica el ciclo del token: `Quotation::__construct()` no debe generarlo. `acceptance_token` permanece `NULL` en solicitud, revisión y borrador; se crea con entropía segura, dentro de la transacción y después de pasar todos los guardas, al emitir/preparar el primer envío formal. La migración debe poner en `NULL` los tokens existentes de estados preformales. La aceptación pública valida simultáneamente token, estado formal permitido, vigencia, `pricing_engine_version`, fingerprint actual y aprobación; conocer un token nunca basta.

Las cotizaciones históricas emitidas antes del corte se marcan explícitamente `pricing_engine_version = LEGACY` y conservan su flujo/snapshot sin exigir aplicaciones V2 inexistentes. No uses una comparación de fechas para decidirlo. Una revisión nueva nacida de una cotización legacy siempre se calcula como V2 y queda sujeta a las nuevas reglas.

## Flujo público `/cotizar`

Mantén el envío inmediato, separándolo explícitamente del envío formal:

1. El usuario envía la solicitud.
2. El servidor resuelve el contacto, cliente/prospecto clase C, cantidades de costeo, precios y descuentos automáticos de volumen/lealtad.
3. Persiste la solicitud y, si corresponde, deja la revisión de volumen en `PENDING`.
4. Envía un **acuse con estimación preliminar**, incluyendo el PDF provisional y el desglose de costos/descuentos calculados.
5. Muestra en correo y PDF un aviso inequívoco equivalente a:

   > Estimación preliminar sujeta a revisión. Nuestro equipo validará especificaciones, costos y descuentos; recibirás la cotización final en poco tiempo.

6. El correo provisional no incluye CTA ni enlace de aceptación.
7. Conserva la regla ya definida para entrega: el cuerpo del correo provisional no muestra fecha de entrega; el PDF provisional sí la muestra con asterisco y una nota pequeña indicando que está pendiente de confirmación.
8. Tras ajustes y aprobación administrativa, genera y envía la cotización formal con su token/flujo normal de aceptación.

Crea métodos/plantillas separados para “acuse provisional” y “cotización formal” para evitar que una opción booleana olvidada envíe por accidente un token o texto incorrecto.

## Administración y experiencia de usuario

Usa los componentes y estilos estándar ya existentes en el proyecto. Evita controles nativos sin normalizar, estilos inline en la aplicación y espacios vacíos al abrir formularios.

### Configuración de descuentos

Crea un módulo administrativo coherente con el resto del sistema, con secciones o pestañas para:

- tipos de descuento (consulta y campos visibles permitidos);
- clases A/B/C y porcentaje de lealtad;
- perfil de cálculo de cada línea de negocio;
- tramos de volumen por línea.

En tramos de volumen:

- presenta mínimo, unidad, porcentaje y límite superior implícito;
- ordena por mínimo;
- valida duplicados y porcentajes decrecientes antes de enviar y nuevamente en servidor;
- solicita confirmación para cambios que dejen cotizaciones pendientes desactualizadas;
- muestra estados vacíos útiles, no tablas en blanco.

### Clientes y contactos

- En alta/edición de cliente muestra clase A/B/C solamente a usuarios autorizados; C es el valor inicial.
- En el contacto ofrece “Heredar clase del cliente (X)” como opción predeterminada y A/B/C como overrides.
- Explica que cambiar el contacto no cambia la clase del cliente.
- Un usuario sin permiso puede ver la clase efectiva cuando la operación lo requiera, pero no editarla.

### Cotización

Muestra de forma clara:

- cantidad solicitada;
- fórmula/operandos relevantes;
- cantidad de costeo y unidad;
- subtotal bruto;
- volumen agregado por línea y tramo seleccionado;
- clase efectiva y origen (cliente o override del contacto);
- cada descuento con tipo, base, porcentaje e importe;
- estado de aprobación del volumen;
- subtotal, descuento total, IVA y total.

El descuento adicional debe abrir un formulario compacto de porcentaje + motivo, con confirmación explícita. No reutilices el campo global antiguo `discountPercent`.

En detalle, los administradores cuentan con acciones accesibles de aprobar/rechazar, notas y bitácora. Si enviar/emitir/aceptar está bloqueado, muestra el motivo junto al botón deshabilitado.

## PDF, correo y órdenes de servicio

- Tanto el PDF provisional como el formal y sus correos muestran una tabla de descuentos con: tipo, alcance/línea, base original, porcentaje e importe.
- No presentes un “porcentaje total” engañoso cuando existen bases distintas; muestra el importe total descontado y, si se necesita un porcentaje efectivo, etiquétalo expresamente como informativo.
- Congela nombres, códigos, porcentajes, bases, reglas y clase efectiva en snapshots para que cambios futuros de catálogo no reescriban documentos emitidos.
- Conserva el diseño corporativo y el logo oficial ya implementado; no reintroduzcas PrintFlow como marca visible.
- Al crear una orden de servicio desde una cotización aceptada, copia el resultado; nunca vuelvas a calcular con reglas vigentes.

Para órdenes, crea `service_order_discount_applications` y `service_order_item_discount_allocations` como copias inmutables sin dependencia funcional de la configuración vigente. Añade a `service_order_items` `gross_subtotal`, `discount_amount` y `net_subtotal`, y conserva en la cabecera el `discount_amount` agregado. Copia códigos/nombres/bases/porcentajes/importes/contexto y referencia de origen sólo para trazabilidad; la eliminación/cambio del catálogo no puede alterar la orden.

Resuelve explícitamente los campos globales legados:

- `quotations.discount_amount` y `service_orders.discount_amount` permanecen como totales agregados canónicos;
- renombra o migra `discount_percent` a `legacy_discount_percent_snapshot`, nullable y de sólo lectura;
- para documentos V2 el porcentaje legacy es `NULL`; ningún cálculo, formulario, PDF, correo o auditoría nuevo puede depender de él;
- para documentos `LEGACY`, consérvalo exactamente y muestra el fallback “Descuento histórico” si no existen aplicaciones;
- actualiza getters, calculadores, validadores, snapshots y plantillas en el mismo corte para evitar dos fuentes de verdad.

## Permisos y auditoría

Integra permisos siguiendo el mecanismo existente. Como mínimo:

- `discounts.view`;
- `discounts.types.manage`;
- `discounts.client_classes.manage`;
- `discounts.costing_profiles.manage`;
- `discounts.volume_rules.manage`;
- `clients.assign_class`;
- `clients.contacts.assign_class`;
- `quotations.apply_additional_discount`;
- `quotations.approve_volume_discount`.

Asigna permisos de mutación únicamente a `ROLE_ADMIN`. Revisa y retira/reemplaza el permiso legado `quote.apply_discount` donde corresponda. Protege rutas, servicios y formularios; incluye CSRF. No confíes en valores de porcentaje, clase, importes o aprobación recibidos desde el navegador.

Registra en la bitácora:

- cambios de clase y porcentaje de lealtad;
- override de contacto;
- altas/cambios/bajas lógicas de perfiles y tramos;
- recálculo e invalidación;
- descuento adicional y motivo;
- solicitud, aprobación o rechazo del volumen;
- intentos bloqueados de emitir, enviar o aceptar.

Incluye valores anteriores/nuevos y actor. Los intentos bloqueados deben escribirse mediante un canal de seguridad o después del rollback, no dentro de la misma transacción que lanza la excepción. No almacenes secretos, tokens, payloads CSRF fallidos ni datos sensibles innecesarios.

## Estrategia de migración y despliegue

Implementa una secuencia expandir → configurar/backfill → cortar → contraer en **al menos dos releases**. Introduce una bandera temporal, por ejemplo `QUOTATION_PRICING_V2_ENABLED`, evaluada en un único selector de motor y deshabilitada por defecto en producción. No mantengas rutas híbridas dentro de una misma cotización.

### Fase 1: expandir

- Crear tablas, índices, checks y FKs nuevas.
- Sembrar tipos y clases de forma idempotente. En reejecuciones inserta sólo ausentes y actualiza exclusivamente invariantes técnicas; nunca restablezcas a `0.0000` un porcentaje que ya configuró un administrador ni sobrescribas textos administrables.
- Agregar columnas inicialmente nullable cuando el backfill lo exija.
- Registrar permisos sin duplicarlos.
- Respetar el patrón MySQL/MariaDB existente, guardas de plataforma y migraciones DDL no transaccionales cuando aplique.
- Desplegar esquema, administración, configuración, comando de readiness y cálculo V2 en modo de prueba/sombra, pero conservar el motor actual como autoridad mientras la bandera esté apagada.

### Fase 2: backfill y configuración

- Asignar clase C a todos los clientes y después volver la FK `NOT NULL`.
- Conservar override nulo en contactos existentes.
- Marcar cotizaciones emitidas/no mutables existentes como `pricing_engine_version = LEGACY` y preservar exactamente sus importes.
- Dejar solicitudes/borradores legacy pendientes de migración explícita a V2 al volver a editarlos; no mezclar aplicaciones V2 con sus totales viejos.
- Crear perfiles únicamente con códigos de categoría verificados.
- Proveer un comando/reporte de preparación que liste categorías activas sin perfil, operandos/características faltantes, unidades artículo-perfil incompatibles, unidades contextuales ausentes/inactivas, escalas/umbrales incompatibles, perfiles inválidos, clases sin configuración y reglas inconsistentes.
- Configurar y validar producción con la bandera todavía apagada. El comando debe terminar con código distinto de cero ante cualquier bloqueo.

### Fase 3: corte del motor

- En un segundo release, exigir readiness exitoso y activar el nuevo cálculo en altas, ediciones, revisiones y `/cotizar`.
- Eliminar todas las lecturas/escrituras nuevas de `ClientCategory.discount_percentage`, `Client::getDefaultDiscountPercent()`, el valor JS `defaultDiscountPercent`, el campo global editable y ramas equivalentes.
- Las solicitudes/borradores legados se recalculan explícitamente antes de continuar.
- Los documentos históricos emitidos no se recalculan: pueden conservar los campos numéricos antiguos como snapshot de sólo lectura y mostrarse como “Descuento histórico”. Esto no significa que el cálculo antiguo siga activo.
- Poner en `NULL` tokens de aceptación de solicitudes/estados preformales existentes y exigir estado formal en el endpoint público.

### Fase 4: contraer

- Cuando el código ya no lo use, elimina `client_categories.discount_percentage` mediante una migración nueva.
- Retira servicios, DTOs, campos de formulario y JavaScript obsoletos.
- Si se conservan columnas históricas de cotización, renómbralas/documenta su condición de snapshot; ningún flujo nuevo debe escribirlas como fuente de cálculo.
- Retirar la bandera y el selector legacy para documentos nuevos una vez estabilizado V2; conservar sólo el lector de snapshots `LEGACY`.
- Asegura que `down()` elimine primero FKs/permisos y luego tablas en orden seguro, sin destruir datos ajenos.

No despliegues el corte si hay líneas activas sin perfil válido. No inventes porcentajes de lealtad o volumen para completar datos faltantes.

## Compatibilidad, transacciones e invariantes

- Una sola transacción debe cubrir recálculo, reemplazo de aplicaciones/asignaciones, totales, versión y revisión pendiente.
- `ClientManager` debe incluir cliente y contactos anidados en su transacción y auditoría.
- Las configuraciones referenciadas usan `RESTRICT`; no borres reglas ya congeladas en documentos.
- Desactivar una regla afecta cálculos futuros, no snapshots emitidos.
- Una nueva revisión de cotización usa configuración vigente, recalcula y solicita nueva aprobación si aplica.
- Ningún importe final se deriva desde etiquetas traducidas o nombres visibles.
- Los códigos y enums persistidos son estables e independientes del idioma.
- Todos los timestamps se almacenan en UTC y se presentan en la zona configurada.

## Pruebas obligatorias

Agrega pruebas unitarias, de integración y funcionales que cubran como mínimo:

### Fórmulas y precios

- Cada una de las cuatro fórmulas, sus unidades, conversiones, `ceil`, operandos inválidos y límites.
- Mapeo de cada rol técnico desde formularios/API/snapshot hasta su DTO, incluidas las características actuales en cm.
- La cantidad de costeo alimenta tanto precio bruto como selección de `item_price_rules`.
- Compatibilidad estricta o conversión explícita entre unidad del perfil, artículo, precio y umbral.
- Regresión de reglas de precio existentes.
- Precisión `DECIMAL(18,6)`, umbrales sensibles al quinto/sexto decimal y redondeo monetario.

### Volumen

- Suma de varias partidas de la misma categoría.
- Independencia entre categorías.
- Debajo, exactamente en y entre umbrales.
- Regla inactiva, ausencia de regla, duplicados y porcentajes decrecientes.
- Ediciones concurrentes de reglas.

### Clases y lealtad

- Cliente nuevo/existente/prospecto en C.
- Herencia dinámica del contacto.
- Override sin mutar al cliente.
- Cambio posterior del cliente reflejado sólo en contactos sin override.
- Restricción de edición a administrador.

### Descuentos aditivos

- Volumen + lealtad + adicional sobre bases originales.
- Cotización con varias líneas y volumen sólo en una.
- Asignaciones por partida y centavo residual.
- Asignaciones de VOLUME sólo a su línea y de LOYALTY/ADDITIONAL a todas las partidas elegibles.
- Rechazo cuando descuentos aplicables superan la base de una partida.
- Motivo y actor obligatorios para adicional.

### Aprobación y seguridad

- Pendiente cuando volumen > 0 y no requerida cuando es 0.
- Aprobación/rechazo con permisos y CSRF.
- Hash correcto, invalidación tras cambio material y no invalidación por un cambio adicional independiente.
- Incrementos separados de `pricing_calculation_version` y `volume_calculation_version`, JSON canónico estable y reintento posterior a rechazo.
- Bloqueo backend de emisión, envío, aceptación administrativa y aceptación pública.
- Carrera entre editar/aprobar/enviar.
- Usuario no administrador no puede alterar porcentajes, clases, reglas ni payloads ocultos.

### Comunicación y documentos

- `/cotizar` envía correo y PDF provisionales con costos y los tres desgloses que correspondan.
- Ambos incluyen aviso de revisión/futura cotización final.
- El provisional carece de token/CTA de aceptación.
- El token permanece nulo antes del estado formal, se genera atómicamente después de los guardas y el endpoint rechaza tokens de estados no formales.
- El correo provisional omite la fecha de entrega.
- El PDF provisional conserva fecha con asterisco y nota “pendiente de confirmación”.
- Tras aprobación, el envío formal incluye el desglose y permite aceptación.
- PDF/correo mantienen marca OoxCorp y logo oficial.
- La orden de servicio copia snapshots y no recalcula.
- La orden conserva aplicaciones/asignaciones y gross/descuento/neto por partida.

### Migración e histórico

- Backfill C correcto.
- Contactos quedan con override nulo.
- Seeds idempotentes.
- Cotizaciones emitidas conservan exactamente sus totales anteriores.
- `ordered_quantity` histórico sólo se reconstruye con evidencia; M2/AUTO desconocido queda `LEGACY_UNKNOWN`.
- Documentos `LEGACY` no quedan bloqueados por revisiones V2 inexistentes; una revisión nueva sí usa V2.
- Borradores requieren recálculo con el motor nuevo.
- Ya no existe dependencia funcional de `ClientCategory.discount_percentage`.

## Validación técnica final

Ejecuta y corrige, como mínimo, los comandos aplicables:

```bash
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --dry-run
php bin/phpunit
git diff --check
```

Ejecuta además el lint/build de assets disponible en el repositorio y pruebas enfocadas durante el desarrollo. No declares éxito si alguna validación relevante falla; distingue errores introducidos por el cambio de fallos preexistentes con evidencia.

## Orden recomendado de implementación

1. Pruebas de caracterización del cálculo actual y fixtures.
2. Migraciones de expansión, catálogos y permisos.
3. Clases de cliente, herencia/override y formularios.
4. Registro de fórmulas, perfiles y cantidades de costeo.
5. Reglas de volumen y administración.
6. Motor aditivo, aplicaciones y asignaciones.
7. Revisión/aprobación y política central de guardas.
8. Flujo provisional `/cotizar` separado del envío formal.
9. UI de cotización, PDF, emails y órdenes de servicio.
10. Corte y eliminación del descuento de `ClientCategory`.
11. Suite completa, diagnóstico de preparación y documentación operativa.

Haz commits/cambios pequeños y coherentes si el flujo de trabajo lo permite, pero no dejes el sistema en un estado donde una parte use el porcentaje global antiguo y otra el desglose nuevo.

## Criterios de aceptación

La implementación se considera terminada sólo cuando:

1. Toda línea activa usada para cotizar tiene una fórmula tipada y genera cantidad de costeo reproducible.
2. El bruto de cada partida usa esa cantidad y el volumen se agrega por `CommercialCategory`.
3. El tramo aplicable se resuelve correctamente y afecta sólo sus partidas.
4. La clase efectiva produce un descuento de lealtad independiente.
5. Un administrador puede aplicar un adicional con motivo.
6. Los tres descuentos se suman sobre sus bases originales y se muestran individualmente con porcentaje e importe.
7. Los porcentajes y reglas son configurables sin desplegar código, dentro de permisos y validaciones.
8. Un volumen pendiente impide cualquier emisión/envío/aceptación formal también desde llamadas directas al backend.
9. `/cotizar` continúa enviando de inmediato su estimación y PDF provisionales, claramente sujetos a revisión y sin aceptación.
10. Cualquier cambio material invalida la aprobación correcta y queda auditado.
11. Los documentos emitidos y órdenes conservan snapshots inmutables.
12. El descuento de `ClientCategory` ya no participa en ningún cálculo nuevo.
13. Migraciones, pruebas, Twig, contenedor, esquema y assets validan correctamente.
14. La entrega final enumera archivos modificados, migraciones, decisiones técnicas, comandos ejecutados y cualquier configuración comercial pendiente (porcentajes/tramos reales), sin afirmar que se inventaron valores.
