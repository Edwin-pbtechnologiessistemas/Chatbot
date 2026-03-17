<?php
// includes/class-rag-database.php

class RAG_Database {
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rag_knowledge';
        $this->create_table();
    }
    
    private function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content longtext NOT NULL,
            content_type varchar(50) NOT NULL,
            source_url varchar(500),
            keywords text,
            embedding longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY content_type (content_type),
            FULLTEXT KEY content_search (content),
            KEY source_url (source_url(191))
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function save_content($content, $type, $url, $keywords = []) {
        global $wpdb;
        
        if (empty($content) || strlen($content) < 20) {
            return false;
        }
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
            WHERE content LIKE %s AND source_url = %s 
            LIMIT 1",
            '%' . $wpdb->esc_like(substr($content, 0, 100)) . '%',
            $url
        ));
        
        if ($exists) {
            return false;
        }
        
        $embedding = $this->create_simple_embedding($content . ' ' . implode(' ', $keywords));
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'content' => $content,
                'content_type' => $type,
                'source_url' => $url,
                'keywords' => implode(',', array_unique($keywords)),
                'embedding' => json_encode($embedding, JSON_UNESCAPED_UNICODE)
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    private function create_simple_embedding($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^a-záéíóúñü0-9\s]/', ' ', $text);
        
        $keywords = [
            'fotric', 'sonel', 'hobo', 'ecamec', 'termografica', 'camara',
            'medicion', 'instrumento', 'calibracion', 'monitoreo', 'ambiental',
            'seguridad', 'electrico', 'precio', 'producto', 'marca', 'servicio',
            'soporte', 'tecnico', 'garantia', 'industrial', 'precision',
            'calidad', 'soluciones', 'empresa', 'nosotros', 'contacto',
            'telefono', 'email', 'ubicacion', 'horario', 'whatsapp',
            'taladro', 'martillo', 'pinza', 'multimetro', 'analizador'
        ];
        
        $embedding = [];
        foreach ($keywords as $keyword) {
            $count = substr_count($text, $keyword);
            if ($count > 0) {
                $embedding[$keyword] = $count;
            }
        }
        
        return $embedding;
    }
    
    public function search_relevant($query, $limit = 10) {
        global $wpdb;
        
        $query_embedding = $this->create_simple_embedding($query);
        
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
            WHERE content IS NOT NULL 
            ORDER BY created_at DESC 
            LIMIT 300"
        );
        
        if (empty($results)) {
            return [];
        }
        
        $scored = [];
        foreach ($results as $row) {
            $score = 0;
            
            $content_embedding = json_decode($row->embedding, true);
            if (is_array($content_embedding)) {
                foreach ($query_embedding as $key => $value) {
                    if (isset($content_embedding[$key])) {
                        $score += min($value, $content_embedding[$key]) * 2;
                    }
                }
            }
            
            $content_lower = strtolower($row->content);
            $query_lower = strtolower($query);
            
            if (strpos($content_lower, $query_lower) !== false) {
                $score += 20;
            }
            
            $query_words = explode(' ', $query_lower);
            foreach ($query_words as $word) {
                if (strlen($word) > 3 && strpos($content_lower, $word) !== false) {
                    $score += 5;
                }
            }
            
            if ($score > 0) {
                $scored[] = [
                    'content' => $row->content,
                    'type' => $row->content_type,
                    'score' => $score,
                    'source' => $row->source_url,
                    'keywords' => $row->keywords
                ];
            }
        }
        
        usort($scored, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return array_slice($scored, 0, $limit);
    }
}