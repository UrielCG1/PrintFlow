# Registro de rollos con longitud variable

La lona `LONA-FRONT-13OZ-DEFAULT` llegó del catálogo legado con saldo y consumo en `PZA`. Ese dato no representa los rollos reales y no debe utilizarse para registrar metros. La aplicación bloquea las nuevas entradas de presentaciones marcadas como `legacy_migration` hasta configurar una presentación operativa.

Para la lona, el **objeto físico** es un rollo; el **saldo de cada rollo** se mide en metros lineales; y la **orden de trabajo** consume metros cuadrados. Como hay distintos anchos, cada ancho es una variante distinta. La longitud se mide al recibir cada rollo y puede variar entre dos rollos de la misma variante.

1. Administración abre **Inventario → Configurar anchos**, selecciona el material y captura un ancho real en metros, por ejemplo `1.52`. Repite el proceso por cada ancho que exista en bodega. La variante creada usa `ML` para compra y saldo, `M2` para consumo, y factor `1 ML = ancho en m²`. La unidad de compra se expresa en metros porque los rollos no tienen una longitud fija; el comprobante puede seguir identificando cuántos rollos facturó el proveedor.
2. En **Inventario → Registrar material**, elige el origen: **Acaba de llegar** o **Ya estaba en bodega**. Elige la variante del ancho correcto. El formulario selecciona **Rollo** como objeto físico y muestra `m` junto a la cantidad. Si el rollo mide 20 metros lineales, captura `20`, no `1` ni `20 m²`. Se genera un QR para ese rollo.
3. Si otro rollo del mismo ancho mide 17.5 metros, se registra aparte con `17.5`; recibe otro QR. Un rollo de otro ancho requiere su propia variante.
4. El folio o documento es opcional y solo sirve de referencia. El origen queda grabado como recepción o existencia previa, independientemente del folio. **Rasgos para identificarlo** es opcional; puede describir daño, color o estado, sin repetir ancho ni saldo.

Ejemplo: un rollo de ancho `1.52 m` y longitud `20 m` tiene saldo `20 m` y capacidad teórica `30.4 m²`. La reserva y el consumo convierten los m² de la orden a metros lineales usando el ancho de esa variante. Las órdenes o planes que todavía apunten a `LONA-FRONT-13OZ-DEFAULT` deben reasignarse a la variante del ancho real antes de reservar o consumir material. No se migran automáticamente existencias ni necesidades desde `PZA`, porque falta el ancho que les corresponde.

No se requiere migración SQL para esta pantalla; las dimensiones de la presentación se guardan en las columnas y especificaciones ya existentes. Después de desplegar el código, hay que limpiar la caché de Symfony para que aparezca la nueva ruta.
