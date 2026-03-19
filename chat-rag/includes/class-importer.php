<?php
/**
 * ChatRAG Importer Class - Con soporte UTF-8 garantizado
 */

class ChatRAG_Importer {
    
    private $database;
    private $embeddings;
    
    public function __construct($database, $embeddings) {
        $this->database = $database;
        $this->embeddings = $embeddings;
        
        add_action('wp_ajax_chat_rag_import_products', [$this, 'importProducts']);
    }
    
    public function importProducts() {
        // Verificar nonce
        if (!check_ajax_referer('chat_rag_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Nonce inválido');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos');
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error('No se recibió archivo');
        }
        
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            wp_send_json_error('Por favor, sube un archivo CSV');
        }
        
        $result = $this->processProductsCSV($file['tmp_name']);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    private function processProductsCSV($filepath) {
        // Leer el archivo completo
        $content = file_get_contents($filepath);
        if ($content === false) {
            return ['success' => false, 'message' => 'No se pudo leer el archivo'];
        }
        
        // Detectar y convertir a UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // Eliminar BOM si existe
        $bom = pack('H*','EFBBBF');
        $content = preg_replace("/^$bom/", '', $content);
        
        // Guardar en archivo temporal
        $temp_file = tempnam(sys_get_temp_dir(), 'utf8_');
        file_put_contents($temp_file, $content);
        
        // Procesar el archivo
        $handle = fopen($temp_file, 'r');
        if (!$handle) {
            unlink($temp_file);
            return ['success' => false, 'message' => 'No se pudo procesar el archivo'];
        }
        
        // Detectar delimitador
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = $this->detectDelimiter($first_line);
        
        // Leer encabezados
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            unlink($temp_file);
            return ['success' => false, 'message' => 'El archivo CSV no tiene encabezados'];
        }
        
        // Limpiar encabezados
        $headers = array_map(function($h) {
            return trim(strtolower($h));
        }, $headers);
        
        // Columnas esperadas
        $expected = ['product_name', 'category', 'subcategory', 'brand', 'short_description', 'long_description', 'specifications', 'price', 'product_url'];
        
        // Mapear columnas
        $column_index = [];
        foreach ($expected as $field) {
            $index = array_search($field, $headers);
            if ($index !== false) {
                $column_index[$field] = $index;
            }
        }
        
        // Verificar que tenemos al menos las columnas mínimas
        $required = ['product_name', 'category', 'brand'];
        $missing = array_diff($required, array_keys($column_index));
        
        if (!empty($missing)) {
            fclose($handle);
            unlink($temp_file);
            return ['success' => false, 'message' => 'Columnas requeridas faltantes: ' . implode(', ', $missing)];
        }
        
        $count = 0;
        $errors = [];
        $row_number = 1;
        
        // Procesar datos
        while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
            $row_number++;
            
            if (empty(array_filter($data))) {
                continue;
            }
            
            // Extraer datos con UTF-8 garantizado
            $row = [];
            foreach ($column_index as $field => $index) {
                if (isset($data[$index])) {
                    // Forzar UTF-8
                    $value = trim($data[$index]);
                    $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                    $row[$field] = $value;
                } else {
                    $row[$field] = '';
                }
            }
            
            // Validar
            if (empty($row['product_name'])) {
                $errors[] = "Fila $row_number: nombre vacío";
                continue;
            }
            
            // Debug: registrar para verificar acentos
            error_log("Importando: " . $row['product_name']);
            
            // Generar keywords
            $keywords = $this->generateProductKeywords($row);
            
            // Insertar
            $data_to_insert = [
                'product_name' => $row['product_name'],
                'category' => $row['category'] ?? '',
                'subcategory' => $row['subcategory'] ?? '',
                'brand' => $row['brand'] ?? '',
                'short_description' => $row['short_description'] ?? '',
                'long_description' => $row['long_description'] ?? '',
                'specifications' => $row['specifications'] ?? '',
                'price' => $row['price'] ?? 'Consultar',
                'availability' => null,
                'product_url' => $row['product_url'] ?? '',
                'keywords' => $keywords
            ];
            
