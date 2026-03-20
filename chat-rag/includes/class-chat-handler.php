<?php
class ChatRAG_Chat_Handler {
    
    private static $instance = null;
    private $database;
    private $embeddings;
    
    public function __construct($database, $embeddings) {
        $this->database = $database;
        $this->embeddings = $embeddings;
        
        add_action('wp_ajax_chat_rag_query', [$this, 'handleQuery']);
        add_action('wp_ajax_nopriv_chat_rag_query', [$this, 'handleQuery']);
    }
    
    public function handleQuery() {
        if (!wp_verify_nonce($_POST['nonce'], 'chat_rag_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        $question = sanitize_text_field($_POST['question']);
        
        if (empty($question)) {
            wp_send_json_error('Pregunta vacía');
        }
        
        $answer = $this->findBestAnswer($question);
        wp_send_json_success($answer);
    }
    
    private function findBestAnswer($question) {
    global $wpdb;
    $question_lower = strtolower($question);
    $question_normalized = $this->normalizeText($question_lower);
    
    // =============================================
    // PASO 1: DETECTAR SI QUIERE PRODUCTOS DE UNA MARCA
    // =============================================
    if (preg_match('/productos? (de|de la) ([a-zA-Z]+)/i', $question, $matches) || 
        preg_match('/dame (productos? )?de ([a-zA-Z]+)/i', $question, $matches) ||
        preg_match('/que productos? tienen? (de )?([a-zA-Z]+)/i', $question, $matches)) {
        
        $marca = isset($matches[2]) ? $matches[2] : (isset($matches[1]) ? $matches[1] : '');
        $marca = $this->normalizeText($marca);
        
        // Buscar productos de esa marca
        $productos_marca = $this->searchProductsByBrand($marca);
        
        if (!empty($productos_marca)) {
            return $this->formatProductList($productos_marca, "Productos de " . strtoupper($marca));
        }
    }
    
    // =============================================
    // PASO 2: DETECTAR SI QUIERE PRODUCTOS ALEATORIOS
    // =============================================
    if (preg_match('/dame (otros |algunos |)(productos?|opciones)/i', $question) ||
        preg_match('/muéstrame (otros |algunos |)(productos?|opciones)/i', $question) ||
        strpos($question_normalized, 'otros productos') !== false) {
        
        $productos_aleatorios = $this->getRandomProducts(8);
        return $this->formatProductList($productos_aleatorios, "Algunos productos que pueden interesarte");
    }
    
    // =============================================
    // PASO 3: VERIFICAR SI ES PREGUNTA DE PRODUCTO
    // =============================================
    $es_producto = $this->esPreguntaDeProducto($question);
    
    if ($es_producto) {
        $product_results = $this->searchProductsIntelligent($question);
        
        if (!empty($product_results)) {
            $exact_match = $this->findExactProductMatch($product_results, $question);
            
            if ($exact_match) {
                if (preg_match('/\d+[a-zA-Z]?[-]?\d*[a-zA-Z]?/', $question)) {
                    return $this->formatSingleProduct($exact_match);
                }
            }
            
            return $this->formatProductResponse($product_results, $question);
        }
    }
    
    // =============================================
    // PASO 4: DETECTAR INTENCIÓN DE EMPRESA
    // =============================================
    $intent = $this->detectIntent($question_lower);
    
    if (in_array($intent, ['contacto', 'ubicacion', 'horario', 'empresa', 'servicios', 'politicas'])) {
        $company_results = $this->searchCompanyIntelligent($question, $intent);
        if (!empty($company_results)) {
            return $this->formatCompanyResponse($company_results, $question);
        }
    }
    
    // =============================================
    // PASO 5: CASO ESPECIAL PARA MARCAS (solo si no es búsqueda de productos)
    // =============================================
    if ($intent === 'marcas' && !$es_producto) {
        $company_results = $this->searchCompanyIntelligent('marcas', 'marcas');
        if (!empty($company_results)) {
            return $this->formatCompanyResponse($company_results, $question);
        }
    }
    
    // =============================================
    // PASO 6: BÚSQUEDA GENERAL DE PRODUCTOS
    // =============================================
    $product_results = $this->searchProductsIntelligent($question);
    if (!empty($product_results)) {
        return $this->formatProductResponse($product_results, $question);
    }
    
    // =============================================
    // PASO 7: FALLBACK CON PRODUCTOS ALEATORIOS
    // =============================================
    $productos_aleatorios = $this->getRandomProducts(5);
    $response = "🤔 No encontré resultados exactos para \"$question\"\n\n";
    $response .= "**Mientras tanto, te muestro algunos productos que pueden interesarte:**\n\n";
    $response .= $this->formatSimpleProductList($productos_aleatorios);
    
    return $response;
}

private function searchProductsByBrand($marca) {
    global $wpdb;
    $tables = $this->database->getTables();
    
    $marca_normalized = $this->normalizeText($marca);
    
    // Buscar en la columna brand
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$tables['products']} 
         WHERE LOWER(brand) LIKE %s 
         ORDER BY product_name ASC",
        '%' . $wpdb->esc_like($marca_normalized) . '%'
    ));
    
