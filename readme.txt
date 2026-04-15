ASD Labs Product Audit & Stock Reverter
======================================

Qué hace
---------
- Mantiene una auditoría canónica en tablas propias para `stock`, `regular_price`, `yith_cost` y eventos de `arbitrary_revert`.
- Registra actor, usuario, before/after, delta, source/context, metadata útil, source system e indicadores de import/bridge.
- Añade compatibilidad por capas con Stock Manager for WooCommerce:
  - `native_only`
  - `stock_manager_import_only`
  - `stock_manager_bridge`
- Importa historial legado del stock log de Stock Manager en lotes y sin destruir datos.
- Endurece la reversión arbitraria por pedido con lock, idempotencia, estado `success|partial|blocked`, una sola nota final y sin cambiar el estado del pedido.
- Añade tres vistas admin bajo WooCommerce:
  - `ASD Labs Audit Log`
  - `Revertir inventario`
  - `Compatibilidad Stock Manager`

Cómo se instala
---------------
1. Copia este plugin a tu directorio de plugins de WordPress.
2. Actívalo desde WordPress.
3. Verifica la creación/upgrade de tablas propias:
   - `{$wpdb->prefix}asdl_tvx_wc_change_log`
   - `{$wpdb->prefix}asdl_tvx_wc_reversions`
4. Entra a `WooCommerce > Compatibilidad Stock Manager` para confirmar detección, modo y, si aplica, lanzar la importación histórica.
5. Nota de compatibilidad: el slug técnico, el archivo principal y el text-domain internos siguen usando `tvx-woo-change-log` por compatibilidad retroactiva, aunque la marca visible del plugin sea ASD Labs.

Cómo se prueba
--------------
1. Cambia stock desde edición normal, quick edit, bulk edit o una ruta que termine en `wc_update_product_stock()`.
2. Revisa `WooCommerce > ASD Labs Audit Log`.
3. Si Stock Manager está presente, valida su detección y ejecuta un lote de importación desde `WooCommerce > Compatibilidad Stock Manager`.
4. Busca un pedido con nota de descuento de inventario y `_reduced_stock` positivo en sus líneas.
5. Abre `WooCommerce > Revertir inventario`, revisa el preview y ejecuta la reversión.
6. Confirma:
   - el estado del pedido no cambió
   - solo se restauró stock donde `_reduced_stock > 0`
   - en una reversión parcial no se limpió el flag global ni se marcó como éxito total
   - en una reversión completa se limpió `_order_stock_reduced`
   - el pedido recibió una sola nota final
   - se registró auditoría en `asdl_tvx_wc_reversions`

Meta YITH detectada
-------------------
- En la inspección local no se encontró un plugin YITH Cost of Goods verificable.
- Por rigor, el plugin no activa ninguna meta YITH por defecto.
- Queda disponible configuración por filtro:
  - `asdl_tvx_wc_yith_cost_meta_keys`
  - `tvx_wcl_yith_cost_meta_keys`
- Referencias observadas localmente pero no habilitadas automáticamente:
  - `yith_cog_cost`
  - `_yith_cog_cost`
- Costos no-YITH observados localmente:
  - `_wc_cog_cost`
  - `_wc_cog_cost_variable`
  - `_op_cost_price`

Estrategia de invoice search usada
----------------------------------
- La búsqueda prioriza order ID y order number nativo de WooCommerce.
- Se encapsularon metas observadas localmente para OpenPOS y numeración operativa:
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
- La UI usa la URL oficial de edición del pedido cuando WooCommerce la expone.

Compatibilidad Stock Manager
----------------------------
- Plugin local detectado en la inspección: `Stock Manager for WooCommerce`.
- Tabla histórica detectada localmente: `{$wpdb->prefix}stock_log`.
- Columnas clave detectadas: `ID`, `date_created`, `product_id`, `qty`.
- Hooks relevantes observados en su código local:
  - `woocommerce_product_set_stock`
  - `woocommerce_variation_set_stock`
  - callback `save_stock`
- Estrategia implementada:
  - ASD Labs sigue siendo la fuente canónica enriquecida.
  - El historial legado puede importarse por lotes a la tabla ASD.
  - El bridge continuo se limita a stock y solo se intenta cuando el esquema fue validado.
  - No se alteran ni borran tablas del plugin tercero.

Hooks y puntos de captura usados
--------------------------------
- Logging de stock real:
  - `woocommerce_product_before_set_stock`
  - `woocommerce_variation_before_set_stock`
  - `woocommerce_product_set_stock`
  - `woocommerce_variation_set_stock`
- Fallback por meta:
  - `update_post_metadata`
  - `add_post_metadata`
  - `updated_post_meta`
  - `added_post_meta`
- Contexto de pedidos:
  - `woocommerce_can_reduce_order_stock`
  - `woocommerce_can_restore_order_stock`
  - `woocommerce_reduce_order_stock`
  - `woocommerce_restore_order_stock`

Decisiones de implementación
----------------------------
- Se preservó la arquitectura modular existente; no se rehizo el plugin desde cero.
- Se mantuvo namespace/prefijo interno actual por bajo riesgo, pero el branding visible pasó a ASD Labs.
- La auditoría enriquecida vive solo en tablas ASD Labs; la compatibilidad con Stock Manager es una capa aparte.
- La importación histórica usa batches, checkpoint por opción, `source_system = stock_manager` y `source_context = stock_manager_legacy_import`.
- El bridge continuo no intenta empujar `regular_price` ni `yith_cost` al historial externo porque ese esquema es claramente de stock.
- El bridge continuo también se resuelve desde stock hooks nativos: si Stock Manager ya cubre ese mismo hook, se marca como puenteado sin duplicar inserciones torpes; si no lo cubre y el bridge es seguro, se inserta vía adapter.
- La reversión arbitraria valida el resultado real del aumento de stock antes de limpiar `_reduced_stock`.

Limitaciones conocidas
----------------------
- No hay CSV export en esta iteración.
- Si una operación externa modifica `_stock` solo por meta y además genera su propio historial paralelo no observable, el bridge evita duplicación torpe por ruta/contexto, pero no puede demostrar equivalencia perfecta de sistemas ajenos sin claves de correlación externas.
- Sin una meta YITH verificada, `yith_cost` sigue degradado de forma elegante.
- La validación por notas de pedido sigue siendo configurable por patrones y depende del workflow local de notas.
