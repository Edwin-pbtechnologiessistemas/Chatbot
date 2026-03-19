<div class="wrap">
    <h1>Importar Información de Empresa</h1>
    
    <div class="import-container" style="background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin-top: 20px;">
        <h2>Subir archivo CSV de información empresarial</h2>
        
        <div class="format-info" style="background: #f0f6fc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3>📌 Formato requerido:</h3>
            <p><strong>Columnas:</strong> info_type, title, content, subcontent, order_index, keywords</p>
            <p><strong>Tipos disponibles:</strong> empresa, ubicacion, contacto, horario, servicios, mision, marcas</p>
            <p><strong>Ejemplo:</strong></p>
            <pre style="background: #fff; padding: 10px; border-radius: 5px;">empresa,Empresa,Nuestra empresa...,,0,empresa nosotros
contacto,Teléfonos,WhatsApp: +591...,,0,contacto telefono
ubicacion,Dirección,Av. Principal...,,0,ubicacion direccion</pre>
        </div>
        
        <form id="import-company-form">
            <input type="file" id="company-file" accept=".csv" required style="margin-bottom: 20px;">
            <button type="submit" class="button button-primary" id="import-btn">Importar Información</button>
        </form>
        
        <div id="import-progress" style="display:none; margin-top: 20px;">
            <div class="progress-bar" style="width: 100%; height: 4px; background: #f0f0f0; border-radius: 2px;">
                <div class="progress-fill" style="width: 0%; height: 100%; background: #da291c; border-radius: 2px;"></div>
            </div>
            <p class="progress-text" style="text-align: center;">Procesando...</p>
        </div>
        
        <div id="import-result" style="margin-top: 20px;"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#import-company-form').on('submit', function(e) {
        e.preventDefault();
        
        var file = $('#company-file')[0].files[0];
        if (!file) {
            alert('Por favor selecciona un archivo');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'chat_rag_import_company');
        formData.append('file', file);
        
        $('#import-progress').show();
        $('#import-result').html('');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $('#import-progress').hide();
                if (response.success) {
                    $('#import-result').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                } else {
                    $('#import-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            },
            error: function() {
                $('#import-progress').hide();
                $('#import-result').html('<div class="notice notice-error"><p>Error en la importación</p></div>');
            }
        });
    });
});
</script>