    // Si no encuentra, buscar en keywords también
    if (empty($results)) {
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['products']} 
             WHERE LOWER(keywords) LIKE %s 
             ORDER BY product_name ASC",
            '%' . $wpdb->esc_like($marca_normalized) . '%'
        ));
    }
    
    return $results;
}

/**
 * Obtener productos aleatorios
 */
private function getRandomProducts($limit = 8) {
    global $wpdb;
    $tables = $this->database->getTables();
    
    return $wpdb->get_results(
        "SELECT * FROM {$tables['products']} 
         ORDER BY RAND() 
         LIMIT $limit"
    );
}

/**
 * Formato simple para lista de productos (sin descripción larga)
 */
private function formatSimpleProductList($products) {
    $response = "";
    $count = 0;
    
    foreach ($products as $p) {
        if ($count >= 5) break;
        
        $response .= "• **{$p->product_name}**\n";
        $response .= "  {$p->short_description}\n";
        $response .= "  ⚡ Marca: {$p->brand}";
        if (!empty($p->price) && $p->price != 'Consultar') {
            $response .= " | 💰 {$p->price}";
        }
        $response .= "\n\n";
        $count++;
    }
    
    return $response;
}

/**
 * Detectar si es una consulta de producto específico
 */
private function isSpecificProductQuery($question) {
    $question_lower = strtolower($question);
    
    // Patrones que indican búsqueda de producto específico
    $product_patterns = [
        'cámara', 'camara', 'termográfica', 'termografica', 'fotric',
        'taladro', 'perforador', 'percutor', 'batería', 'bateria',
        'alcoholímetro', 'alcoholimetro', 'intoxilyzer',
        'hobo', 'mx1101', 'sonel', 'trotec', 'rigel'
    ];
    
    // Si tiene palabras de producto y parece un nombre completo
    $palabras = explode(' ', $question_lower);
    $product_words = 0;
    
    foreach ($palabras as $palabra) {
        if (strlen($palabra) > 3) {
            foreach ($product_patterns as $pattern) {
                if (strpos($palabra, $pattern) !== false) {
                    $product_words++;
                    break;
                }
            }
        }
    }
    
    // Si tiene al menos 2 palabras de producto, es específico
    return $product_words >= 2;
}

/**
 * Encontrar match exacto de producto
 */
private function findExactProductMatch($products, $question) {
    $question_normalized = $this->normalizeText($question);
    $question_normalizado_nombre = $this->normalizarNombreProducto($question_normalized);
    
    $best_match = null;
    $best_score = 0;
    
    foreach ($products as $p) {
        $name_normalized = $this->normalizeText($p->product_name);
        $name_normalizado_nombre = $this->normalizarNombreProducto($name_normalized);
        
        $score = 0;
        
        // Coincidencia exacta con nombre normalizado
        if ($name_normalizado_nombre === $question_normalizado_nombre) {
            $score += 1000;
        }
        // El nombre contiene la pregunta completa
        elseif (strpos($name_normalizado_nombre, $question_normalizado_nombre) !== false) {
            $score += 500;
        }
        // La pregunta contiene el nombre completo
        elseif (strpos($question_normalizado_nombre, $name_normalizado_nombre) !== false) {
            $score += 400;
        }
        
        // Coincidencia de modelo (ej: pags 10 125)
        preg_match_all('/([a-zA-Z]+)\s*(\d+)\s*(\d*)/', $question_normalizado_nombre, $q_model);
        preg_match_all('/([a-zA-Z]+)\s*(\d+)\s*(\d*)/', $name_normalizado_nombre, $p_model);
        
        if (!empty($q_model[0]) && !empty($p_model[0])) {
            if (isset($q_model[1][0]) && isset($p_model[1][0]) && 
                $q_model[1][0] === $p_model[1][0]) {
                $score += 300;
                
                // Comparar números
                if (isset($q_model[2][0]) && isset($p_model[2][0]) && 
                    $q_model[2][0] == $p_model[2][0]) {
                    $score += 300;
                }
            }
        }
        
        // Coincidencia de palabras
        $question_words = explode(' ', $question_normalizado_nombre);
        $name_words = explode(' ', $name_normalizado_nombre);
        $matches = array_intersect($question_words, $name_words);
        $score += count($matches) * 50;
        
        if ($score > $best_score) {
            $best_score = $score;
            $best_match = $p;
        }
    }
    
    // Si la puntuación es alta, devolver el match
    if ($best_score > 200) {
        return $best_match;
    }
    
    return null;
}
    /**
 * Detectar si la pregunta es sobre un producto específico
 */
