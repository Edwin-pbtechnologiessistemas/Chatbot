<?php
/**
 * Plugin Name: Gemini RAG Product Chat
 * Description: Chat inteligente profesional para PBTechnologies usando Gemini 3.1 Flash Lite con RAG.
 * Version: 3.0
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'class-database.php';

class Gemini_RAG_Plugin {
    private $db;
    private $api_key = ''; 

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
            
            global $wpdb;
            $table_products = $this->db->getTables()['products'];
            $table_company = $this->db->getTables()['company'];

            // 1. INFORMACIÓN DE EMPRESA
            $company_info = $wpdb->get_results("SELECT * FROM $table_company WHERE info_type IN ('empresa', 'ubicacion', 'contacto') ORDER BY order_index ASC LIMIT 5");

            // 2. BÚSQUEDA DE PRODUCTOS
            $products = $this->searchProductsOptimized($question);

            // 3. CONSTRUIR CONTEXTO
            $context = $this->buildContext($company_info, $products);

            // 4. LLAMADA A GEMINI (Caché desactivada para pruebas)
            $response = $this->call_gemini($question, $context);
            
            $this->logQuery($question, count($products), strlen($response));
            
            wp_send_json_success([
                'answer' => $response,
                'debug_context' => $context 
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    private function searchProductsOptimized($query) {
        global $wpdb;
        $table_products = $this->db->getTables()['products'];
        
        $clean_query = mb_strtolower($query, 'UTF-8');
        $words = explode(' ', $clean_query);
        
        // Solo palabras con significado
        $words = array_filter($words, function($w) { 
            return strlen($w) > 3 && !in_array($w, ['para', 'tiene', 'tienen', 'ustedes', 'busco', 'hola']); 
        });

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
            
            $sql = "SELECT * FROM $table_products WHERE " . implode(' AND ', $conditions) . " LIMIT 15";
            $products = $wpdb->get_results($sql);
            
            if (!empty($products)) return $products;

            $sql_or = "SELECT * FROM $table_products WHERE " . implode(' OR ', $conditions) . " LIMIT 15";
            $products = $wpdb->get_results($sql_or);
            if (!empty($products)) return $products;
        }

        return $wpdb->get_results("SELECT * FROM $table_products ORDER BY id DESC LIMIT 5");
    }

    private function buildContext($company_info, $products) {
    $context = "--- INFORMACIÓN CORPORATIVA PBTechnologies ---\n";
    foreach ($company_info as $info) {
        $context .= "{$info->title}: {$info->content}\n: {$info->subcontent}\n";
    }
    
    $context .= "\n--- CATÁLOGO DETALLADO DE PRODUCTOS (SÍ TENEMOS DISPONIBLES) ---\n";
    if (!empty($products)) {
        foreach ($products as $p) {
            $context .= "PRODUCTO: {$p->product_name}\n";
            $context .= "MARCA: {$p->brand} | CATEGORÍA: {$p->category} | SUB: {$p->subcategory}\n";
            
            // Unimos descripción corta y larga si existen
            $desc = (!empty($p->short_description)) ? $p->short_description : '';
            if (!empty($p->long_description)) $desc .= " " . $p->long_description;
            $context .= "RESUMEN: $desc\n";
            
            // ESTO ES CLAVE: Pasar las especificaciones técnicas
            if (!empty($p->specifications)) {
                $context .= "ESPECIFICACIONES TÉCNICAS: {$p->specifications}\n";
            }
            
            $context .= "ESTADO: " . ($p->availability ? $p->availability : 'Disponible') . "\n";
            $context .= "URL: {$p->product_url}\n";
            $context .= "-------------------------------------------\n";
        }
    } else {
        $context .= "No se encontraron productos coincidentes en nuestra base de datos oficial.";
    }
    return $context;
}

    private function call_gemini($question, $context) {
        $model = "gemini-3.1-flash-lite-preview"; 
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $this->api_key;
        
        $prompt = "INSTRUCCIÓN DEL SISTEMA:
Eres el Ingeniero de Soporte Técnico de PBTechnologies S.R.L. (Bolivia).

REGLAS PARA COMPARACIONES:
1. Si el usuario pide comparar productos (ej. '¿Cuál es la diferencia entre el Ti5 y el Ti7?'), genera una TABLA comparativa clara.
2. Compara puntos clave: Resolución, Sensibilidad, Lente, y Aplicación sugerida basándote en 'ESPECIFICACIONES TÉCNICAS'.
3. Indica claramente cuál es el modelo superior o para qué caso de uso se recomienda cada uno (ej. 'El Ti7 es ideal para mantenimiento predictivo de alta exigencia').
4. Al final de la comparación, proporciona las URLs de ambos productos.

REGLAS GENERALES:
- Usa siempre los datos del CONTEXTO DE INVENTARIO.
- Si un dato no está en las especificaciones, di 'Consultar con un asesor'.
- Tono profesional y experto.

CONTEXTO DE INVENTARIO:
$context

PREGUNTA DEL CLIENTE:
$question";

        $body = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => ["temperature" => 0.1, "maxOutputTokens" => 800]
        ];

        $request = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($request)) return "Error de red.";
        
        $data = json_decode(wp_remote_retrieve_body($request), true);
        $response = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Lo siento, no puedo responder ahora.";
        return preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$2', $response);
    }

    private function logQuery($question, $products_count, $response_length) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rag_query_logs', [
            'question' => substr($question, 0, 500),
            'products_found' => $products_count,
            'response_length' => $response_length,
            'user_ip' => $_SERVER['REMOTE_ADDR'],
            'created_at' => current_time('mysql')
        ]);
    }
}

new Gemini_RAG_Plugin();