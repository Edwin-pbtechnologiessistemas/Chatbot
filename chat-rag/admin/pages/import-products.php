<div class="wrap">
    <h1>Importar Productos</h1>
    
    <div class="import-container" style="background: white; padding: 30px; border-radius: 10px; max-width: 800px;">
        <h2>📤 Subir archivo CSV</h2>
        
        <div class="format-info" style="background: #f0f6fc; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0;">📋 Tus columnas exactas:</h3>
            
            <div style="background: #fff; padding: 15px; border-radius: 5px; font-family: monospace;">
                product_name | category | subcategory | brand | short_description | long_description | specifications | price | product_url
            </div>
            
            <h3>📌 Ejemplo:</h3>
            <div style="background: #fff; padding: 15px; border-radius: 5px; overflow-x: auto;">
                Taladro Percusor TROTEC PHDS 10-230V,Herramientas Eléctricas,Taladros Percusores,TROTEC,"Taladro percutor profesional de 620W","Equipo robusto y potente","Potencia: 620 W; Velocidad: 0-3000 rpm; Peso: 2.1 kg",450 Bs,https://pbt.com.bo/producto/ejemplo
            </div>
            
            <h3>⚠️ Importante:</h3>
            <ul style="margin-bottom: 0;">
                <li>✅ Guarda tu Excel como <strong>CSV UTF-8</strong> (delimitado por comas)</li>
                <li>✅ La primera fila debe ser los nombres de las columnas</li>
                <li>✅ Las keywords se generan automáticamente</li>
                <li>❌ No incluyas columna "availability"</li>
                <li>❌ No incluyas columna "keywords"</li>
            </ul>
        </div>
        
        <form id="import-products-form" enctype="multipart/form-data">
            <input type="file" id="product-file" name="file" accept=".csv" required 
                   style="margin-bottom: 20px; padding: 10px; border: 2px dashed #ccc; width: 100%;">
            
            <button type="submit" class="button button-primary" style="padding: 15px 30px; font-size: 16px;">
                Importar Productos
            </button>
        </form>
        
        <div id="import-progress" style="display:none; margin-top: 20px;">
            <div style="background: #f0f0f0; height: 4px; border-radius: 2px;">
                <div class="progress-fill" style="width: 0%; height: 100%; background: #da291c; border-radius: 2px;"></div>
            </div>
            <p class="progress-text" style="text-align: center;">Procesando...</p>
        </div>
        
        <div id="import-result" style="margin-top: 20px;"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#import-products-form').on('submit', function(e) {
        e.preventDefault();
        
        var file = $('#product-file')[0].files[0];
        if (!file) {
            alert('Por favor selecciona un archivo');
            return;
        }
        
        // Verificar que sea CSV
        if (!file.name.toLowerCase().endsWith('.csv')) {
            alert('Solo se permiten archivos CSV. Guarda tu Excel como CSV.');
            return;
        }
        
        $('#import-progress').show();
        $('.progress-fill').css('width', '0%');
        $('.progress-text').text('Subiendo archivo...');
        
        var formData = new FormData();
        formData.append('action', 'chat_rag_import_products');
        formData.append('file', file);
        formData.append('nonce', chat_rag_admin.nonce);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        $('.progress-fill').css('width', percent + '%');
                        $('.progress-text').text('Subiendo: ' + percent + '%');
                    }
                });
                return xhr;
            },
            success: function(response) {
                $('.progress-fill').css('width', '100%');
                $('.progress-text').text('Procesando...');
                
                setTimeout(function() {
                    $('#import-progress').hide();
                    if (response.success) {
                        $('#import-result').html('<div class="notice notice-success"><p>' + response.data.replace(/\n/g, '<br>') + '</p></div>');
                    } else {
                        $('#import-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                }, 500);
            },
            error: function() {
                $('#import-progress').hide();
                $('#import-result').html('<div class="notice notice-error"><p>Error en la conexión</p></div>');
            }
        });
    });
});
</script>