<?php
// includes/class-rag-chat.php

class RAG_Chat {
    private $db;
    private $urls_marcas = [
        'hobo' => 'https://pbt.com.bo/etiqueta-producto/hobo/',
        'fotric' => 'https://pbt.com.bo/etiqueta-producto/fotric/',
        'sonel' => 'https://pbt.com.bo/etiqueta-producto/sonel/',
        'mpower' => 'https://pbt.com.bo/etiqueta-producto/mpower/',
        'rigel' => 'https://pbt.com.bo/etiqueta-producto/rigel-medical/',
        'trotec' => 'https://pbt.com.bo/etiqueta-producto/trotec/',
        'intoxilyzer' => 'https://pbt.com.bo/etiqueta-producto/cmi-intoxilyzer/'
    ];
    
    public function __construct() {
        $this->db = new RAG_Database();
    }
    
    public function process_question($question) {
        $question_lower = strtolower($question);
        $question_lower = trim($question_lower);
        
        // =============================================
        // RESPUESTAS PROGRAMADAS (ALTA PRIORIDAD)
        // =============================================
        
        // SALUDOS
        $saludos = ['hola', 'buenas', 'buenos dias', 'buenos días', 'buenas tardes', 'buenas noches', 'hey', 'hi', 'hello'];
        foreach ($saludos as $saludo) {
            if (strpos($question_lower, $saludo) !== false && strlen($question_lower) < 20) {
                return "🤖 **PBTechnologies**\n\n" .
                       "¡Hola! Soy el asistente virtual. ¿En qué puedo ayudarte?\n\n" .
                       "**Puedes preguntarme sobre:**\n" .
                       "• **La empresa** (quienes son, que ofrecen, servicios)\n" .
                       "• **Productos** específicos (ej: 'multimetro sonel cmm 60')\n" .
                       "• **Marcas** (FOTRIC, SONEL, HOBO, TROTEC, RIGEL)\n" .
                       "• **Categorías** (cámaras termográficas, pinzas amperimétricas)\n" .
                       "• **Ubicación** y **contacto**";
            }
        }
        
        // SERVICIOS - DETECCIÓN ESPECÍFICA
        $servicios = [
            'servicios', 'servicio', 'que servicios', 'qué servicios',
            'que ofrecen', 'qué ofrecen', 'que hacen', 'qué hacen',
            'calibracion', 'calibración', 'soporte', 'tecnico', 'técnico',
            'mantenimiento', 'reparacion', 'reparación', 'asesoria', 'asesoría',
            'consulta', 'consultas', 'ayuda', 'asistencia'
        ];
        
        foreach ($servicios as $palabra) {
            if (strpos($question_lower, $palabra) !== false) {
                return $this->respuesta_servicios();
            }
        }
        
        // QUIÉNES SOMOS / EMPRESA
        $quienes_somos = [
            'quienes son', 'quiénes son', 'quienes somos', 'quiénes somos',
            'que es pbtechnologi', 'qué es pbtechnologi', 'que es pbtechnologies',
            'que son', 'qué son', 'que hacen', 'qué hacen',
            'empresa', 'nosotros', 'acerca de', 'sobre ustedes', 'pbtechnologies'
        ];
        
        foreach ($quienes_somos as $frase) {
            if (strpos($question_lower, $frase) !== false) {
                return $this->respuesta_empresa();
            }
        }
        
        // UBICACIÓN
        $ubicacion = ['ubicacion', 'ubicación', 'donde', 'dónde', 'direccion', 'dirección', 'encuentran', 'estan', 'están'];
        foreach ($ubicacion as $palabra) {
            if (strpos($question_lower, $palabra) !== false) {
                return $this->respuesta_ubicacion();
            }
        }
        
        // CONTACTO
        $contacto = ['contacto', 'telefono', 'teléfono', 'whatsapp', 'email', 'correo', 'celular', 'llamar'];
        foreach ($contacto as $palabra) {
            if (strpos($question_lower, $palabra) !== false) {
                return $this->respuesta_contacto();
            }
        }
        
        // =============================================
        // DETECCIÓN DE "DAME PRODUCTOS DE [MARCA]"
        // =============================================
        
        foreach ($this->urls_marcas as $marca => $url) {
            if (strpos($question_lower, "productos de {$marca}") !== false || 
                strpos($question_lower, "productos {$marca}") !== false ||
                strpos($question_lower, "dame productos de {$marca}") !== false ||
                strpos($question_lower, "muéstrame productos de {$marca}") !== false ||
                strpos($question_lower, "quiero ver productos de {$marca}") !== false) {
                
                return "🤖 **Productos {$marca}**\n\n" .
                       "Puedes ver todos los productos de la marca **" . strtoupper($marca) . "** en el siguiente enlace:\n\n" .
                       "🔗 **Catálogo completo:** [Productos {$marca}]({$url})\n\n" .
                       "¿Quieres información sobre algún producto específico de esta marca?";
            }
        }
        
        // =============================================
        // DETECCIÓN DE MARCAS (sin número de modelo)
        // =============================================
        
        $marcas_conocidas = [
            'sonel' => 'respuesta_marca_sonel',
            'fotric' => 'respuesta_marca_fotric',
            'hobo' => 'respuesta_marca_hobo',
            'trotec' => 'respuesta_marca_trotec',
            'rigel' => 'respuesta_marca_rigel',
            'mpower' => 'respuesta_marca_mpower',
            'intoxilyzer' => 'respuesta_marca_intoxilyzer'
        ];
        
        foreach ($marcas_conocidas as $marca => $funcion) {
            if (strpos($question_lower, $marca) !== false) {
                // Verificar si hay números de modelo (ej: cmm60, mpi507)
                $tiene_modelo = false;
                foreach (explode(' ', $question_lower) as $palabra) {
                    if (preg_match('/[a-z]+\d+/', $palabra) || preg_match('/\d+[a-z]+/', $palabra)) {
                        $tiene_modelo = true;
                        break;
                    }
                }
                
                if (!$tiene_modelo && strlen($question_lower) < 30) {
                    return $this->$funcion();
                }
            }
        }
        
        // =============================================
        // DETECCIÓN DE CATEGORÍAS
        // =============================================
        
        $categorias = [
            'electrico' => ['eléctrico', 'electrico', 'electricos', 'eléctricos', 'medicion electrica', 'parametros electricos', 'voltaje', 'corriente', 'potencia'],
            'temperatura' => ['temperatura', 'termico', 'termica', 'termográfico', 'termografica', 'calor', 'grado', 'termometro'],
            'pinzas' => ['pinza', 'pinzas', 'amperimetrica', 'amperimetricas', 'amperometrica'],
            'presion' => ['presión', 'presion', 'presiones', 'bar', 'psi'],
            'humedad' => ['humedad', 'humedad relativa', 'hr', 'higrometro'],
            'gas' => ['gas', 'gases', 'detector de gas', 'co2', 'oxigeno'],
            'vibracion' => ['vibración', 'vibracion', 'vibraciones', 'aceleracion'],
            'distancia' => ['distancia', 'distanciometro', 'laser', 'medidor laser'],
            'medico' => ['médico', 'medico', 'hospital', 'equipos medicos', 'rigel']
        ];
        
        foreach ($categorias as $categoria => $palabras) {
            foreach ($palabras as $palabra) {
                if (strpos($question_lower, $palabra) !== false) {
                    return $this->buscar_por_categoria($categoria, $question);
                }
            }
        }
        
        // =============================================
        // BÚSQUEDA INTELIGENTE DE PRODUCTOS
        // =============================================
        
        $resultados = $this->buscar_productos_inteligente($question);
        
        if (!empty($resultados)) {
            return $this->formatear_respuesta_producto($resultados, $question);
        }
        
        // =============================================
        // RESPUESTA POR DEFECTO
        // =============================================
        
        return "🤖 **PBTechnologies**\n\n" .
               "No encontré información específica sobre '{$question}'.\n\n" .
               "**Puedes preguntarme sobre:**\n" .
               "• **La empresa** (ej: 'quienes son', 'que ofrecen', 'servicios')\n" .
               "• **Productos** específicos (ej: 'multimetro sonel cmm 60')\n" .
               "• **Marcas** (FOTRIC, SONEL, HOBO, TROTEC, RIGEL)\n" .
               "• **Categorías** (cámaras termográficas, pinzas amperimétricas)\n" .
               "• **Ubicación** y **contacto**";
    }
    
