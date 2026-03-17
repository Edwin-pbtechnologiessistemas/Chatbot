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
    
    // FUNCIÓN PRINCIPAL: Extraer TODO (inicio + about + productos)
    public function extract_everything() {
        echo "<h2>🏠 PASO 1: Extrayendo páginas principales</h2>";
        $this->flush_output();
        $this->extract_main_pages();
        
        echo "<h2>🔍 PASO 2: Extrayendo URLs de productos</h2>";
        $this->flush_output();
        $urls_productos = $this->extract_product_urls_directo();
        
        if (empty($urls_productos)) {
            echo '<p style="color:red;">❌ No se encontraron URLs de productos</p>';
            return [];
        }
        
        echo '<p>✅ Total URLs encontradas: <strong>' . count($urls_productos) . '</strong></p>';
        $this->flush_output();
        
        echo "<h2>📦 PASO 3: Extrayendo información de cada producto</h2>";
        $this->flush_output();
        
        $exitosos = 0;
        $fallidos = 0;
        $total = count($urls_productos);
        
        echo '<div style="background:#f0f0f0; padding:15px; max-height:400px; overflow-y:scroll; border:1px solid #ccc; margin:20px 0;">';
        
        foreach ($urls_productos as $index => $url) {
            $numero = $index + 1;
            $nombre = basename($url);
            
            echo "<p><strong>{$numero}/{$total}</strong> - Procesando: {$nombre}...</p>";
            $this->flush_output();
            
            $chunks = $this->extract_url($url, 'producto');
            
            if ($chunks !== false && $chunks > 0) {
                $exitosos++;
                echo "<p style='color:green; margin-left:20px;'>✅ Extraídos {$chunks} fragmentos</p>";
            } else {
                $fallidos++;
                echo "<p style='color:red; margin-left:20px;'>❌ Error al extraer</p>";
            }
            
            $this->flush_output();
            
            // Pausa cada 10 productos
            if ($numero % 10 == 0) {
                $porcentaje = round(($numero / $total) * 100);
                echo "<p style='color:blue;'>⏸️ Progreso: {$numero}/{$total} ({$porcentaje}%) - Pausa de 2 segundos...</p>";
                $this->flush_output();
                sleep(2);
            }
        }
        
        echo '</div>';
        
        // Resumen
        echo '<div style="background:#e8f5e8; padding:15px; margin-top:20px; border-left:4px solid #2e7d32;">';
        echo "<p><strong>📊 RESUMEN FINAL</strong></p>";
        echo "<p>✅ Productos exitosos: {$exitosos}</p>";
        echo "<p>❌ Productos fallidos: {$fallidos}</p>";
        echo "<p>📦 Total procesados: " . ($exitosos + $fallidos) . "</p>";
        echo '</div>';
        
        return $urls_productos;
    }
    
    // Extraer páginas principales (inicio y about-us)
    public function extract_main_pages() {
        $paginas_principales = [
            'https://pbt.com.bo/' => 'inicio',
            'https://pbt.com.bo/about-us/' => 'empresa'
        ];
        
        echo "<p>🔍 Extrayendo páginas principales...</p>";
        $this->flush_output();
        
        foreach ($paginas_principales as $url => $tipo) {
            echo "<p>Extrayendo: {$url} (Tipo: {$tipo})</p>";
            $this->flush_output();
            
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
                echo "<p>❌ Error: " . $response->get_error_message() . "</p>";
                $this->flush_output();
                continue;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $content = $body['data']['markdown'] ?? '';
            
            if (empty($content)) {
                echo "<p>⚠️ No se pudo obtener contenido</p>";
                $this->flush_output();
                continue;
            }
            
            // Limpiar contenido
            $content = $this->clean_content($content);
            
            // Guardar en chunks
            $chunks = $this->split_main_content($content);
            $chunk_count = 0;
            
            foreach ($chunks as $chunk) {
                if (strlen($chunk) > 50) {
                    $keywords = $this->extract_main_keywords($chunk, $tipo);
                    $this->db->save_content($chunk, $tipo, $url, $keywords);
                    $chunk_count++;
                }
            }
            
            echo "<p>✅ Extraídos {$chunk_count} fragmentos de {$tipo}</p>";
            $this->flush_output();
            sleep(1);
        }
        
        echo "<p>✅ Extracción de páginas principales completada</p>";
        $this->flush_output();
    }
    
    // Dividir contenido de páginas principales
    private function split_main_content($content) {
        $chunks = [];
        $parrafos = explode("\n\n", $content);
        
        foreach ($parrafos as $parrafo) {
            $parrafo = trim($parrafo);
            if (strlen($parrafo) > 100 && strlen($parrafo) < 500) {
                $chunks[] = $parrafo;
            }
        }
        
        // Si no hay párrafos largos, tomar oraciones
        if (empty($chunks)) {
            $oraciones = preg_split('/(?<=[.!?])\s+/', $content);
            foreach ($oraciones as $oracion) {
                $oracion = trim($oracion);
                if (strlen($oracion) > 50) {
                    $chunks[] = $oracion;
                }
            }
        }
        
        return $chunks;
    }
    
    // Extraer keywords de páginas principales
    private function extract_main_keywords($text, $tipo) {
        $keywords = [];
        
        $palabras_clave = [
            'inicio' => ['inicio', 'principal', 'bienvenida', 'soluciones', 'industriales', 'medición', 'calibración', 'monitoreo', 'seguridad', 'precisión', 'calidad', 'marcas', 'representadas'],
            'empresa' => ['empresa', 'nosotros', 'about', 'quienes', 'somos', 'historia', 'misión', 'visión', 'valores', 'experiencia', 'clientes', 'testimonios', 'equipo', 'trabajo']
        ];
        
        $lista = $palabras_clave[$tipo] ?? ['información', 'general'];
        
        foreach ($lista as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $keywords[] = $keyword;
            }
        }
        
        return array_slice(array_unique($keywords), 0, 8);
    }
    
    // FUNCIÓN: Extraer URLs de productos
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