            if ($this->database->insertProduct($data_to_insert)) {
                $count++;
            } else {
                global $wpdb;
                $errors[] = "Fila $row_number: error DB - " . $wpdb->last_error;
            }
        }
        
        fclose($handle);
        unlink($temp_file);
        
        if ($count > 0) {
            $message = "✅ ¡Importación exitosa! Se importaron $count productos.\n\n";
            $message .= "📊 Ejemplo del primer producto:\n";
            $message .= "   • " . $row['product_name'] . "\n";
            
            if (!empty($errors)) {
                $message .= "\n⚠️ Errores:\n" . implode("\n", array_slice($errors, 0, 5));
            }
            
            return ['success' => true, 'message' => $message];
        } else {
            return ['success' => false, 'message' => 'No se importaron productos: ' . implode(', ', $errors)];
        }
    }
    
    private function detectDelimiter($line) {
        $delimiters = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
            '|' => substr_count($line, '|')
        ];
        
        $max = 0;
        $best_delimiter = ',';
        
        foreach ($delimiters as $delimiter => $count) {
            if ($count > $max) {
                $max = $count;
                $best_delimiter = $delimiter;
            }
        }
        
        return $best_delimiter;
    }
    
    private function generateProductKeywords($row) {
    $keywords = [];
    
    // Función para normalizar texto (quitar acentos)
    $normalize = function($text) {
        $text = strtolower($text);
        $unwanted = array('á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ');
        $wanted   = array('a','e','i','o','u','u','n','A','E','I','O','U','U','N');
        return str_replace($unwanted, $wanted, $text);
    };
    
    // 1. NOMBRE DEL PRODUCTO - Versión original y normalizada
    $name_original = strtolower($row['product_name']);
    $name_normalized = $normalize($name_original);
    
    // Agregar palabras del nombre original
    $name_parts = explode(' ', $name_original);
    $keywords = array_merge($keywords, $name_parts);
    
    // Agregar palabras del nombre normalizado (sin tildes)
    $name_parts_normalized = explode(' ', $name_normalized);
    $keywords = array_merge($keywords, $name_parts_normalized);
    
    // 2. CATEGORÍA - Original y normalizada
    if (!empty($row['category'])) {
        $cat_original = strtolower($row['category']);
        $cat_normalized = $normalize($cat_original);
        
        $keywords[] = $cat_original;
        $keywords[] = $cat_normalized;
        
        $cat_parts = explode(' ', $cat_original);
        $keywords = array_merge($keywords, $cat_parts);
    }
    
    // 3. SUBCATEGORÍA - Original y normalizada
    if (!empty($row['subcategory'])) {
        $sub_original = strtolower($row['subcategory']);
        $sub_normalized = $normalize($sub_original);
        
        $keywords[] = $sub_original;
        $keywords[] = $sub_normalized;
    }
    
    // 4. MARCA - Original y normalizada
    if (!empty($row['brand'])) {
        $brand_original = strtolower($row['brand']);
        $brand_normalized = $normalize($brand_original);
        
        $keywords[] = $brand_original;
        $keywords[] = $brand_normalized;
    }
    
    // 5. ESPECIFICACIONES - Extraer palabras clave y números
    if (!empty($row['specifications'])) {
        $specs = $row['specifications'];
        
        // Extraer números (siempre útiles para búsqueda)
        preg_match_all('/\d+/', $specs, $numbers);
        foreach ($numbers[0] as $num) {
            $keywords[] = $num;
        }
        
        // Extraer términos técnicos comunes
        $tech_terms = ['w', 'kw', 'hp', 'rpm', 'kg', 'mm', 'cm', 'm', 'v', 'a', 'sds', 'plus', 'litro', 'ml'];
        $specs_lower = strtolower($specs);
        foreach ($tech_terms as $term) {
            if (strpos($specs_lower, $term) !== false) {
                $keywords[] = $term;
            }
        }
    }
    
    // 6. PALABRAS CLAVE ESPECÍFICAS PARA PRODUCTOS COMUNES
    $common_terms = [
        'alcoholimetro' => ['alcoholimetro', 'alcoholímetro', 'alcohol', 'etilometro'],
        'termografica' => ['termografica', 'termográfica', 'camara termica', 'infrarrojo'],
        'analizador' => ['analizador', 'tester', 'medidor', 'comprobador'],
        'rigel' => ['rigel', 'medical', 'biomedico'],
        'trotec' => ['trotec', 'herramientas', 'electricas']
    ];
    
    // Detectar si el producto coincide con algún término común
    $full_text = strtolower($row['product_name'] . ' ' . ($row['category'] ?? '') . ' ' . ($row['brand'] ?? ''));
    foreach ($common_terms as $key => $terms) {
        foreach ($terms as $term) {
            if (strpos($full_text, $term) !== false) {
                $keywords = array_merge($keywords, $terms);
                break;
            }
        }
    }
    
    // Limpiar y filtrar
    $keywords = array_map('trim', $keywords);
    $keywords = array_filter($keywords, function($word) {
        // Quitar caracteres no alfanuméricos
        $word = preg_replace('/[^a-z0-9]/', '', $word);
        return strlen($word) > 1;
    });
    
    // Quitar duplicados
    $keywords = array_unique($keywords);
    
    // Ordenar y limitar
    sort($keywords);
    $keywords = array_slice($keywords, 0, 40);
    
    return implode(', ', $keywords);
}

