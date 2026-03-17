<?php
// includes/class-rag-extractor.php

class RAG_Extractor {
    private $api_key;
    private $api_url = 'https://api.firecrawl.dev/v1';
    private $db;
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
        $this->db = new RAG_Database();
    }
    
    // FUNCIÓN PRINCIPAL: Extraer URLs directamente
    public function extract_product_urls_directo() {
        $todos_productos_urls = [];
        $paginas_tienda = [
            'https://pbt.com.bo/tienda-pbtechnologies-srl/?perpage=64',
            'https://pbt.com.bo/tienda-pbtechnologies-srl/page/2/?perpage=64'
        ];
        
        echo "<p>🔍 Buscando productos en " . count($paginas_tienda) . " páginas de tienda...</p>";
        $this->flush_output();
        
        foreach ($paginas_tienda as $url_tienda) {
            echo "<p>Analizando: " . $url_tienda . "</p>";
            $this->flush_output();
            
            $response = wp_remote_post($this->api_url . '/scrape', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'url' => $url_tienda,
                    'formats' => ['html'],
                    'onlyMainContent' => false,
                    'waitFor' => 3000,
                    'timeout' => 30000
                ]),
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                echo "<p>⚠️ Error con Firecrawl: " . $response->get_error_message() . "</p>";
                $this->flush_output();
                continue;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $html = $body['data']['html'] ?? '';
            
            if (empty($html)) {
                echo "<p>⚠️ No se pudo obtener HTML de la página</p>";
                $this->flush_output();
                continue;
            }
            
            // Extraer URLs de productos del HTML
            preg_match_all('/href=["\'](https?:\/\/pbt\.com\.bo\/producto\/[^"\']+)["\']/', $html, $matches);
            
            if (!empty($matches[1])) {
                $nuevas_urls = array_unique($matches[1]);
                $todos_productos_urls = array_merge($todos_productos_urls, $nuevas_urls);
                echo "<p>✅ Encontrados " . count($nuevas_urls) . " productos en esta página</p>";
                
                // Mostrar algunos ejemplos
                $ejemplos = array_slice($nuevas_urls, 0, 3);
                foreach ($ejemplos as $ej) {
                    echo "<p style='margin-left:20px;'>• " . basename($ej) . "</p>";
                }
            } else {
                echo "<p>⚠️ No se encontraron productos en esta página</p>";
            }
            
            $this->flush_output();
            sleep(2);
        }
        
        return array_unique($todos_productos_urls);
    }
    
    // Extraer información de una URL específica
    public function extract_url($url, $type) {
        $response = wp_remote_post($this->api_url . '/scrape', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'url' => $url,
                'formats' => ['markdown'],
                'onlyMainContent' => true,
                'waitFor' => 2000,
                'timeout' => 15000
            ]),
            'timeout' => 20
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $content = $body['data']['markdown'] ?? '';
        
        if (empty($content)) {
            return false;
        }
        
        $content = $this->clean_content($content);
        
        if ($type == 'producto') {
            $chunks = $this->split_product_content($content, $url);
        } else {
            $chunks = [$content];
        }
        
        $chunk_count = 0;
        foreach ($chunks as $chunk) {
            if (strlen($chunk) > 30) {
                $keywords = $this->extract_keywords($chunk, $url);
                $this->db->save_content($chunk, $type, $url, $keywords);
                $chunk_count++;
            }
        }
        
        return $chunk_count;
    }
    
    // Dividir contenido de producto en chunks
    private function split_product_content($content, $url) {
        $chunks = [];
        
        if (preg_match('/producto\/([^\/]+)/', $url, $matches)) {
            $nombre = str_replace('-', ' ', $matches[1]);
            $chunks[] = "Producto: " . $nombre;
        }
        
        $lineas = explode("\n", $content);
        $importantes = [];
        
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if (strlen($linea) > 20 && strlen($linea) < 200) {
                if (preg_match('/(\d+)\s*[WVAwva]|potencia|voltaje|corriente|medición|rango|precisión|marca|modelo|precio|características|especificaciones/i', $linea)) {
                    $importantes[] = $linea;
                }
            }
        }
        
        if (!empty($importantes)) {
            $chunks = array_merge($chunks, array_slice($importantes, 0, 10));
        } else {
            $chunks[] = substr($content, 0, 400);
        }
        
        return $chunks;
    }
    
    // Limpiar contenido de markdown
    private function clean_content($content) {
        $content = preg_replace('/!\[.*?\]\(.*?\)/', '', $content);
        $content = preg_replace('/!\[\]\(.*?\)/', '', $content);
        $content = preg_replace('/\[.*?\]\(\)/', '', $content);
        $content = preg_replace('/#{1,6}\s*/', '', $content);
        $content = preg_replace('/\[link\]|\(link\)/', '', $content);
        $content = preg_replace('/\[\d+\]/', '', $content);
        return trim($content);
    }
    
    // Extraer keywords para búsqueda
    private function extract_keywords($text, $url) {
        $keywords = [];
        
        $comunes = ['taladro', 'martillo', 'perforador', 'medidor', 'analizador', 
                   'camara', 'termografica', 'sonel', 'fotric', 'hobo', 'ecamec',
                   'pinza', 'amperimetrica', 'multimetro', 'digital', 'industrial',
                   'trotec', 'rigel', 'medicion', 'instrumento', 'voltaje', 'corriente',
                   'potencia', 'resistencia', 'capacitancia', 'frecuencia', 'precio'];
        
        foreach ($comunes as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $keywords[] = $keyword;
            }
        }
        
        if (preg_match('/producto\/([^\/]+)/', $url, $matches)) {
            $url_keywords = explode('-', $matches[1]);
            foreach ($url_keywords as $kw) {
                $kw = strtolower($kw);
                if (strlen($kw) > 2) {
                    $keywords[] = $kw;
                }
            }
        }
        
        return array_slice(array_unique($keywords), 0, 10);
    }
    
    // Función para forzar salida en tiempo real
    private function flush_output() {
        ob_flush();
        flush();
    }
}