private function esPreguntaDeProducto($question) {
    $question_lower = strtolower($question);
    $question_normalized = $this->normalizeText($question_lower);
    
    // Palabras que indican que es un producto (incluyendo plurales)
    $palabras_producto = [
        'camara', 'termografica', 'taladro', 'alcoholimetro', 'registrador', 
        'analizador', 'multimetro', 'pinza', 'medidor', 'detector', 
        'comprobador', 'pirometro', 'termometro', 'osciloscopio', 'frecuencimetro'
    ];
    
    foreach ($palabras_producto as $palabra) {
        if (strpos($question_normalized, $palabra) !== false) {
            return true;
        }
        
        // También buscar plurales
        $plural = $palabra . 's';
        if (strpos($question_normalized, $plural) !== false) {
            return true;
        }
    }
    
    // Si tiene un número de modelo (como 325M-L25)
    if (preg_match('/\d+[a-zA-Z]?[-]?\d*[a-zA-Z]?/', $question)) {
        return true;
    }
    
    return false;
}
private function normalizarNombreProducto($texto) {
    // Convertir a minúsculas
    $texto = strtolower($texto);
    
    // Normalizar espacios alrededor de números
    $texto = preg_replace('/(\d+)\s*-\s*(\d+)/', '$1-$2', $texto);
    $texto = preg_replace('/([a-zA-Z])\s*(\d+)/', '$1 $2', $texto);
    $texto = preg_replace('/(\d+)\s*([a-zA-Z])/', '$1 $2', $texto);
    
    // Eliminar espacios extras
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    return trim($texto);
}
    /**
 * Búsqueda inteligente de productos con múltiples estrategias
 */
/**
 * Búsqueda inteligente de productos
 */
