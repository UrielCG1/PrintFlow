# Matriz de cotización

La hoja `LOGICA` del cliente contiene cuatro operaciones. Para esta versión del cotizador usaremos **una tarifa de venta por unidad de cálculo** en cada línea. Las tarifas y equivalencias que el cliente no ha entregado siguen **Pendientes**.

| Línea | Cantidad para cotizar | Precio de la partida |
|---|---|---|
| Impresión digital | `ancho_cm × alto_cm × piezas ÷ 10000` = m² | `m² × precio_por_m²` |
| Offset | `múltiplos_de_carta × colores × millares` | `resultado × precio_por_múltiplo_color_millar` |
| Promocionales | `piezas` | `piezas × (precio_artículo_por_pieza + precio_personalización_por_pieza)` |
| Serigrafía | `factor_tamaño × cientos × tintas` | `resultado × precio_por_factor_tamaño_ciento_tinta` |

## Qué significa «millar»

Tomamos **un millar = 1,000 piezas**. Por tanto, `millares = piezas ÷ 1000`. Falta confirmar si un pedido que no llega a un millar se cobra proporcionalmente o se redondea al millar completo. El calculador acepta ambas políticas y exige que se indique una antes de cotizar offset. Se aplica la misma pregunta para `cientos = piezas ÷ 100` en serigrafía.

## Datos pendientes

- **Digital:** confirmar que largo y ancho se capturan en centímetros, que «cantidad» son piezas, y el precio por m².
- **Offset:** equivalencia de cada tamaño en múltiplos de carta, cómo se cuentan los colores, tarifa por resultado de la fórmula y cobro de fracciones de millar.
- **Promocionales:** precio por pieza del artículo, precio por pieza de la personalización y confirmar que ambos se suman.
- **Serigrafía:** tabla de tamaño a factor, número de tintas, tarifa por resultado y cobro de fracciones de ciento.

Las tarifas de la tabla son precios de venta al cliente.

## Estado en PrintFlow

`QuotationCostMatrixCalculator` ya calcula estas cantidades y precios cuando recibe todos los datos explícitos. No inventa tarifas. Los perfiles nuevos se guardan pendientes/inactivos. El formulario de cotización aún debe capturar los factores de offset y serigrafía y enlazar este calculador con el precio de la partida. Mientras eso no ocurra, el sistema conserva el precio de catálogo actual. En gran formato, el perfil existente calcula el área de una medida; todavía falta distinguir piezas de m² para aplicar la primera fila de esta matriz.
