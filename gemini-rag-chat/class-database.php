<?php
class ChatRAG_Database {
    
    private $wpdb;
    private $tables;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tables = [
            'products' => $wpdb->prefix . 'rag_products',
            'company' => $wpdb->prefix . 'rag_company_info'
        ];
    }
    
    public static function createTables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de productos
        $table_products = $wpdb->prefix . 'rag_products';
        $sql1 = "CREATE TABLE IF NOT EXISTS $table_products (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_name varchar(255) NOT NULL,
            category varchar(100) NOT NULL,
            subcategory varchar(100),
            brand varchar(100) NOT NULL,
            short_description text NOT NULL,
            long_description text,
            specifications text NOT NULL,
            price varchar(50),
            availability varchar(50) DEFAULT NULL,
            product_url varchar(500) NOT NULL,
            keywords text NOT NULL,
            embedding longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_brand (brand),
            FULLTEXT idx_product_search (product_name, short_description, specifications, keywords)
        ) $charset_collate;";
        
        // Tabla de información de empresa
        $table_company = $wpdb->prefix . 'rag_company_info';
        $sql2 = "CREATE TABLE IF NOT EXISTS $table_company (
            id int(11) NOT NULL AUTO_INCREMENT,
            info_type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            content text NOT NULL,
            subcontent text,
            order_index int DEFAULT 0,
            keywords text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_info_type (info_type),
            FULLTEXT idx_company_search (title, content, keywords)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }
    
    public function getProductCount() {
        return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['products']}");
    }
    
    public function getCompanyCount() {
        return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['company']}");
    }
    
    public function getProducts($limit = 50, $offset = 0) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['products']} ORDER BY id DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }
    
    public function getAllProducts() {
        return $this->wpdb->get_results("SELECT * FROM {$this->tables['products']} ORDER BY id DESC");
    }
    
    public function getCompanyInfo() {
        return $this->wpdb->get_results("SELECT * FROM {$this->tables['company']} ORDER BY info_type, order_index ASC");
    }
    
    public function getCompanyByType($type) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['company']} WHERE info_type = %s ORDER BY order_index ASC",
                $type
            )
        );
    }
    
    public function insertProduct($data) {
        array_walk_recursive($data, function(&$item) {
            if (is_string($item)) {
                $item = mb_convert_encoding($item, 'UTF-8', 'auto');
            }
        });
        return $this->wpdb->insert($this->tables['products'], $data);
    }
    
    public function insertCompanyInfo($data) {
        return $this->wpdb->insert($this->tables['company'], $data);
    }
    
    public function deleteProduct($id) {
        return $this->wpdb->delete($this->tables['products'], ['id' => $id]);
    }
    
    public function deleteCompanyInfo($id) {
        return $this->wpdb->delete($this->tables['company'], ['id' => $id]);
    }
    
    public function searchProducts($query, $limit = 10) {
        global $wpdb;
        $table = $this->tables['products'];

        $clean_query = $this->simplify_text($query);
        $words = explode(' ', $clean_query);
        $words = array_filter($words, function($w) { return strlen($w) > 2; });

        $sql = $wpdb->prepare(
            "SELECT * FROM $table 
             WHERE product_name LIKE %s 
             OR keywords LIKE %s 
             OR category LIKE %s",
            '%' . $wpdb->esc_like($query) . '%',
            '%' . $wpdb->esc_like($query) . '%',
            '%' . $wpdb->esc_like($query) . '%'
        );
        $results = $wpdb->get_results($sql);

        if (empty($results) && !empty($words)) {
            $conditions = [];
            foreach ($words as $word) {
                $conditions[] = $wpdb->prepare("(product_name LIKE %s OR keywords LIKE %s)", '%' . $wpdb->esc_like($word) . '%', '%' . $wpdb->esc_like($word) . '%');
            }
            $sql_fallback = "SELECT * FROM $table WHERE " . implode(' OR ', $conditions) . " LIMIT $limit";
            $results = $wpdb->get_results($sql_fallback);
        }

        return $results;
    }
    
    private function simplify_text($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $unwanted_array = array('á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n');
        return strtr($text, $unwanted_array);
    }
    
    public function searchCompanyInfo($query, $limit = 3) {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tables['company']} 
             WHERE MATCH(title, content, keywords) AGAINST (%s IN NATURAL LANGUAGE MODE)
             OR title LIKE %s
             OR content LIKE %s
             ORDER BY order_index ASC,
                MATCH(title, content, keywords) AGAINST (%s) DESC
             LIMIT %d",
            $query,
            '%' . $this->wpdb->esc_like($query) . '%',
            '%' . $this->wpdb->esc_like($query) . '%',
            $query,
            $limit
        );
        
        return $this->wpdb->get_results($sql);
    }
    
    public function getTables() {
        return $this->tables;
    }
    
    public function getWpdb() {
        return $this->wpdb;
    }
}