    // RESPUESTA DE SERVICIOS
    private function respuesta_servicios() {
        return "🤖 **PBTechnologies - Servicios**\n\n" .
               "Ofrecemos una amplia gama de servicios especializados en instrumentación industrial:\n\n" .
               "🔧 **Soluciones Industriales**\n" .
               "• Instrumentos de medición para control y monitoreo industrial\n" .
               "• Asesoría en selección de equipos según tu necesidad\n\n" .
               "📊 **Servicios de Calibración**\n" .
               "• Calibración profesional realizada por expertos\n" .
               "• Aseguramos la exactitud de tus equipos\n" .
               "• Certificados de calibración\n\n" .
               "🛠️ **Soporte Técnico Especializado**\n" .
               "• Asesoramiento pre-venta y post-venta\n" .
               "• Soporte técnico por profesionales capacitados\n" .
               "• Garantía de equipos\n" .
               "• Respaldo de marcas representadas\n\n" .
               "💻 **Soluciones Sistematizadas**\n" .
               "• Desarrollo de páginas web\n" .
               "• Herramientas digitales a medida\n" .
               "• Automatización de procesos\n\n" .
               "📍 **Ubicados en:** Santa Cruz de la Sierra, Bolivia\n\n" .
               "¿Necesitas información más específica sobre algún servicio?";
    }
    
