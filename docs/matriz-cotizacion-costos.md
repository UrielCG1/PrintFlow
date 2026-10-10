# Matriz de cotización y estructura de costos

Fuente: hoja `LOGICA` del archivo `base de datos.xlsx` entregado por el cliente. Sus cuatro renglones describen factores de producción; no contienen tarifas, costos, márgenes ni políticas de redondeo. La respuesta de otra IA se usa sólo como hipótesis de análisis, no como dato del cliente.

## Reglas comunes

Para cada partida deben conservarse por separado:

1. **Pedido físico**: número de piezas y especificaciones terminadas.
2. **Magnitud de costeo**: m², millares, cientos u otro factor técnico.
3. **Costo de producción**: material + impresión + personalización/acabados + merma + diseño/preparación + otros costos confirmados.
4. **Precio de venta antes de descuentos**: calculado desde tarifas de venta o desde costo y una política de margen explícita. No se puede inferir una tarifa de la magnitud de costeo.
5. **Descuentos e impuestos**: después de calcular el precio, con base y orden de aplicación definidos.

Los importes monetarios se redondean al final de cada componente cobrado según la política fiscal/comercial que se confirme. Se deben guardar en el snapshot las piezas, dimensiones, factores, tarifas, políticas, cargos fijos, regla aplicada y resultado. Una configuración incompleta se muestra como **Pendiente** y no debe usarse para generar un precio automático.

## Impresión digital / gran formato

```
area_terminada_m2 = ancho_cm × alto_cm ÷ 10000
area_total_m2 = area_terminada_m2 × piezas
area_cobrable_m2 = política_de_redondeo(area_total_m2, mínimo, incremento)
costo_variable = area_de_consumo_m2 × (costo_material_m2 + costo_tinta_m2 + costo_máquina_m2)
costo_total = costo_variable + costo_merma + costo_preparación + costo_diseño + acabados
precio = política_comercial(costo_total, tarifa_m2, cargos_adicionales, margen)
```

El `LARGE_FORMAT` actual calcula `ancho × alto ÷ 10000` para una medida y puede usarlo como cantidad M2; **no multiplica por piezas**. El nuevo cálculo técnico `DIGITAL_AREA` sí recibe piezas explícitas. No debe activarse en la cotización hasta que el formulario distinga piezas de m² y el cliente confirme redondeo/mínimos. El ancho del rollo no equivale necesariamente al ancho facturable: se necesita una regla de acomodo/corte para calcular merma, o un cargo explícito de desperdicio.

**Pendiente:** unidad de captura, piezas, tarifa/costo por m², tratamiento de tinta y máquina, tamaño de rollo y acomodo, merma, mínimos, redondeo, diseño/preparación y acabados.

## Offset

```
millares = piezas ÷ 1000             [si es proporcional]
millares = techo(piezas ÷ 1000)      [si se cobra millar completo]
factor_offset = múltiplos_de_carta × colores_cobrables × millares
costo_variable = factor_offset × tarifa_variable_confirmada
costo_total = costo_variable + papel + placas + arreglo_de_máquina + diseño + acabados + merma
precio = política_comercial(costo_total, cargos, margen o tarifa_de_venta)
```

La multiplicación original **no incorpora** por sí sola placas ni arreglo. Esos son términos separados, normalmente fijos por trabajo, cara, placa o color según lo que confirme el cliente. El primer millar puede tener precio distinto de los siguientes, pero esa escala no se deduce de la fórmula original. `múltiplos_de_carta` exige una tabla de tamaños y definición de si indica consumo de hoja, imposición o equivalencia comercial. La cantidad de colores debe aclarar frente/reverso y tintas especiales.

**Pendiente:** definición y tabla de múltiplos de carta, colores cobrables, millar proporcional o completo, preparación/placas, tarifa del primer millar y adicionales, papel, merma, acabados y escalas de volumen.

## Promocionales

