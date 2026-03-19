<?php
class ChatRAG_Admin_Menu {
    
    private $importer;
    private $database;
    
    public function __construct($importer, $database) {
        $this->importer = $importer;
        $this->database = $database;
        
        add_action('admin_menu', [$this, 'addMenus']);
        add_action('admin_enqueue_scripts', [$this, 'adminScripts']);
    }
    
    public function addMenus() {
        add_menu_page(
            'ChatRAG',
            'ChatRAG',
            'manage_options',
            'chat-rag',
            [$this, 'renderDashboard'],
            'dashicons-format-chat',
            30
        );
        
        add_submenu_page(
            'chat-rag',
            'Importar Productos',
            'Importar Productos',
            'manage_options',
            'chat-rag-import-products',
            [$this, 'renderImportProducts']
        );
        add_submenu_page(
        'chat-rag',
        'Importar Empresa (Simple)',
        'Importar Empresa',
        'manage_options',
        'chat-rag-import-company-simple',
        [$this, 'renderImportCompanySimple']
    );
        add_submenu_page(
            'chat-rag',
            'Importar Empresa',
            'Importar Empresa',
            'manage_options',
            'chat-rag-import-company',
            [$this, 'renderImportCompany']
        );
        
        add_submenu_page(
            'chat-rag',
            'Ver Productos',
            'Ver Productos',
            'manage_options',
            'chat-rag-products',
            [$this, 'renderProducts']
        );
        
        add_submenu_page(
            'chat-rag',
            'Info Empresa',
            'Info Empresa',
            'manage_options',
            'chat-rag-company',
            [$this, 'renderCompany']
        );
    }
    
    public function renderImportCompanySimple() {
    include CHAT_RAG_PATH . 'admin/pages/import-company-simple.php';
}
    public function adminScripts($hook) {
        if (strpos($hook, 'chat-rag') === false) {
            return;
        }
        
        wp_enqueue_style('chat-rag-admin', CHAT_RAG_URL . 'admin/css/admin-style.css', [], CHAT_RAG_VERSION);
        
        wp_localize_script('jquery', 'chat_rag_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chat_rag_admin_nonce')
        ]);
    }
    
    public function renderDashboard() {
        // Obtener los datos usando los métodos públicos
        $product_count = $this->database->getProductCount();
        $company_count = $this->database->getCompanyCount();
        $tables = $this->database->getTables();
        
        // Incluir la vista
        include CHAT_RAG_PATH . 'admin/pages/dashboard.php';
    }
    
    public function renderImportProducts() {
        include CHAT_RAG_PATH . 'admin/pages/import-products.php';
    }
    
    public function renderImportCompany() {
        include CHAT_RAG_PATH . 'admin/pages/import-company.php';
    }
    
    public function renderProducts() {
        $products = $this->database->getAllProducts();
        include CHAT_RAG_PATH . 'admin/pages/products.php';
    }
    
    public function renderCompany() {
        $company_info = $this->database->getCompanyInfo();
        include CHAT_RAG_PATH . 'admin/pages/company.php';
    }
}