    // RESPUESTA DE EMPRESA
    private function respuesta_empresa() {
        return "🤖 **PBTechnologies SRL**\n\n" .
               "Somos una empresa boliviana especializada en **instrumentación industrial** con amplia experiencia en el mercado.\n\n" .
               "**Nuestra misión:**\n" .
               "Proveer los instrumentos de medición más robustos y precisos del mercado, con calidad, garantía y respaldo técnico.\n\n" .
               "**Representamos marcas de prestigio mundial:**\n" .
               "• **FOTRIC** - Cámaras termográficas\n" .
               "• **SONEL** - Instrumentos de medición eléctrica\n" .
               "• **HOBO** - Monitoreo ambiental\n" .
               "• **TROTEC** - Herramientas y equipos de medición\n" .
               "• **RIGEL** - Equipos médicos y de seguridad\n" .
               "• **MPOWER** - Electrónica industrial\n" .
               "• **Intoxilyzer** - Equipos de detección de alcohol\n\n" .
               "**Servicios:**\n" .
               "• Venta de instrumentos\n" .
               "• Calibración profesional\n" .
               "• Soporte técnico especializado\n" .
               "• Asesoría en selección de equipos\n\n" .
               "📍 **Ubicados en:** Santa Cruz de la Sierra, Bolivia\n\n" .
               "¿Te gustaría conocer más sobre alguna marca, servicio o producto en específico?";
    }
    
    private function respuesta_ubicacion() {
        return "🤖 **PBTechnologies - Ubicación**\n\n" .
               "📍 **Dirección:**\n" .
               "• Av. Cristo Redentor, C. Cosorio 2015\n" .
               "• Santa Cruz de la Sierra, Bolivia\n\n" .
               "📞 **Teléfonos:**\n" .
               "• +591 710 33004 (WhatsApp)\n" .
               "• +591 3 3454600 (Fijo)\n\n" .
               "🕒 **Horario de atención:**\n" .
               "• Lunes a Viernes: 9:00 AM - 6:00 PM\n\n" .
               "¿Necesitas indicaciones para llegar?";
    }
    
