<?php
/**
 * Plugin Name: Gemini RAG Product Chat
 * Description: Chat inteligente profesional para PBTechnologies usando Gemini 3.1 Flash Lite con RAG de productos y empresa.
 * Version: 2.9
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'class-database.php';

class Gemini_RAG_Plugin {
    private $db;
    private $api_key = 'AIzaSyAoaSfF1q-eSXCUQJQD430RBMI5bRxCmEg'; 

    public function __construct() {
        $this->db = new ChatRAG_Database();
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_chat_rag_query', [$this, 'handle_chat_query']);
        add_action('wp_ajax_nopriv_chat_rag_query', [$this, 'handle_chat_query']);
        
        if (!session_id()) {
            session_start();
        }
    }

    public function enqueue_assets() {
        wp_enqueue_style('rag-chat-style', plugin_dir_url(__FILE__) . 'assets/css/chat-style.css', [], time());
        wp_enqueue_script('rag-chat-script', plugin_dir_url(__FILE__) . 'assets/js/chat-script.js', ['jquery'], time(), true);

        wp_localize_script('rag-chat-script', 'chat_rag', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('chat_rag_nonce')
        ]);
    }

    public function handle_chat_query() {
        try {
            check_ajax_referer('chat_rag_nonce', 'nonce');
            
            if (!isset($_POST['question'])) {
                wp_send_json_error(['message' => 'No se recibió ninguna pregunta']);
                return;
            }
            
            $question = sanitize_text_field($_POST['question']);
            
            // Rate limiting
            $user_ip = $_SERVER['REMOTE_ADDR'];
            $recent_queries = get_transient("rag_queries_{$user_ip}");
            if ($recent_queries && $recent_queries > 10) {
                wp_send_json_error(['message' => 'Demasiadas consultas. Por favor, espera un momento.']);
                return;
            }
            set_transient("rag_queries_{$user_ip}", ($recent_queries ?: 0) + 1, 60);
            
            if (strlen($question) > 500) {
                wp_send_json_error(['message' => 'La pregunta es demasiado larga (máximo 500 caracteres)']);
                return;
            }
            
            global $wpdb;
            $table_products = $this->db->getTables()['products'];
            $table_company = $this->db->getTables()['company'];

            error_log("---------- INICIO CONSULTA RAG ----------");
            error_log("PREGUNTA: " . $question);

            // 1. BÚSQUEDA DE INFORMACIÓN DE EMPRESA
            $company_info = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_company 
                 WHERE title LIKE %s 
                    OR content LIKE %s 
                    OR keywords LIKE %s 
                 LIMIT 10",
                '%'.$wpdb->esc_like($question).'%',
                '%'.$wpdb->esc_like($question).'%',
                '%'.$wpdb->esc_like($question).'%'
            ));
            
            if (empty($company_info)) {
                $company_info = $wpdb->get_results(
                    "SELECT * FROM $table_company 
                     WHERE info_type IN ('empresa', 'ubicacion', 'contacto') 
                     ORDER BY order_index ASC 
                     LIMIT 10"
                );
            }

            // 2. BÚSQUEDA DE PRODUCTOS - DINÁMICA
            $products = $this->searchProductsOptimized($question);

            error_log("PRODUCTOS ENCONTRADOS: " . count($products));
            if (!empty($products)) {
                foreach ($products as $p) {
                    error_log("PRODUCTO: " . $p->product_name . " | URL: " . $p->product_url);
                }
            }

            // Después de obtener $products, agregar:
if (!empty($products)) {
    $products = $this->searchProductsWithRelevance($question, $products);
}

            if (empty($products)) {
                error_log("Búsqueda vacía. Aplicando fallback de últimos productos.");
                $products = $wpdb->get_results("SELECT * FROM $table_products ORDER BY id DESC LIMIT 5");
            }

            // 3. CONSTRUIR CONTEXTO
            $context = $this->buildContext($company_info, $products);

            error_log("---------- FIN CONSULTA RAG ----------");

            $response = $this->call_gemini_with_cache($question, $context);
            
            $this->logQuery($question, count($products), strlen($response));
            
            wp_send_json_success([
                'answer' => $response,
                'debug_context' => $context 
            ]);
            
        } catch (Exception $e) {
            error_log("Error en chat RAG: " . $e->getMessage());
            wp_send_json_error([
                'message' => 'Ocurrió un error procesando tu consulta. Por favor, intenta nuevamente.'
            ]);
        }
    }
    
    private function searchProductsOptimized($query) {
    global $wpdb;
    $table_products = $this->db->getTables()['products'];
    
    // Normalizar consulta
    $clean_query = $this->simplify_text($query);
    $words = explode(' ', $clean_query);
    $words = array_filter($words, function($w) { 
        return strlen($w) > 2 && !in_array($w, ['con', 'que', 'para', 'por', 'una', 'las', 'los', 'del', 'pueda']); 
    });
    
    error_log("=== BÚSQUEDA DE PRODUCTOS ===");
    error_log("Query original: " . $query);
    error_log("Palabras clave: " . implode(', ', $words));
    
    // ESTRATEGIA 1: Buscar por categoría detectada automáticamente
    // Extraer categorías relevantes de la pregunta
    $categories = $this->extractCategoriesFromQuery($query);
    
    if (!empty($categories)) {
        error_log("Categorías detectadas: " . implode(', ', $categories));
        
        $category_conditions = [];
        foreach ($categories as $cat) {
            $category_conditions[] = $wpdb->prepare("category LIKE %s", '%' . $wpdb->esc_like($cat) . '%');
            $category_conditions[] = $wpdb->prepare("product_name LIKE %s", '%' . $wpdb->esc_like($cat) . '%');
            $category_conditions[] = $wpdb->prepare("keywords LIKE %s", '%' . $wpdb->esc_like($cat) . '%');
        }
        
        $sql = "SELECT * FROM $table_products WHERE " . implode(' OR ', $category_conditions) . " LIMIT 20";
        $products = $wpdb->get_results($sql);
        
        if (!empty($products)) {
            error_log("Encontrados por categoría: " . count($products));
            return $products;
        }
    }
    
    // ESTRATEGIA 2: Búsqueda por código de producto (cualquier código, no solo VT o P)
    // Esto detecta automáticamente cualquier código como: VT-2, P-5, XYZ-123, etc.
    preg_match_all('/([A-Z]+[-]?\d+)/i', $query, $codes);
    $product_codes = $codes[0] ?? [];
    
    if (!empty($product_codes)) {
        error_log("Códigos encontrados: " . implode(', ', $product_codes));
        $code_conditions = [];
        foreach ($product_codes as $code) {
            $code_conditions[] = $wpdb->prepare("product_name LIKE %s", '%' . $wpdb->esc_like($code) . '%');
            $code_conditions[] = $wpdb->prepare("keywords LIKE %s", '%' . $wpdb->esc_like($code) . '%');
        }
        $sql_codes = "SELECT * FROM $table_products WHERE " . implode(' OR ', $code_conditions) . " LIMIT 20";
        $products = $wpdb->get_results($sql_codes);
        
        if (!empty($products)) {
            error_log("Encontrados por código: " . count($products));
            return $products;
        }
    }
    
    // ESTRATEGIA 3: Búsqueda por nombre o keywords con LIKE
    $products = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_products 
         WHERE product_name LIKE %s 
            OR keywords LIKE %s 
            OR category LIKE %s 
         LIMIT 20",
        '%' . $wpdb->esc_like($query) . '%',
        '%' . $wpdb->esc_like($query) . '%',
        '%' . $wpdb->esc_like($query) . '%'
    ));
    
    if (!empty($products)) {
        error_log("Encontrados por nombre/keywords: " . count($products));
        return $products;
    }
    
    // ESTRATEGIA 4: Búsqueda por palabras individuales
    if (!empty($words)) {
        $conditions = [];
        foreach ($words as $word) {
            $conditions[] = $wpdb->prepare(
                "(product_name LIKE %s OR keywords LIKE %s OR category LIKE %s)", 
                '%' . $wpdb->esc_like($word) . '%', 
                '%' . $wpdb->esc_like($word) . '%',
                '%' . $wpdb->esc_like($word) . '%'
            );
        }
        $sql = "SELECT * FROM $table_products WHERE " . implode(' OR ', $conditions) . " LIMIT 20";
        $products = $wpdb->get_results($sql);
        
        if (!empty($products)) {
            error_log("Encontrados por palabras: " . count($products));
        }
    }
    
    // ESTRATEGIA 5: Si no hay resultados, traer los más recientes
    if (empty($products)) {
        $products = $wpdb->get_results("SELECT * FROM $table_products ORDER BY id DESC LIMIT 10");
        error_log("Fallback: últimos productos: " . count($products));
    }
    
    return $products;
}

private function extractCategoriesFromQuery($query) {
    global $wpdb;
    $table_products = $this->db->getTables()['products'];
    
    // Obtener todas las categorías únicas de la base de datos
    $all_categories = $wpdb->get_col("SELECT DISTINCT category FROM $table_products WHERE category IS NOT NULL AND category != ''");
    
    $detected_categories = [];
    $query_lower = strtolower($query);
    
    // Detectar qué categorías aparecen en la pregunta
    foreach ($all_categories as $category) {
        $category_lower = strtolower($category);
        if (strpos($query_lower, $category_lower) !== false) {
            $detected_categories[] = $category;
        }
    }
    
    // También buscar por palabras clave comunes en categorías
    $category_keywords = [
        'indicador' => ['comprobador', 'tension', 'tensión', 'detector', 'tester'],
        'herramientas' => ['amoladora', 'taladro', 'sierra', 'herramienta'],
        'equipos de metrología biomédica' => ['biomedica', 'hospital', 'clinica', 'desfibrilador', 'bomba'],
    ];
    
    foreach ($category_keywords as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($query_lower, $keyword) !== false) {
                $detected_categories[] = $category;
            }
        }
    }
    
    return array_unique($detected_categories);
}
    
    private function simplify_text($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $unwanted_array = array(
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 
            'ñ'=>'n', 'Á'=>'a', 'É'=>'e', 'Í'=>'i', 'Ó'=>'o', 'Ú'=>'u', 'Ñ'=>'n'
        );
        return strtr($text, $unwanted_array);
    }
    
    private function buildContext($company_info, $products) {
    $context = "=== DATOS OFICIALES DE PBTechnologies ===\n\n";
    
    // Información de empresa (ya es dinámica)
    if (!empty($company_info)) {
        $context .= "🏢 INFORMACIÓN CORPORATIVA:\n";
        $context .= "================================\n";
        
        foreach ($company_info as $info) {
            $context .= "TIPO: {$info->info_type}\n";
            $context .= "TÍTULO: {$info->title}\n";
            $context .= "CONTENIDO: {$info->content}\n";
            if (!empty($info->subcontent)) {
                $context .= "INFORMACIÓN ADICIONAL: {$info->subcontent}\n";
            }
            $context .= "\n";
        }
    }
    
    // Productos - AGRUPACIÓN DINÁMICA por categoría
    if (!empty($products)) {
        $context .= "📦 CATÁLOGO DE PRODUCTOS DISPONIBLES:\n";
        $context .= "================================\n\n";
        
        // Agrupar productos por categoría dinámicamente
        $grouped_products = [];
        foreach ($products as $p) {
            $category = !empty($p->category) ? $p->category : 'Otros';
            if (!isset($grouped_products[$category])) {
                $grouped_products[$category] = [];
            }
            $grouped_products[$category][] = $p;
        }
        
        // Mostrar cada grupo
        foreach ($grouped_products as $category => $items) {
            $context .= "📌 {$category}:\n";
            $context .= str_repeat("-", 40) . "\n\n";
            
            foreach ($items as $p) {
                $context .= "**{$p->product_name}**\n";
                $context .= "• MARCA: {$p->brand}\n";
                $context .= "• DESCRIPCIÓN: {$p->short_description}\n";
                
                if (!empty($p->specifications) && $p->specifications != '%s') {
                    $context .= "• ESPECIFICACIONES: {$p->specifications}\n";
                }
                
                $context .= "• URL: {$p->product_url}\n";
                $context .= "\n";
            }
        }
    }
    
    return $context;
}
    
    private function call_gemini_with_cache($question, $context) {
        $cache_key = md5($question . substr($context, 0, 500));
        
        if (isset($_SESSION['rag_cache'][$cache_key])) {
            error_log("Usando cache para pregunta: " . substr($question, 0, 50));
            return $_SESSION['rag_cache'][$cache_key];
        }
        
        $response = $this->call_gemini($question, $context);
        
        if (!isset($_SESSION['rag_cache'])) {
            $_SESSION['rag_cache'] = [];
        }
        $_SESSION['rag_cache'][$cache_key] = $response;
        
        if (count($_SESSION['rag_cache']) > 50) {
            array_shift($_SESSION['rag_cache']);
        }
        
        return $response;
    }

    private function call_gemini($question, $context) {
        $model = "gemini-3.1-flash-lite-preview"; 
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $this->api_key;

        $system_instruction = $this->getSystemInstruction();

        $body = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => "INSTRUCCIONES:\n$system_instruction\n\nCONTEXTO OFICIAL (SOLO USA ESTA INFORMACIÓN):\n$context\n\nPREGUNTA DEL CLIENTE:\n$question"]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.2,
                "maxOutputTokens" => 1024,
                "topP" => 0.9
            ]
        ];

        $request = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($request)) {
            error_log("Error conexión Gemini: " . $request->get_error_message());
            return "Error de conexión con el servicio de inteligencia artificial. Por favor, intenta nuevamente.";
        }
        
        $response_body = wp_remote_retrieve_body($request);
        $data = json_decode($response_body, true);

        if (isset($data['error'])) {
            error_log("Error Gemini API: " . $data['error']['message']);
            return "Lo siento, hubo un error procesando tu consulta. Por favor, intenta de nuevo más tarde.";
        }

        $response = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Lo siento, no puedo procesar esa consulta en este momento.";
        
        // Limpiar URLs de formato markdown
        $response = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$2', $response);
        
        return $response;
    }
    
    private function getSystemInstruction() {
    return "Eres el Asistente Técnico Experto de PBTechnologies S.R.L. (Bolivia).
    
    === REGLAS OBLIGATORIAS ===
    
    1. **URLS**:
       - Muestra la URL EXACTAMENTE como aparece en el contexto
       - PROHIBIDO usar formato [texto](url)
       - PROHIBIDO inventar URLs
    
    2. **LISTAR PRODUCTOS**:
       - Cuando el usuario pregunta por un tipo de producto, LISTA TODOS los productos de ese tipo que aparecen en el contexto
       - NO omitas ningún producto relevante
       - Agrupa los productos por categoría si hay varias
       - Si hay múltiples productos similares, haz una comparación destacando:
         * Rangos de medición
         * Características clave
         * Diferencias principales
    
    3. **USAR DATOS DEL CONTEXTO**:
       - Usa SOLO la información que aparece en el contexto
       - Usa nombres EXACTOS de productos
       - Usa descripciones EXACTAS
       - Usa especificaciones EXACTAS
    
    4. **FORMATO DE RESPUESTA**:
       - Sé amable y profesional
       - Usa el formato que mejor se adapte a la consulta
       - Para múltiples productos, usa listas o tablas comparativas
       - Destaca qué productos cumplen con los requisitos específicos del usuario
    
    5. **PROHIBICIONES**:
       - NO inventes información
       - NO uses formato markdown en URLs
       - NO omitas productos relevantes
       - NO recomiendes un solo producto si hay varios disponibles";
}

// Agregar este método a la clase Gemini_RAG_Plugin
private function searchProductsWithRelevance($question, $products) {
    if (empty($products)) return $products;
    
    // Extraer requisitos específicos de la pregunta (rangos, voltajes, etc.)
    $requirements = $this->extractRequirements($question);
    
    // Calcular relevancia para cada producto
    foreach ($products as $product) {
        $relevance = 0;
        
        // Mayor relevancia si el nombre contiene palabras clave
        $product_name_lower = strtolower($product->product_name);
        $question_lower = strtolower($question);
        
        if (strpos($product_name_lower, 'tension') !== false || 
            strpos($product_name_lower, 'tensión') !== false) {
            $relevance += 10;
        }
        
        if (strpos($product_name_lower, 'contacto') !== false) {
            $relevance += 5;
        }
        
        // Verificar si las especificaciones cumplen con los requisitos
        if (!empty($requirements['voltage_min']) && !empty($product->specifications)) {
            $specs = strtolower($product->specifications);
            if (strpos($specs, (string)$requirements['voltage_min']) !== false) {
                $relevance += 20;
            }
        }
        
        $product->relevance = $relevance;
    }
    
    // Ordenar por relevancia
    usort($products, function($a, $b) {
        return $b->relevance - $a->relevance;
    });
    
    return $products;
}

private function extractRequirements($question) {
    $requirements = [];
    $question_lower = strtolower($question);
    
    // Buscar rangos de voltaje (ej: 100…1000, 90-1000, 90 a 1000)
    $patterns = [
        '/(\d+)\s*\.\.\.\s*(\d+)/',
        '/(\d+)\s*-\s*(\d+)/',
        '/(\d+)\s*a\s*(\d+)/',
        '/(\d+)\s*hasta\s*(\d+)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $question_lower, $matches)) {
            $requirements['voltage_min'] = (int)$matches[1];
            $requirements['voltage_max'] = (int)$matches[2];
            break;
        }
    }
    
    // Detectar tipo de producto buscado
    if (strpos($question_lower, 'tension') !== false || strpos($question_lower, 'tensión') !== false) {
        $requirements['type'] = 'voltage_tester';
    }
    
    if (strpos($question_lower, 'contacto') !== false) {
        $requirements['contact_type'] = 'non_contact';
    } elseif (strpos($question_lower, 'bipolar') !== false) {
        $requirements['contact_type'] = 'bipolar';
    }
    
    return $requirements;
}
    
    private function logQuery($question, $products_count, $response_length) {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'rag_query_logs';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_logs'");
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $table_logs (
                id int(11) NOT NULL AUTO_INCREMENT,
                question text NOT NULL,
                products_found int(11) DEFAULT 0,
                response_length int(11) DEFAULT 0,
                user_ip varchar(45) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        $wpdb->insert($table_logs, [
            'question' => substr($question, 0, 500),
            'products_found' => $products_count,
            'response_length' => $response_length,
            'user_ip' => $_SERVER['REMOTE_ADDR'],
            'created_at' => current_time('mysql')
        ]);
    }
}

new Gemini_RAG_Plugin();