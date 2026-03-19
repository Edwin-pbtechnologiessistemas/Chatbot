<?php
// Archivo: admin/pages/import-company-simple.php
// Importador exclusivo para información de empresa

// Verificar permisos
if (!current_user_can('manage_options')) {
    wp_die('No tienes permisos para acceder a esta página.');
}

// Procesar importación si se envió el formulario
$import_result = '';
if (isset($_POST['import_company']) && isset($_FILES['company_file'])) {
    $import_result = process_company_import($_FILES['company_file']);
}

function process_company_import($file) {
    global $wpdb;
    $table = $wpdb->prefix . 'rag_company_info';
    
    // Verificar archivo
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return '<div class="notice notice-error"><p>Error al subir el archivo</p></div>';
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return '<div class="notice notice-error"><p>Solo se permiten archivos CSV</p></div>';
    }
    
    // Leer y procesar CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return '<div class="notice notice-error"><p>No se pudo leer el archivo</p></div>';
    }
    
    // Leer encabezados
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return '<div class="notice notice-error"><p>El archivo no tiene encabezados</p></div>';
    }
    
    // Limpiar encabezados
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
    // Columnas requeridas
    $required = ['info_type', 'title', 'content'];
    $missing = array_diff($required, $headers);
    
    if (!empty($missing)) {
        fclose($handle);
        return '<div class="notice notice-error"><p>Columnas requeridas faltantes: ' . implode(', ', $missing) . '</p></div>';
    }
    
    // Obtener índices
    $col_index = [];
    foreach ($headers as $idx => $name) {
        $col_index[$name] = $idx;
    }
    
    $count = 0;
    $errors = [];
    $row_num = 1;
    
    while (($data = fgetcsv($handle)) !== FALSE) {
        $row_num++;
        
        // Saltar filas vacías
        if (empty(array_filter($data))) {
            continue;
        }
        
        // Extraer datos
        $info_type = isset($data[$col_index['info_type']]) ? trim($data[$col_index['info_type']]) : '';
        $title = isset($data[$col_index['title']]) ? trim($data[$col_index['title']]) : '';
        $content = isset($data[$col_index['content']]) ? trim($data[$col_index['content']]) : '';
        $subcontent = isset($data[$col_index['subcontent']]) ? trim($data[$col_index['subcontent']]) : '';
        $order_index = isset($data[$col_index['order_index']]) ? intval($data[$col_index['order_index']]) : 0;
        $keywords = isset($data[$col_index['keywords']]) ? trim($data[$col_index['keywords']]) : '';
        
        // Validar datos mínimos
        if (empty($info_type) || empty($title) || empty($content)) {
            $errors[] = "Fila $row_num: datos incompletos";
            continue;
        }
        
        // Insertar en BD
        $result = $wpdb->insert($table, [
            'info_type' => $info_type,
            'title' => $title,
            'content' => $content,
            'subcontent' => $subcontent,
            'order_index' => $order_index,
            'keywords' => $keywords
        ]);
        
        if ($result) {
            $count++;
        } else {
            $errors[] = "Fila $row_num: error BD - " . $wpdb->last_error;
        }
    }
    
    fclose($handle);
    
    if ($count > 0) {
        $message = "<div class='notice notice-success'><p>✅ Se importaron $count registros de empresa correctamente</p>";
        if (!empty($errors)) {
            $message .= "<p>⚠️ Errores: " . implode('<br>', array_slice($errors, 0, 5)) . "</p>";
        }
        $message .= "</div>";
        return $message;
    } else {
        return "<div class='notice notice-error'><p>No se importó ningún registro: " . implode(', ', $errors) . "</p></div>";
    }
}
?>

<div class="wrap">
    <h1>📋 Importar Información de Empresa</h1>
    
    <?php echo $import_result; ?>
    
    <div class="card" style="max-width: 800px; padding: 20px; background: white; border-radius: 10px; margin-top: 20px;">
        <h2>Subir archivo CSV</h2>
        
        <div style="background: #f0f6fc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3>📌 Columnas requeridas:</h3>
            <p style="font-family: monospace; font-size: 16px; background: white; padding: 10px; border-radius: 5px;">
                info_type, title, content, subcontent, order_index, keywords
            </p>
            
            <h3>📋 Tipos disponibles:</h3>
            <p>
                <strong>empresa</strong> - Información general de la empresa<br>
                <strong>mision</strong> - Misión y visión<br>
                <strong>servicios</strong> - Servicios ofrecidos<br>
                <strong>marcas</strong> - Marcas representadas<br>
                <strong>contacto</strong> - Información de contacto<br>
                <strong>ubicacion</strong> - Dirección y ubicación<br>
                <strong>horario</strong> - Horarios de atención<br>
                <strong>redes_sociales</strong> - Redes sociales<br>
                <strong>testimonios</strong> - Testimonios de clientes<br>
                <strong>novedades</strong> - Novedades y promociones<br>
                <strong>valores</strong> - Valores de la empresa<br>
                <strong>politicas</strong> - Políticas de envío y garantía
            </p>
            
            <h3>📝 Ejemplo:</h3>
            <pre style="background: white; padding: 10px; border-radius: 5px; overflow-x: auto;">
info_type,title,content,subcontent,order_index,keywords
empresa,PBTechnologies S.R.L.,"Descripción de la empresa","Información adicional",1,"empresa, pbtechnologies"
mision,Nuestra Misión,"Proveer instrumentos de medición precisos",,2,"mision, valores"
contacto,Información de Contacto,"Teléfono: 123456","WhatsApp: 789012",3,"contacto, telefono"
            </pre>
            
            <p style="color: #666;">
                <strong>⚠️ Nota:</strong> Si el contenido tiene comas, debe ir entre comillas dobles.
            </p>
        </div>
        
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="import_company" value="1">
            
            <label for="company_file" style="font-weight: bold;">Seleccionar archivo CSV:</label>
            <input type="file" name="company_file" id="company_file" accept=".csv" required 
                   style="display: block; margin: 10px 0; padding: 10px; border: 2px dashed #ccc; width: 100%;">
            
            <p style="color: #666; margin: 5px 0;">
                <small>El archivo debe estar en formato CSV UTF-8</small>
            </p>
            
            <button type="submit" class="button button-primary" style="padding: 10px 30px; font-size: 16px; margin-top: 10px;">
                Importar Información
            </button>
        </form>
        
        <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-radius: 5px;">
            <h3>📊 Ver registros existentes</h3>
            <a href="<?php echo admin_url('admin.php?page=chat-rag-company'); ?>" class="button">
                Ver Información de Empresa
            </a>
        </div>
    </div>
</div>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
pre {
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>