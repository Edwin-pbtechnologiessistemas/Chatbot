<?php
/**
 * Plugin Name: RAG Chat para PBTechnologies
 * Description: Sistema RAG con Firecrawl para consultas inteligentes
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

define('RAG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Cargar clases
require_once RAG_PLUGIN_DIR . 'includes/class-rag-database.php';
require_once RAG_PLUGIN_DIR . 'includes/class-rag-extractor.php';
require_once RAG_PLUGIN_DIR . 'includes/class-rag-chat.php';

class RAG_PBTechnologies {
    
    private $api_key;
    private $extractor;
    private $chat;
    private $api_url = 'https://api.firecrawl.dev/v1';
    
    public function __construct() {
        $this->api_key = get_option('rag_firecrawl_api_key');
        
        if ($this->api_key) {
            $this->extractor = new RAG_Extractor($this->api_key);
            $this->chat = new RAG_Chat();
        }
        
        // Hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_rag_query', array($this, 'handle_query'));
        add_action('wp_ajax_nopriv_rag_query', array($this, 'handle_query'));
        add_action('wp_footer', array($this, 'render_chat'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Hook para extracción completa
        add_action('admin_init', array($this, 'check_pending_products'));
    }
    
    public function handle_query() {
        check_ajax_referer('rag_nonce', 'nonce');
        
        $question = sanitize_text_field($_POST['question']);
        
        if (!$this->chat) {
            wp_send_json_error('Chat no inicializado');
        }
        
        $response = $this->chat->process_question($question);
        wp_send_json_success($response);
    }
    
    public function run_complete_extraction() {
    if (!$this->extractor) {
        return;
    }
    
    // Aumentar tiempo a 30 minutos
    set_time_limit(1800);
    ini_set('max_execution_time', 1800);
    ini_set('memory_limit', '512M');
    
    // Forzar salida
    ob_implicit_flush(true);
    @ob_end_flush();
    
    echo '<div class="wrap">';
    echo '<h1>🚀 EXTRACCIÓN COMPLETA INICIADA</h1>';
    echo '<div class="notice notice-info" style="padding:15px;">';
    echo '<p><strong>⏱️ Procesando TODO (inicio + about + productos)... 20-30 minutos. No cierres esta ventana.</strong></p>';
    echo '</div>';
    
    // ✅ EXTRAER TODO (inicio, about, productos)
    $this->extractor->extract_everything();
    
    // 📊 RESUMEN FINAL
    global $wpdb;
    $tabla = $wpdb->prefix . 'rag_knowledge';
    $total_docs = $wpdb->get_var("SELECT COUNT(*) FROM $tabla");
    $productos_unicos = $wpdb->get_var("SELECT COUNT(DISTINCT source_url) FROM $tabla WHERE content_type = 'producto'");
    $inicio = $wpdb->get_var("SELECT COUNT(*) FROM $tabla WHERE content_type = 'inicio'");
    $empresa = $wpdb->get_var("SELECT COUNT(*) FROM $tabla WHERE content_type = 'empresa'");
    
    echo '<div class="notice notice-success" style="margin-top:20px; padding:15px;">';
    echo '<h2>📊 RESUMEN FINAL COMPLETO</h2>';
    echo "<p>🏠 Página de inicio: <strong>{$inicio} fragmentos</strong></p>";
    echo "<p>👥 Página about-us: <strong>{$empresa} fragmentos</strong></p>";
    echo "<p>📦 Productos únicos: <strong>{$productos_unicos} / 112</strong></p>";
    echo "<p>📄 Total documentos: <strong>{$total_docs}</strong></p>";
    echo '</div>';
    
    echo '<p><a href="' . admin_url('admin.php?page=rag-chat') . '" class="button button-primary">Actualizar página</a></p>';
    echo '</div>';
}
    
    public function enqueue_scripts() {
        wp_enqueue_style('rag-chat', RAG_PLUGIN_URL . 'assets/chat.css', array(), '1.0');
        wp_enqueue_script('rag-chat', RAG_PLUGIN_URL . 'assets/chat.js', array('jquery'), '1.0', true);
        
        wp_localize_script('rag-chat', 'rag_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rag_nonce')
        ));
    }
    
    public function render_chat() {
        ?>
        <div id="rag-chat-widget">
            <div id="rag-chat-button">💬</div>
            <div id="rag-chat-window" style="display:none;">
                <div id="rag-chat-header">
                    <span>Asistente PBTechnologies</span>
                    <button id="rag-chat-close">×</button>
                </div>
                <div id="rag-chat-messages">
                    <div class="rag-message bot">
                        ¡Hola! Pregúntame sobre productos, servicios o la empresa.
                    </div>
                </div>
                <div id="rag-chat-input-area">
                    <input type="text" id="rag-chat-input" placeholder="Escribe tu pregunta...">
                    <button id="rag-chat-send">Enviar</button>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function admin_menu() {
        add_menu_page(
            'RAG Chat',
            'RAG Chat',
            'manage_options',
            'rag-chat',
            array($this, 'admin_page'),
            'dashicons-format-chat',
            30
        );
    }
    
    public function admin_page() {
        // Iniciar buffer
        if (ob_get_level() == 0) {
            ob_start();
        }
        
        // Procesar extracción completa (1 solo botón)
        if (isset($_POST['rag_extract_complete']) && check_admin_referer('rag_extract_complete')) {
            $this->run_complete_extraction();
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>RAG Chat - PBTechnologies</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('rag_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>Firecrawl API Key</th>
                        <td>
                            <input type="text" 
                                   name="rag_firecrawl_api_key" 
                                   value="<?php echo esc_attr(get_option('rag_firecrawl_api_key')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar Configuración'); ?>
            </form>
            
            <hr>
            
            <h2>Base de Conocimiento</h2>
            <?php
            global $wpdb;
            $tabla = $wpdb->prefix . 'rag_knowledge';
            
            $total_docs = $wpdb->get_var("SELECT COUNT(*) FROM $tabla");
            $productos_unicos = $wpdb->get_var("SELECT COUNT(DISTINCT source_url) FROM $tabla WHERE content_type = 'producto'");
            ?>
            
            <table class="widefat striped">
                <tr>
                    <th>Total documentos</th>
                    <td><?php echo $total_docs; ?></td>
                </tr>
                <tr>
                    <th>Productos únicos</th>
                    <td><?php echo $productos_unicos; ?> / 112</td>
                </tr>
            </table>
            
            <!-- BOTÓN ÚNICO - HACE TODO -->
            <div style="margin: 30px 0; padding: 20px; background: #f0f8ff; border: 3px solid #0055a4; border-radius: 8px;">
                <h2 style="color: #0055a4; margin-top: 0; font-size: 24px;">🚀 EXTRACCIÓN COMPLETA (1 SOLO PASO)</h2>
                <p><strong>Este botón hace TODO:</strong></p>
                <ul>
                    <li>1️⃣ Extrae TODAS las URLs de productos con Firecrawl</li>
                    <li>2️⃣ Extrae la información detallada de CADA producto</li>
                    <li>3️⃣ Guarda todo en la base de conocimiento</li>
                </ul>
                <p><strong>⏱️ Tiempo estimado: 20-30 minutos. No cierres la ventana.</strong></p>
                
                <form method="post">
                    <?php wp_nonce_field('rag_extract_complete'); ?>
                    <input type="submit" name="rag_extract_complete" class="button button-primary" 
                           value="🚀 INICIAR EXTRACCIÓN COMPLETA AHORA" 
                           style="background: #d63638; border-color: #b32d2e; font-size: 18px; padding: 15px 30px; height: auto;">
                </form>
            </div>
            
            <?php
            // Mostrar últimos productos guardados
            $ultimos_productos = $wpdb->get_results(
                "SELECT * FROM $tabla 
                 WHERE content_type = 'producto' 
                 AND content LIKE '%Producto:%' 
                 ORDER BY id DESC 
                 LIMIT 20"
            );
            
            if ($ultimos_productos) {
                echo '<h3>📋 Últimos productos guardados:</h3>';
                echo '<div style="max-height:300px; overflow-y:scroll; border:1px solid #ddd; padding:10px;">';
                foreach ($ultimos_productos as $p) {
                    preg_match('/Producto:\s*(.+)/i', $p->content, $matches);
                    $nombre = $matches[1] ?? substr($p->content, 0, 50);
                    echo '<p><strong>🔹 ' . esc_html($nombre) . '</strong><br>';
                    echo '<small>URL: ' . esc_html(basename($p->source_url)) . ' | ID: ' . $p->id . '</small></p>';
                }
                echo '</div>';
            }
            ?>
        </div>
        <?php
    }
    
    public function check_pending_products() {
        // Ya no necesario
    }
    
    public function register_settings() {
        register_setting('rag_settings', 'rag_firecrawl_api_key');
    }
}

// Inicializar
new RAG_PBTechnologies();