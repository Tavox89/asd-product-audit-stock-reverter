TVX Woo Change Log & Safe Inventory Reverter
===========================================

Qué hace
---------
- Registra cambios reales de `stock`, `regular_price` y `yith_cost` cuando exista una meta YITH verificada en runtime.
- Guarda el log en tabla propia con contexto, actor, origen, fingerprint y fecha UTC.
- Añade una página admin paginada dentro de WooCommerce.
- Permite buscar pedidos/facturas en cualquier estado y ejecutar una reversión arbitraria y segura de inventario sin tocar el estado del pedido.
- Audita cada reversión en una tabla propia y deja una sola nota interna final en el pedido.

Cómo se instala
---------------
1. Copia este plugin a `wp-content/plugins/tvx-woo-change-log`.
2. Activa el plugin desde WordPress.
3. Verifica que se hayan creado las tablas:
   - `{$wpdb->prefix}asdl_tvx_wc_change_log`
   - `{$wpdb->prefix}asdl_tvx_wc_reversions`
4. Revisa el menú WooCommerce:
   - `TVX Change Log`
   - `Revertir inventario`

Cómo se prueba
--------------
1. Edita un producto simple o una variación y cambia stock o regular price.
2. Revisa que aparezca la entrada en `WooCommerce > TVX Change Log`.
3. Busca un pedido con `_reduced_stock` en sus líneas y con nota de evidencia de descuento de inventario.
4. Abre `WooCommerce > Revertir inventario`, busca el pedido, revisa el preview y ejecuta la reversión.
5. Confirma:
   - el estado del pedido no cambió
   - el stock del producto sí aumentó
   - `_reduced_stock` quedó limpiado en las líneas restauradas
   - `_order_stock_reduced` quedó desmarcado
   - el pedido recibió una sola nota final de reversión
   - se creó una fila de auditoría en `asdl_tvx_wc_reversions`

Meta YITH detectada
-------------------
- En la inspección local no se encontró instalado un plugin YITH Cost of Goods verificable.
- Por rigor, el plugin no activa ninguna meta YITH por defecto.
- Referencias históricas observadas localmente, pero no habilitadas por no estar verificadas: `yith_cog_cost`, `_yith_cog_cost`.
- Costos no-YITH observados localmente:
  - `_wc_cog_cost`
  - `_wc_cog_cost_variable`
  - `_op_cost_price`
- Si más adelante necesitas activar una meta YITH verificada, usa el filtro `tvx_wcl_yith_cost_meta_keys`.

Estrategia de invoice search usada
----------------------------------
- Se detectaron integraciones locales de numeración secuencial y OpenPOS.
- La búsqueda soporta, en este orden operativo, estas metas:
  - `_invoice_number`
  - `_order_number`
  - `_order_number_formatted`
  - `_op_order_number`
  - `_op_order_number_format`
  - `_openpos_order_number`
  - `_op_wc_custom_order_number`
  - `_op_wc_custom_order_number_formatted`
  - `_op_source_order_number`
  - `_pos_order_no`
  - `_op_receipt_number`
  - `_wpos_order_number`
  - `_billing_document`
- También soporta búsqueda directa por order ID.
- No se encontró una meta específica de número de factura expuesta por WPO/WCPDF dentro de este árbol local.

Hooks y puntos de captura usados
--------------------------------
- `update_post_metadata`
- `add_post_metadata`
- `updated_post_meta`
- `added_post_meta`
- `woocommerce_can_reduce_order_stock`
- `woocommerce_can_restore_order_stock`
- `woocommerce_reduce_order_stock`
- `woocommerce_restore_order_stock`

Decisiones de implementación
----------------------------
- El change log usa hooks de meta relevantes y contexto por request/backtrace para cubrir edición normal, quick edit, bulk edit, REST, import, stock manager, reducción por pedido y reversión manual.
- La reversión arbitraria no usa helpers core que puedan añadir notas extra; restaura stock con `wc_update_product_stock()` y luego limpia flags/metas de forma controlada.
- Se eliminan `_reduced_stock` y, cuando existe, `_op_reduced_stock` por ítem restaurado.
- Para compatibilidad local con tu backup, si el pedido tiene `_csfx_lb_stock_reduced_sync`, se marca `_csfx_lb_stock_reverted`.
- La idempotencia se apoya en lock por pedido + meta interna `_tvx_wcl_arbitrary_reverted_at_utc` + auditoría persistente.

Limitaciones conocidas
----------------------
- CSV export no está implementado en esta primera versión para no contaminar el core.
- Sin una meta YITH verificada localmente, `yith_cost` queda visible como campo lógico pero no generará cambios hasta que se configure el filtro correspondiente.
- La detección de contexto `manual_edit`, `quick_edit`, `bulk_edit`, `stock_manager`, `rest_api` e `import` es robusta pero heurística por request/backtrace, no por un API unificado de WooCommerce.