public function importCompany() {
    // Verificar nonce
    if (!check_ajax_referer('chat_rag_admin_nonce', 'nonce', false)) {
        wp_send_json_error('Nonce inválido');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos');
    }
    
    if (empty($_FILES['file'])) {
        wp_send_json_error('No se recibió archivo');
    }
    
    $file = $_FILES['file'];
    
    // DEPURACIÓN: Registrar información del archivo
    error_log('=== INICIO IMPORTACIÓN EMPRESA ===');
    error_log('Nombre archivo: ' . $file['name']);
    error_log('Tamaño: ' . $file['size']);
    error_log('Error: ' . $file['error']);
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    error_log('Extensión: ' . $ext);
    
    if ($ext !== 'csv') {
        error_log('ERROR: No es CSV');
        wp_send_json_error('Por favor sube un archivo CSV');
    }
    
    // Leer primeras líneas del archivo
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle) {
        $linea1 = fgets($handle);
        $linea2 = fgets($handle);
        fclose($handle);
        error_log('Primera línea (encabezados): ' . $linea1);
        error_log('Segunda línea (primer dato): ' . $linea2);
    }
    
    $result = $this->processCompanyCSV($file['tmp_name']);
    
    error_log('Resultado importación: ' . print_r($result, true));
    error_log('=== FIN IMPORTACIÓN EMPRESA ===');
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

private function processCompanyCSV($filepath) {
    // Leer el archivo completo y convertir a UTF-8
    $content = file_get_contents($filepath);
    if ($content === false) {
        return ['success' => false, 'message' => 'No se pudo leer el archivo'];
    }
    
    // Detectar y convertir a UTF-8
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    
    // Eliminar BOM si existe
    $bom = pack('H*','EFBBBF');
    $content = preg_replace("/^$bom/", '', $content);
    
    // Guardar en archivo temporal
    $temp_file = tempnam(sys_get_temp_dir(), 'company_');
    file_put_contents($temp_file, $content);
    
    // Procesar el archivo
    $handle = fopen($temp_file, 'r');
    if (!$handle) {
        unlink($temp_file);
        return ['success' => false, 'message' => 'No se pudo procesar el archivo'];
    }
    
    // Detectar delimitador
    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = $this->detectDelimiter($first_line);
    
    // Leer encabezados
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        unlink($temp_file);
        return ['success' => false, 'message' => 'El archivo CSV no tiene encabezados'];
    }
    
    // Limpiar encabezados
    $headers = array_map(function($h) {
        return trim(strtolower($h));
    }, $headers);
    
    // Columnas esperadas
    $expected = ['info_type', 'title', 'content', 'subcontent', 'order_index', 'keywords'];
    
    // Verificar que tenemos todas las columnas
    $missing = array_diff($expected, $headers);
    if (!empty($missing)) {
        fclose($handle);
        unlink($temp_file);
        return ['success' => false, 'message' => 'Columnas faltantes: ' . implode(', ', $missing)];
    }
    
    // Obtener índices de columnas
    $col_index = [];
    foreach ($expected as $field) {
        $col_index[$field] = array_search($field, $headers);
    }
    
    $count = 0;
    $errors = [];
    $row_number = 1;
    
    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $row_number++;
        
        // Saltar filas vacías
        if (empty(array_filter($data))) {
            continue;
        }
        
        // Extraer datos
        $row = [];
        foreach ($col_index as $field => $index) {
            if (isset($data[$index])) {
                $row[$field] = trim($data[$index]);
            } else {
                $row[$field] = '';
            }
        }
        
        // Validar datos mínimos
        if (empty($row['info_type']) || empty($row['title']) || empty($row['content'])) {
            $errors[] = "Fila $row_number: faltan datos requeridos (info_type, title, content)";
            continue;
        }
        
        // Preparar datos para insertar
        $data_to_insert = [
            'info_type' => $row['info_type'],
            'title' => $row['title'],
            'content' => $row['content'],
            'subcontent' => $row['subcontent'] ?? '',
            'order_index' => intval($row['order_index'] ?? 0),
            'keywords' => $row['keywords'] ?? ''
        ];
        
        if ($this->database->insertCompanyInfo($data_to_insert)) {
            $count++;
        } else {
            global $wpdb;
            $errors[] = "Fila $row_number: error DB - " . $wpdb->last_error;
        }
    }
    
    fclose($handle);
    unlink($temp_file);
    
    if ($count > 0) {
        $message = "✅ Se importaron $count registros de empresa correctamente";
        if (!empty($errors)) {
            $message .= "\n\n⚠️ Errores:\n" . implode("\n", array_slice($errors, 0, 5));
        }
        return ['success' => true, 'message' => $message];
    } else {
        return ['success' => false, 'message' => 'No se pudo importar ningún registro: ' . implode(', ', $errors)];
    }
}
}