    private function respuesta_contacto() {
        return "🤖 **PBTechnologies - Contacto**\n\n" .
               "📞 **Teléfonos:**\n" .
               "• WhatsApp: +591 710 33004\n" .
               "• Fijo: +591 3 3454600\n\n" .
               "📧 **Email:**\n" .
               "• info@pbtechnologies.com\n" .
               "• ventas@pbtechnologies.com\n\n" .
               "📍 **Dirección:**\n" .
               "• Av. Cristo Redentor, C. Cosorio 2015\n" .
               "• Santa Cruz de la Sierra, Bolivia\n\n" .
               "🌐 **Web:**\n" .
               "• https://pbt.com.bo\n\n" .
               "**Horario de atención:**\n" .
               "• Lunes a Viernes: 9:00 AM - 6:00 PM\n\n" .
               "¿Necesitas contactar a alguien en específico?";
    }
    
    private function buscar_por_categoria($categoria, $pregunta_original) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'rag_knowledge';
        
        $terminos_categoria = [
            'electrico' => ['eléctrico', 'electrico', 'voltaje', 'corriente', 'potencia', 'frecuencia', 'medicion electrica', 'multimetro', 'pinza'],
            'temperatura' => ['temperatura', 'termico', 'termografica', 'calor', 'grado', 'termometro', 'cámara termográfica'],
            'pinzas' => ['pinza', 'amperimetrica', 'amperometrica', 'cmp'],
            'presion' => ['presión', 'presion', 'bar', 'psi', 'manómetro'],
            'humedad' => ['humedad', 'hr', 'humidity', 'higrómetro'],
            'gas' => ['gas', 'gases', 'detector', 'co2', 'oxígeno'],
            'vibracion' => ['vibración', 'vibracion', 'aceleración'],
            'distancia' => ['distancia', 'distanciometro', 'laser', 'medidor'],
            'medico' => ['médico', 'medico', 'hospital', 'rigel', 'paciente', 'signos vitales']
        ];
        
        $terminos = $terminos_categoria[$categoria] ?? [$categoria];
        
        $condiciones = [];
        $params = [];
        
        foreach ($terminos as $termino) {
            $condiciones[] = "content LIKE %s";
            $params[] = '%' . $wpdb->esc_like($termino) . '%';
        }
        
        $sql = "SELECT DISTINCT source_url, content 
                FROM {$tabla} 
                WHERE content_type = 'producto' 
                AND (" . implode(' OR ', $condiciones) . ")
                GROUP BY source_url
                ORDER BY id DESC
                LIMIT 8";
        
        $resultados = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        if (empty($resultados)) {
            return "🤖 **PBTechnologies**\n\n" .
                   "No encontré productos de la categoría '{$categoria}'.\n\n" .
                   "¿Quieres buscar en otra categoría?";
        }
        
        $respuesta = "🤖 **Productos relacionados con " . ucfirst($categoria) . "**\n\n";
        $respuesta .= "Aquí tienes algunos productos que pueden interesarte:\n\n";
        
        foreach ($resultados as $row) {
            $url = preg_replace('/\/tab-especificaciones-tec.*/', '', $row->source_url);
            $nombre = basename($url);
            $nombre = str_replace('-', ' ', $nombre);
            $nombre = ucwords($nombre);
            
            $respuesta .= "• **{$nombre}**\n";
            $respuesta .= "  🔗 [Ver producto]({$url})\n\n";
        }
        
        $respuesta .= "¿Te gustaría conocer más detalles sobre alguno de estos productos?";
        