private function searchProductsIntelligent($question) {
    global $wpdb;
    $tables = $this->database->getTables();
    
    // Normalizar la pregunta
    $question_normalized = $this->normalizeText($question);
    $question_normalizado_nombre = $this->normalizarNombreProducto($question_normalized);
    $question_words = explode(' ', $question_normalizado_nombre);
    $question_words = array_filter($question_words, function($w) { 
        return strlen($w) > 2; 
    });

    error_log("=== BÚSQUEDA ===");
    error_log("Pregunta original: " . $question);
    error_log("Pregunta normalizada: " . $question_normalizado_nombre);
    
    // Obtener TODOS los productos
    $all_products = $wpdb->get_results("SELECT * FROM {$tables['products']}");
    
    $scored_products = [];
    
    foreach ($all_products as $p) {
        $name_normalized = $this->normalizeText($p->product_name);
        $name_normalizado_nombre = $this->normalizarNombreProducto($name_normalized);
        
        $score = 0;
        
        // Coincidencia exacta
        if ($name_normalizado_nombre === $question_normalizado_nombre) {
            $score += 1000;
        }
        // El nombre contiene la pregunta
        elseif (strpos($name_normalizado_nombre, $question_normalizado_nombre) !== false) {
            $score += 500;
        }
        // La pregunta contiene el nombre
        elseif (strpos($question_normalizado_nombre, $name_normalizado_nombre) !== false) {
            $score += 300;
        }
        
        // Coincidencia de palabras
        $name_words = explode(' ', $name_normalizado_nombre);
        $matches = array_intersect($question_words, $name_words);
        $score += count($matches) * 100;
        
        // Coincidencia de modelo (ej: pags 10 125)
        preg_match_all('/([a-zA-Z]+)\s*(\d+)\s*(\d*)/', $question_normalizado_nombre, $q_model);
        preg_match_all('/([a-zA-Z]+)\s*(\d+)\s*(\d*)/', $name_normalizado_nombre, $p_model);
        
        if (!empty($q_model[0]) && !empty($p_model[0])) {
            if (isset($q_model[1][0]) && isset($p_model[1][0]) && 
                $q_model[1][0] === $p_model[1][0]) {
                $score += 200;
                
                if (isset($q_model[2][0]) && isset($p_model[2][0]) && 
                    $q_model[2][0] == $p_model[2][0]) {
                    $score += 200;
                }
            }
        }
        
        if ($score > 10) {
            $p->relevance = $score;
            $scored_products[] = $p;
        }
    }
    
    // Ordenar por relevancia
    usort($scored_products, function($a, $b) {
        return $b->relevance - $a->relevance;
    });
    
    error_log("Top resultados: " . count($scored_products));
    
    return array_slice($scored_products, 0, 15);
}
    
    /**
     * Búsqueda inteligente de información de empresa
     */
    private function searchCompanyIntelligent($question, $intent) {
        global $wpdb;
        $tables = $this->database->getTables();
        $question_lower = strtolower($question);
        
        // Primero buscar por tipo específico
        $specific = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['company']} 
             WHERE info_type = %s 
             ORDER BY order_index ASC",
            $intent
        ));
        
        if (!empty($specific)) {
            return $specific;
        }
        
        // Si no, búsqueda general
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['company']} 
             WHERE title LIKE %s 
             OR content LIKE %s 
             OR keywords LIKE %s
             ORDER BY order_index ASC",
            '%' . $wpdb->esc_like($question_lower) . '%',
            '%' . $wpdb->esc_like($question_lower) . '%',
            '%' . $wpdb->esc_like($question_lower) . '%'
        ));
    }
    
    private function detectIntent($question) {
        $intents = [
            'contacto' => ['contacto', 'teléfono', 'telefono', 'whatsapp', 'llamar', 'número', 'numero', 'celular', 'email', 'correo', 'comunicarme'],
            'ubicacion' => ['ubicación', 'ubicacion', 'dirección', 'direccion', 'dónde', 'donde', 'encuentran', 'mapa', 'llegar', 'ubicados'],
            'horario' => ['horario', 'horarios', 'atienden', 'abren', 'cierran', 'hora', 'abierto', 'cerrado'],
            'empresa' => ['empresa', 'sobre', 'quienes', 'quienes somos', 'historia', 'acerca'],
            'servicios' => ['servicios', 'ofrecen', 'hacen', 'calibración', 'calibracion', 'soporte', 'reparación'],
            'marcas' => ['marcas', 'representan', 'trabajan', 'venden', 'distribuyen', 'fotric', 'sonel', 'trotec', 'rigel'],
            'politicas' => ['envío', 'envio', 'garantía', 'garantia', 'politicas', 'devolución', 'devolucion'],
            'novedades' => ['novedades']
        ];
        
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($question, $keyword) !== false) {
                    return $intent;
                }
            }
        }
        
        return 'producto';
    }
    
private function normalizeText($text) {
    if (empty($text)) return '';
    
    // Convertir a minúsculas
    $text = mb_strtolower($text, 'UTF-8');
    
    // Tabla de caracteres con tilde a sin tilde
    $tildes = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ã' => 'a', 'õ' => 'o', 'ñ' => 'n', 'ç' => 'c',
        'ÿ' => 'y',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'
    ];
    
    // Reemplazar tildes
    foreach ($tildes as $tilde => $sin_tilde) {
        $text = str_replace($tilde, $sin_tilde, $text);
    }
    
    // =============================================
    // NORMALIZAR PLURALES Y VARIANTES COMUNES
    // =============================================
    $plurales = [
        'pirometros' => 'pirometro',
        'pirometros' => 'pirometro',
        'termometros' => 'termometro',
        'taladros' => 'taladro',
        'camaras' => 'camara',
        'camáras' => 'camara',
        'termograficas' => 'termografica',
        'termográficas' => 'termografica',
        'alcoholimetros' => 'alcoholimetro',
        'alcoholímetros' => 'alcoholimetro',
        'registradores' => 'registrador',
        'analizadores' => 'analizador',
        'multimetros' => 'multimetro',
        'multímetros' => 'multimetro',
        'pinzas' => 'pinza',
        'medidores' => 'medidor',
        'detectores' => 'detector',
        'comprobadores' => 'comprobador'
    ];
    
    foreach ($plurales as $plural => $singular) {
        $text = str_replace($plural, $singular, $text);
    }
    
    // Eliminar caracteres especiales pero mantener letras, números y espacios
    $text = preg_replace('/[^a-z0-9\s]/', '', $text);
    
    // Eliminar espacios extras
    $text = preg_replace('/\s+/', ' ', $text);
    
    return trim($text);
}
    
