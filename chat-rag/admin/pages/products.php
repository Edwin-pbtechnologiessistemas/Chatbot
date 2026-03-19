<div class="wrap">
    <h1>Productos en Base de Datos</h1>
    
    <?php if (empty($products)): ?>
        <div class="notice notice-warning">
            <p>No hay productos importados todavía. <a href="<?php echo admin_url('admin.php?page=chat-rag-import-products'); ?>">Importar productos</a></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Categoría</th>
                    <th>Marca</th>
                    <th>Precio</th>
                    <th>Disponibilidad</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                <tr>
                    <td><?php echo intval($product->id); ?></td>
                    <td><?php echo esc_html($product->product_name); ?></td>
                    <td><?php echo esc_html($product->category); ?></td>
                    <td><?php echo esc_html($product->brand); ?></td>
                    <td><?php echo esc_html($product->price); ?></td>
                    <td><?php echo esc_html($product->availability); ?></td>
                    <td>
                        <a href="#" class="view-product" data-id="<?php echo intval($product->id); ?>">Ver</a> |
                        <a href="#" class="delete-product" data-id="<?php echo intval($product->id); ?>">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('.delete-product').on('click', function(e) {
        e.preventDefault();
        if (confirm('¿Estás seguro de eliminar este producto?')) {
            var id = $(this).data('id');
            // Aquí iría la llamada AJAX para eliminar
            $.post(ajaxurl, {
                action: 'chat_rag_delete_product',
                id: id,
                nonce: chat_rag_admin.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
    });
});
</script>