        return $respuesta;
    }
    
    private function buscar_productos_inteligente($question) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'rag_knowledge';
        
        $question = strtolower(trim($question));
        
        // Palabras a ignorar
        $verbos = ['tiene', 'tienen', 'tienes', 'tener', 'posee', 'poseen', 'cuenta', 'cuentan', 'dispone', 'disponen', 'incluye', 'incluyen', 'trae', 'traen', 'viene', 'vienen', 'ofrece', 'ofrecen', 'presenta', 'presentan', 'muestra', 'muestran', 'exhibe', 'exhiben', 'muéstrame', 'dime', 'cuéntame', 'quiero', 'quisiera', 'necesito', 'busco', 'estoy', 'buscando', 'hay', 'haber', 'existe', 'existen', 'dame', 'recomienda', 'recomiéndame', 'sugiere', 'sugiéreme'];
        
        $palabras_ignorar = array_merge(['de', 'la', 'el', 'en', 'con', 'para', 'por', 'una', 'un', 'y', 'a', 'que', 'es', 'como', 'los', 'las', 'del', 'al', 'sobre', 'mi', 'tu', 'su', 'mis', 'tus', 'sus', 'me', 'te', 'se', 'nos', 'os'], $verbos);
        
        $palabras = explode(' ', $question);
        $keywords = array_filter($palabras, function($p) use ($palabras_ignorar) {
            return strlen($p) > 2 && !in_array($p, $palabras_ignorar);
        });
        
        if (empty($keywords)) {
            return [];
        }
        
        $condiciones = [];
        $params = [];
        
        foreach ($keywords as $keyword) {
            $condiciones[] = "(content LIKE %s OR keywords LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($keyword) . '%';
            $params[] = '%' . $wpdb->esc_like($keyword) . '%';
        }
        
        $sql = "SELECT * FROM {$tabla} 
                WHERE content_type = 'producto' 
                AND (" . implode(' OR ', $condiciones) . ")
                ORDER BY 
                    CASE 
                        WHEN content LIKE '%Producto:%' THEN 1
                        WHEN content LIKE '%características%' THEN 2
                        WHEN content LIKE '%especificaciones%' THEN 3
                        ELSE 4
                    END,
                    id DESC
                LIMIT 30";
        
        $resultados = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        return $resultados;
    }
    
    private function formatear_respuesta_producto($resultados, $pregunta_original) {
        $productos = [];
        foreach ($resultados as $row) {
            $url = preg_replace('/\/tab-especificaciones-tec.*/', '', $row->source_url);
            
            if (!isset($productos[$url])) {
                $productos[$url] = [
                    'nombre' => '',
                    'caracteristicas' => [],
                    'descripcion' => '',
                    'url' => $url,
                    'score' => 0,
                    'categoria' => '',
                    'marca' => ''
                ];
            }
            
            $content = $row->content;
            
            if (strpos($content, 'Producto:') !== false) {
                $productos[$url]['nombre'] = str_replace('Producto:', '', $content);
                $productos[$url]['nombre'] = trim($productos[$url]['nombre']);
            } 
            elseif (preg_match('/(\d+)\s*[WVAwva]|voltaje|corriente|potencia|rango|precisión|medición|frecuencia|temperatura|humedad|presión|caudal|nivel|distancia|velocidad|aceleración|fuerza|peso|capacidad|dimensiones|medidas|peso|modelo|serie|marca|fabricante|origen|garantía|protección|clase|grado|exactitud|error|linealidad|histéresis|deriva|estabilidad|resolución|sensibilidad|alcance|escala|división|precio|costo|valor|disponible|stock|entrega|envío|transporte|embalaje|accesorios|opciones|configuración|programación|software|aplicación|uso|manejo|operación|funcionamiento|mantenimiento|calibración|ajuste|verificación|comprobación|prueba|test|ensayo|análisis|evaluación|diagnóstico|monitoreo|control|regulación|supervisión|gestion|administración|planificación|diseño|desarrollo|fabricación|producción|comercialización|distribución|venta|compra|adquisición|solicitud|pedido|orden|factura|pago|forma|método|medio|tiempo|plazo|condición|término|política|norma|regla|ley|reglamento|directiva|instrucción|manual|guía|documento|archivo|registro|historial|informe|reporte|lista|catálogo|folleto|ficha|hoja|datos|información|detalle|especificación|característica|propiedad|atributo|cualidad|ventaja|beneficio|valor|prestación|funcionalidad|capacidad|habilidad|aptitud|competencia|experiencia|conocimiento|saber|hacer|poder|querer|deber|haber|estar|ser|tener|hacer|poder|deber|haber|estar|ser|tener|hacer|poder|deber|haber|estar|ser|tener/i', $content)) {
                $productos[$url]['caracteristicas'][] = $content;
            } 
            elseif (strlen($content) > 40 && empty($productos[$url]['descripcion'])) {
                $descripcion_limpia = preg_replace('/[#*_`]/', '', $content);
                $descripcion_limpia = trim($descripcion_limpia);
                $descripcion_limpia = preg_replace('/\s+/', ' ', $descripcion_limpia);
                $productos[$url]['descripcion'] = $descripcion_limpia;
            }
            
            if (preg_match('/Categoría:?\s*([^\n,]+)/i', $content, $matches)) {
                $productos[$url]['categoria'] = trim($matches[1]);
            }
            if (preg_match('/Marca:?\s*([^\n,]+)/i', $content, $matches)) {
                $productos[$url]['marca'] = trim($matches[1]);
            }
            
            $pregunta_lower = strtolower($pregunta_original);
            $content_lower = strtolower($content);
            
            foreach (explode(' ', $pregunta_lower) as $palabra) {
                if (strlen($palabra) > 2 && strpos($content_lower, $palabra) !== false) {
                    $productos[$url]['score'] += 5;
                }
            }
            
            if (isset($productos[$url]['nombre']) && !empty($productos[$url]['nombre'])) {
                $nombre_lower = strtolower($productos[$url]['nombre']);
                foreach (explode(' ', $pregunta_lower) as $palabra) {
                    if (strlen($palabra) > 2 && strpos($nombre_lower, $palabra) !== false) {
                        $productos[$url]['score'] += 20;
                    }
                }
            }
        }
        
        usort($productos, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $producto = $productos[0];
        
        $nombre_producto = trim($producto['nombre']);
        if (empty($nombre_producto)) {
            $nombre_producto = basename($producto['url']);
            $nombre_producto = str_replace('-', ' ', $nombre_producto);
            $nombre_producto = ucwords($nombre_producto);
        }
        
        $respuesta = "🤖 **{$nombre_producto}**\n\n";
        
        $tipo_producto = [];
        if (stripos($nombre_producto, 'termografica') !== false || stripos($nombre_producto, 'camara') !== false) {
            $tipo_producto[] = "📷 Cámara térmica";
        }
        if (stripos($nombre_producto, 'pinza') !== false || stripos($nombre_producto, 'amperimetrica') !== false) {
            $tipo_producto[] = "⚡ Pinza amperimétrica";
        }
        if (stripos($nombre_producto, 'multimetro') !== false || stripos($nombre_producto, 'multímetro') !== false) {
            $tipo_producto[] = "📊 Multímetro";
        }
        if (stripos($nombre_producto, 'temperatura') !== false || stripos($nombre_producto, 'termometro') !== false) {
            $tipo_producto[] = "🌡️ Medidor de temperatura";
        }
        if (stripos($nombre_producto, 'presion') !== false || stripos($nombre_producto, 'presión') !== false) {
            $tipo_producto[] = "⏲️ Medidor de presión";
        }
        
        if (!empty($tipo_producto)) {
            $respuesta .= "**Tipo:** " . implode(' | ', $tipo_producto) . "\n\n";
        }
        
        if (!empty($producto['descripcion']) && strlen($producto['descripcion']) > 30) {
            $respuesta .= $producto['descripcion'] . "\n\n";
        }
        
        if (!empty($producto['caracteristicas'])) {
            $respuesta .= "**Características principales:**\n";
            $caracteristicas_unicas = array_unique($producto['caracteristicas']);
            $contador = 0;
            foreach ($caracteristicas_unicas as $carac) {
                if ($contador >= 8) break;
                
                $carac = preg_replace('/[|*_`]/', '', $carac);
                $carac = trim($carac);
                $carac = preg_replace('/\s+/', ' ', $carac);
                
                if (!empty($carac) && strlen($carac) > 5 && strlen($carac) < 200) {
                    if (preg_match('/[a-zA-Záéíóúñü]/', $carac)) {
                        $respuesta .= "• {$carac}\n";
                        $contador++;
                    }
                }
            }
            $respuesta .= "\n";
        }
        
        if (!empty($producto['categoria']) || !empty($producto['marca'])) {
            $info_extra = [];
            if (!empty($producto['marca'])) {
                $info_extra[] = "Marca: **{$producto['marca']}**";
            }
            if (!empty($producto['categoria'])) {
                $info_extra[] = "Categoría: **{$producto['categoria']}**";
            }
            if (!empty($info_extra)) {
                $respuesta .= implode(' | ', $info_extra) . "\n\n";
            }
        }
        
        $respuesta .= "🔗 **Ver producto:** [Enlace a la página oficial]({$producto['url']})\n\n";
        $respuesta .= "¿Necesitas más información sobre este producto?";
        
        return $respuesta;
    }
    
    private function respuesta_marca_fotric() {
        return "🤖 **FOTRIC - Cámaras Termográficas**\n\n" .
               "FOTRIC es una marca especializada en cámaras termográficas de alta tecnología.\n\n" .
               "**Aplicaciones principales:**\n" .
               "• Mantenimiento predictivo industrial\n" .
               "• Inspecciones eléctricas\n" .
               "• Detección de pérdidas energéticas\n" .
               "• Control de calidad\n\n" .
               "**Modelos disponibles en PBTechnologies:**\n" .
               "• Serie 300 - Profesionales\n" .
               "• Serie 600 - Alta resolución\n" .
               "• Cámaras compactas 160x120 y 384x288\n\n" .
               "🔗 **Catálogo completo:** [Productos FOTRIC]({$this->urls_marcas['fotric']})\n\n" .
               "¿Te interesa algún modelo en particular?";
    }
    
    private function respuesta_marca_sonel() {
        return "🤖 **SONEL - Instrumentos de Medición Eléctrica**\n\n" .
               "SONEL (Polonia) es un fabricante líder en instrumentos de medición eléctrica de alta precisión.\n\n" .
               "**Productos principales:**\n" .
               "• Comprobadores de puesta a tierra\n" .
               "• Medidores de aislamiento\n" .
               "• Analizadores de calidad de energía\n" .
               "• Multímetros industriales\n" .
               "• Pinzas amperimétricas\n\n" .
               "**Modelos destacados en nuestra tienda:**\n" .
               "• **Serie MPI** - Comprobadores multifunción (ej: MPI-507, MPI-502)\n" .
               "• **Serie CMM** - Multímetros de alta gama (ej: CMM-10, CMM-11, CMM-30, CMM-40, CMM-60)\n" .
               "• **Serie CMP** - Pinzas amperimétricas (ej: CMP-100, CMP-200, CMP-400, CMP-1010)\n" .
               "• **Serie MRU** - Medidores de resistencia de tierra\n\n" .
               "🔗 **Catálogo completo:** [Productos SONEL]({$this->urls_marcas['sonel']})\n\n" .
               "¿Qué tipo de medición necesitas realizar?";
    }
    
    private function respuesta_marca_hobo() {
        return "🤖 **HOBO - Monitoreo Ambiental**\n\n" .
               "HOBO (USA) es líder mundial en registradores de datos ambientales.\n\n" .
               "**Aplicaciones:**\n" .
               "• Monitoreo de temperatura en laboratorios\n" .
               "• Control de humedad en almacenes\n" .
               "• Estudios ambientales\n" .
               "• Cadena de frío\n" .
               "• Invernaderos y agricultura\n\n" .
               "**Modelos disponibles:**\n" .
               "• **Serie MX** - Con Bluetooth (MX1101, MX1102, MX2301)\n" .
               "• **Serie UX** - Alta precisión (UX100, UX120)\n" .
               "• **Estaciones meteorológicas** (RX3000, U30)\n\n" .
               "🔗 **Catálogo completo:** [Productos HOBO]({$this->urls_marcas['hobo']})\n\n" .
               "¿Para qué ambiente necesitas monitoreo?";
    }
    
    private function respuesta_marca_trotec() {
        return "🤖 **TROTEC - Herramientas y Equipos de Medición**\n\n" .
               "TROTEC ofrece una amplia gama de herramientas y equipos de medición para profesionales.\n\n" .
               "**Categorías de productos:**\n" .
               "• **Herramientas eléctricas** - Taladros (PRDS), amoladoras (PAGS), sierras\n" .
               "• **Equipos de medición** - Distanciómetros (TD200), niveles láser (BD5A, BD7A)\n" .
               "• **Climatización** - Purificadores de aire, estaciones meteorológicas\n" .
               "• **Estaciones de soldar** (PSIS-10, PSIS-12)\n\n" .
               "🔗 **Catálogo completo:** [Productos TROTEC]({$this->urls_marcas['trotec']})\n\n" .
               "¿Buscas alguna herramienta en específico?";
    }
    
    private function respuesta_marca_rigel() {
        return "🤖 **RIGEL - Equipos Médicos y de Seguridad**\n\n" .
               "RIGEL se especializa en equipos de prueba y medición para el sector médico y de seguridad.\n\n" .
               "**Productos disponibles:**\n" .
               "• **Analizadores de bombas de infusión** (Multi-Flo, UniPulse 400)\n" .
               "• **Simuladores de signos vitales** (Uni-Sim, PATSim 200)\n" .
               "• **Analizadores de ventiladores** (Ventest 800)\n" .
               "• **Equipos de prueba electroquirúrgica** (Uni Therm)\n" .
               "• **Analizadores de flujo de gas** (Citrex H4, Citrex H5)\n\n" .
               "**Aplicaciones:**\n" .
               "• Hospitales y clínicas\n" .
               "• Laboratorios de metrología médica\n" .
               "• Mantenimiento de equipos hospitalarios\n\n" .
               "🔗 **Catálogo completo:** [Productos RIGEL]({$this->urls_marcas['rigel']})\n\n" .
               "¿Qué tipo de equipo médico necesitas verificar?";
    }
    
    private function respuesta_marca_mpower() {
        return "🤖 **MPOWER - Electrónica Industrial**\n\n" .
               "MPOWER ofrece soluciones en electrónica industrial y componentes especializados.\n\n" .
               "🔗 **Catálogo completo:** [Productos MPOWER]({$this->urls_marcas['mpower']})\n\n" .
               "¿Buscas algún componente específico?";
    }
    
    private function respuesta_marca_intoxilyzer() {
        return "🤖 **INTOXILYZER - Equipos de Detección de Alcohol**\n\n" .
               "INTOXILYZER es líder en equipos de detección de alcohol y pruebas de alcoholemia.\n\n" .
               "**Productos disponibles:**\n" .
               "• Intoxilyzer 800 - Equipo profesional de detección\n\n" .
               "🔗 **Catálogo completo:** [Productos Intoxilyzer]({$this->urls_marcas['intoxilyzer']})\n\n" .
               "¿Necesitas información sobre este equipo?";
    }
}