private function formatProductResponse($products, $question) {
    $question_normalized = $this->normalizeText($question);
    $question_words = explode(' ', $question_normalized);
    
    // =============================================
    // REGLA 1: Si la pregunta tiene SOLO 1 o 2 palabras, es CATEGORÍA -> LISTA
    // =============================================
    $palabras_relevantes = array_filter($question_words, function($w) {
        return strlen($w) > 2 && !is_numeric($w);
    });
    
    if (count($palabras_relevantes) <= 2) {
        return $this->formatProductList($products, $this->detectarTipoBusqueda($question_normalized));
    }
    
    // =============================================
    // REGLA 2: Si tiene número de modelo específico, puede ser producto único
    // =============================================
    $tiene_numero_modelo = preg_match('/\d+[a-zA-Z]?/', $question_normalized);
    $tiene_palabra_clave_especifica = preg_match('/(325|326|345|321|384|640|284)/', $question_normalized);
    
    if ($tiene_numero_modelo && $tiene_palabra_clave_especifica && count($products) == 1) {
        return $this->formatSingleProduct($products[0]);
    }
    
    // =============================================
    // REGLA 3: Si hay match EXACTO con nombre completo y es único
    // =============================================
    $exact_match = $this->findExactProductMatch($products, $question);
    if ($exact_match && count($products) == 1) {
        // Verificar que la pregunta sea realmente específica (tiene modelo)
        $nombre_producto_normalizado = $this->normalizeText($exact_match->product_name);
        similar_text($nombre_producto_normalizado, $question_normalized, $percent);
        
        if ($percent > 80) {
            return $this->formatSingleProduct($exact_match);
        }
    }
    
    // =============================================
    // REGLA 4: Por defecto, mostrar lista
    // =============================================
    return $this->formatProductList($products, $this->detectarTipoBusqueda($question_normalized));
}

/**
 * Detectar el tipo de búsqueda para el título
 */
private function detectarTipoBusqueda($texto) {
    if (strpos($texto, 'camara') !== false || strpos($texto, 'termografica') !== false) {
        return 'Cámaras termográficas';
    }
    if (strpos($texto, 'taladro') !== false) {
        return 'Taladros';
    }
    if (strpos($texto, 'alcohol') !== false) {
        return 'Alcoholímetros';
    }
    if (strpos($texto, 'hobo') !== false) {
        return 'Registradores HOBO';
    }
    if (strpos($texto, 'sonel') !== false) {
        return 'Productos SONEL';
    }
    if (strpos($texto, 'fotric') !== false) {
        return 'Productos FOTRIC';
    }
    return 'Productos disponibles';
}

private function formatProductList($products, $tipo) {
    $response = "🔍 **" . ucfirst($tipo) . " disponibles:**\n\n";
    
    $count = 0;
    foreach ($products as $p) {
        if ($count >= 8) break; // Mostrar hasta 8 productos
        
        $response .= "• **{$p->product_name}**\n";
        $response .= "  {$p->short_description}\n";
        $response .= "  ⚡ Marca: {$p->brand}";
        
        if (!empty($p->price) && $p->price != 'Consultar') {
            $response .= " | 💰 {$p->price}";
        }
        $response .= "\n\n";
        $count++;
    }
    
    if (count($products) > 8) {
        $response .= "_... y " . (count($products) - 8) . " productos más_\n\n";
    }
    
    $response .= "💡 *¿Quieres conocer más detalles de algún modelo específico?*\n";
    $response .= "   Por ejemplo: *'más detalle del " . $products[0]->product_name . "'*";
    
    return $response;
}
/**
 * Detectar si está pidiendo más detalles
 */
