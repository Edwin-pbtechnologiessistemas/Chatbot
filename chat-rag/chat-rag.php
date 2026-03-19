<?php
/**
 * Plugin Name: ChatRAG - Asistente Inteligente
 * Plugin URI: https://tusitio.com
 * Description: Chatbot con base de conocimiento curada - Soporte para Excel
 * Version: 2.0.1
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

define('CHAT_RAG_VERSION', '2.0.1');
define('CHAT_RAG_PATH', plugin_dir_path(__FILE__));
define('CHAT_RAG_URL', plugin_dir_url(__FILE__));
define('CHAT_RAG_FILE', __FILE__);

// Verificar versión de PHP primero
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>ChatRAG:</strong> Necesitas PHP 7.2 o superior. Tienes PHP ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// Autoloader para las clases del plugin
spl_autoload_register(function ($class) {
    $prefix = 'ChatRAG_';
    $base_dir = CHAT_RAG_PATH;
    
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $relative_class = substr($class, strlen($prefix));
    $file_name = 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    
    $locations = [
        $base_dir . 'includes/' . $file_name,
        $base_dir . 'admin/' . $file_name,
    ];
    
    foreach ($locations as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Clase principal
class ChatRAG {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', [$this, 'init'], 20);
        register_activation_hook(CHAT_RAG_FILE, [$this, 'activate']);
        add_action('wp_enqueue_scripts', [$this, 'frontendAssets']);
    }
    
    public function init() {
        // Cargar dependencias
        $this->loadDependencies();
        
        // Inicializar componentes
        $database = new ChatRAG_Database();
        $embeddings = new ChatRAG_Embeddings();
        $chat_handler = new ChatRAG_Chat_Handler($database, $embeddings);
        $importer = new ChatRAG_Importer($database, $embeddings);
        $assets = new ChatRAG_Assets();
        
        if (is_admin()) {
            new ChatRAG_Admin_Menu($importer, $database);
            
        }
    }
    
    public function showExcelSuccess() {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>✅ <strong>ChatRAG:</strong> Soporte para Excel activado correctamente.</p>
        </div>
        <?php
    }
    
    
    private function loadDependencies() {
        $files = [
            'includes/class-database.php',
            'includes/class-embeddings.php',
            'includes/class-chat-handler.php',
            'includes/class-importer.php',
            'includes/class-assets.php',
            'admin/class-admin-menu.php',
        ];
        
        foreach ($files as $file) {
            $path = CHAT_RAG_PATH . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
    
    public function activate() {
        require_once CHAT_RAG_PATH . 'includes/class-database.php';
        ChatRAG_Database::createTables();
    }
    
    public function frontendAssets() {
        if (!is_admin()) {
            wp_enqueue_style('chat-rag-css', CHAT_RAG_URL . 'assets/chat.css', [], CHAT_RAG_VERSION);
            wp_enqueue_script('chat-rag-js', CHAT_RAG_URL . 'assets/chat.js', ['jquery'], CHAT_RAG_VERSION, true);
            
            wp_localize_script('chat-rag-js', 'chat_rag', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chat_rag_nonce')
            ]);
        }
    }
}

ChatRAG::getInstance();