```
costo_artículos = piezas_adquiridas × costo_artículo_unitario
costo_personalización = suma(cada técnica/posición: piezas_a_personalizar × costo_unitario)
costo_total = costo_artículos + costo_personalización + preparación + diseño + merma + otros
precio = precio_artículos + precio_personalización + cargos_fijos
```

`PIEZA + PERSONALIZACION` se interpreta como **suma de componentes monetarios**, coherente con el análisis nuevo que proporcionó el usuario. Sumar un número de piezas a un número de posiciones no produce dinero ni una unidad de costo útil. El servicio de cálculo devuelve las piezas y posiciones por separado; no inventa importes. La merma promocional de “2–3 %” mencionada por la otra IA **no se incorpora**: falta una política real del cliente.

**Pendiente:** costo/precio de cada artículo, técnica, número de posiciones, cargo por pieza o por pedido, preparación, quién absorbe piezas defectuosas, merma, mínimos y escalas.

## Serigrafía

```
cientos = piezas ÷ 100             [si es proporcional]
cientos = techo(piezas ÷ 100)      [si se cobra ciento completo]
factor_serigrafía = factor_tamaño × cientos × tintas_cobrables
costo_variable = factor_serigrafía × tarifa_variable_confirmada
costo_total = costo_variable + marcos/revelado + registro + diseño + merma + otros
precio = política_comercial(costo_total, cargos, margen o tarifa_de_venta)
```

El tamaño debe ser un factor/tarifa definido por el cliente, no una etiqueta libre. Revelado y registro son cargos distintos del factor `tintas × cientos`; pueden ser por tinta, diseño o trabajo. Debe confirmarse si las tintas cuentan frente/reverso y si hay mínimo de piezas.

**Pendiente:** tabla de tamaños/factores, tintas cobrables, cientos proporcionales o completos, costo/tarifa por factor, marcos/revelado/registro, merma, mínimos y escalas.

## Diseño, preparación, margen y volumen

- **Diseño y preparación:** la cotización actual permite agregar un concepto comercial aparte con precio, pero no tiene un cargo fijo automático enlazado al método. La propuesta es sumar el cargo **una sola vez por trabajo**, o por color/placa/posición cuando la tarifa así lo indique, antes de descuentos. La base y frecuencia quedan pendientes.
- **Margen:** si el cliente define porcentaje de margen bruto `m`, `precio = costo_total ÷ (1 - m)`. Si define recargo sobre costo `r`, `precio = costo_total × (1 + r)`. Son políticas distintas; no se elige ninguna por defecto.
- **Escalas por volumen:** existen `item_price_rules` para precio unitario por cantidad y `volume_discount_rules` para descuento porcentual por categoría. Actualmente el motor de descuentos suma la `quantity` facturable sin aplicar los cuatro métodos de esta matriz. Por ello, las escalas de offset y serigrafía **no están automatizadas correctamente**. Al implementarlas, el umbral deberá indicar explícitamente su base (piezas, m², millares, cientos o factor técnico) y no mezclar unidades.
- **Precio por tramo:** `precio_variable = tramo(cantidad_base) × cantidad_cobrable`; los cargos fijos se suman por separado. Si un tramo aplica sólo a unidades adicionales, la función será progresiva; si aplica a todo el pedido, será escalonada. **Pendiente** confirmar modalidad, umbrales y tarifas.

## Estado de implementación

- `QuotationCostMatrixCalculator` calcula las magnitudes técnicas con decimales exactos y exige explícitamente la política de redondeo de millar/ciento. Para promocionales conserva piezas y posiciones independientes.
- Los perfiles nuevos de la administración se crean como **Pendiente/inactivos**; no activan reglas por volumen sin definición.
- Ninguna tarifa, porcentaje de merma, cargo fijo, margen o escalón fue inventado o sembrado en la base.
- **Pendiente para cotización automática:** captura de los operandos por línea, configuración de tarifas/cargos y políticas, enlace del calculador al `QuotationManager`, previsualización pública/interna, snapshots y validación de unidades. Hasta entonces continúa el precio de catálogo actual; la matriz no lo sustituye.