private function isAskingForDetails($question) {
    $question_lower = strtolower($question);
    $detail_keywords = ['más detalle', 'mas detalle', 'detalles', 'info completa', 'información completa', 'especificaciones', 'ficha técnica', 'ficha tecnica'];
    
    foreach ($detail_keywords as $keyword) {
        if (strpos($question_lower, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Encontrar el mejor producto que coincida con la pregunta
 */
/**
 * Encontrar el mejor producto que coincida con la pregunta (IGNORANDO TILDES)
 */
private function findBestProductMatch($products, $question) {
    // Normalizar TODO (quitar tildes, mayúsculas, espacios extras)
    $question_normalized = $this->normalizeText(trim($question));
    $question_words = explode(' ', $question_normalized);
    $question_words = array_filter($question_words, function($w) { 
        return strlen($w) > 1; // Reducido a 1 para incluir más palabras
    });
    
    // Extraer modelo específico (ej: pscs 11-20v)
    preg_match_all('/([a-zA-Z]+)[\s-]*(\d+)[\s-]*(\d*)[vV]?/', $question_normalized, $question_model);
    $question_has_model = !empty($question_model[0]);
    
    $best_match = null;
    $best_score = 0;
    $matches_with_score = [];
    
    foreach ($products as $p) {
        // Normalizar nombre del producto (SIN TILDES)
        $name_normalized = $this->normalizeText($p->product_name);
        
        // Debug
        error_log("Comparando: '$name_normalized' con '$question_normalized'");
        
        // =============================================
        // 1. COINCIDENCIA EXACTA (10000 puntos)
        // =============================================
        if ($name_normalized === $question_normalized) {
            $p->relevance = 10000;
            return $p;
        }
        
        $score = 0;
        
        // =============================================
        // 2. EL NOMBRE CONTIENE LA PREGUNTA COMPLETA (5000 puntos)
        // =============================================
        if (strpos($name_normalized, $question_normalized) !== false) {
            $score += 5000;
        }
        
        // =============================================
        // 3. LA PREGUNTA CONTIENE EL NOMBRE COMPLETO (4000 puntos)
        // =============================================
        if (strpos($question_normalized, $name_normalized) !== false) {
            $score += 4000;
        }
        
        // =============================================
        // 4. COINCIDENCIA DE MODELO (3000 puntos)
        // =============================================
        preg_match_all('/([a-zA-Z]+)[\s-]*(\d+)[\s-]*(\d*)[vV]?/', $name_normalized, $name_model);
        
        if ($question_has_model && !empty($name_model[0])) {
            // Comparar series (FOTRIC, PSCS, PHDS, etc.)
            if (isset($question_model[1][0]) && isset($name_model[1][0]) && 
                $question_model[1][0] === $name_model[1][0]) {
                $score += 1500;
                
                // Comparar números (11, 12, 20, 325, etc.)
                if (isset($question_model[2][0]) && isset($name_model[2][0]) && 
                    $question_model[2][0] == $name_model[2][0]) {
                    $score += 1500;
                }
            }
        }
        
        // =============================================
        // 5. COINCIDENCIA DE PALABRAS CLAVE (100 puntos por palabra)
        // =============================================
        $name_words = explode(' ', $name_normalized);
        foreach ($question_words as $qword) {
            foreach ($name_words as $nword) {
                if ($qword === $nword) {
                    $score += 200;
                } elseif (strpos($nword, $qword) !== false || strpos($qword, $nword) !== false) {
                    $score += 100;
                }
            }
        }
        
        // =============================================
        // 6. BONUS POR PALABRAS ESPECÍFICAS DEL PRODUCTO
        // =============================================
        $specific_words = ['camara', 'termografica', 'taladro', 'bateria', 'percutor', 'alcoholimetro', 'registrador', 'analizador'];
        foreach ($specific_words as $word) {
            if (strpos($question_normalized, $word) !== false && strpos($name_normalized, $word) !== false) {
                $score += 500;
            }
        }
        
        // =============================================
        // 7. SIMILITUD TEXTUAL (similar_text)
        // =============================================
        similar_text($name_normalized, $question_normalized, $percent);
        $score += $percent * 10;
        
        $p->relevance = $score;
        $matches_with_score[] = $p;
        
        if ($score > $best_score) {
            $best_score = $score;
            $best_match = $p;
        }
    }
    
    // Debug
    error_log("=== MEJOR MATCH PARA: $question ===");
    if ($best_match) {
        error_log("  '{$best_match->product_name}' -> score: $best_score");
    }
    
    return $best_match;
}
    
    private function formatSingleProduct($p) {
    $response = "✅ **{$p->product_name}**\n\n";
    
    // Descripción corta siempre
    $response .= "📝 **Descripción rápida:**\n{$p->short_description}\n\n";
    
    // Descripción larga si existe (más detalle)
    if (!empty($p->long_description) && strlen($p->long_description) > 10) {
        $response .= "📋 **Detalles completos:**\n{$p->long_description}\n\n";
    }
    
    // Especificaciones técnicas
    $response .= "⚙️ **Especificaciones técnicas:**\n";
    $specs = explode(';', $p->specifications);
    foreach ($specs as $spec) {
        $response .= "• " . trim($spec) . "\n";
    }
    
    // Información adicional
    $response .= "\n🏷️ **Marca:** {$p->brand}\n";
    
    if (!empty($p->price) && $p->price != 'Consultar') {
        $response .= "💰 **Precio:** {$p->price}\n";
    } else {
        $response .= "💰 **Precio:** Consultar\n";
    }
    
    if (!empty($p->availability) && $p->availability != 'NULL' && $p->availability != 'Consultar') {
        $response .= "📦 **Disponibilidad:** {$p->availability}\n";
    }
    
    // Link al producto
    $response .= "\n🔗 **Ver producto en la web:**\n{$p->product_url}\n\n";
    
    $response .= "¿Necesitas información sobre algún otro producto?";
    
    return $response;
}
    
    private function formatCompanyResponse($results, $question) {
        if (count($results) == 1) {
            return $this->formatSingleCompany($results[0]);
        }
        
        // Si hay múltiples resultados, mostrar el más relevante
        $question_lower = strtolower($question);
        $best_match = null;
        $best_score = 0;
        
        foreach ($results as $r) {
            $score = 0;
            if (strpos(strtolower($r->title), $question_lower) !== false) $score += 10;
            if (strpos(strtolower($r->content), $question_lower) !== false) $score += 5;
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $r;
            }
        }
        
        if ($best_match) {
            return $this->formatSingleCompany($best_match);
        }
        
        return $this->formatSingleCompany($results[0]);
    }
    
    private function formatSingleCompany($info) {
        $response = "🏢 **{$info->title}**\n\n";
        $response .= "{$info->content}\n\n";
        
        if (!empty($info->subcontent)) {
            $response .= "📌 **Información adicional:**\n";
            if (strpos($info->subcontent, '|') !== false) {
                $items = explode('|', $info->subcontent);
                foreach ($items as $item) {
                    $response .= "• " . trim($item) . "\n";
                }
            } else {
                $response .= "{$info->subcontent}\n";
            }
        }
        
        return $response;
    }
    
    private function similarity($str1, $str2) {
        similar_text($str1, $str2, $percent);
        return $percent / 100;
    }
    
    private function smartFallback($question, $intent) {
        $response = "🤔 **No encontré resultados exactos** para \"$question\"\n\n";
        
        // Sugerencias basadas en la intención
        if ($intent == 'contacto') {
            $response .= "📞 **Para contacto:**\n";
            $response .= "• Puedes preguntar: 'teléfono', 'whatsapp', 'dirección'\n\n";
        } elseif ($intent == 'ubicacion') {
            $response .= "📍 **Para ubicación:**\n";
            $response .= "• Puedes preguntar: 'dónde están', 'dirección', 'mapa'\n\n";
        } elseif ($intent == 'producto') {
            $response .= "🔍 **Para productos, intenta con:**\n";
            $response .= "• 'taladros'\n";
            $response .= "• 'cámaras termográficas'\n";
            $response .= "• 'analizadores rigel'\n";
            $response .= "• 'marcas como sonel o trotec'\n\n";
        }
        
        $response .= "💡 **¿Qué te gustaría saber?**\n";
        $response .= "• Sobre la empresa\n";
        $response .= "• Nuestros productos\n";
        $response .= "• Servicios de calibración\n";
        $response .= "• Contacto y ubicación";
        
        return $response;
    }
}