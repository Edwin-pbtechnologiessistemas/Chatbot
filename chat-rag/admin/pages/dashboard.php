<div class="wrap chat-rag-dashboard">
    <h1>ChatRAG - Asistente Inteligente</h1>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-content">
                <h3>Productos</h3>
                <p class="stat-number"><?php echo intval($product_count); ?></p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div class="stat-content">
                <h3>Info Empresa</h3>
                <p class="stat-number"><?php echo intval($company_count); ?></p>
            </div>
        </div>
    </div>
    
    <div class="info-boxes">
        <div class="info-box">
            <h2>📋 Estructura de Productos</h2>
            <p>Tu Excel debe tener estas columnas:</p>
            <pre>product_name,category,subcategory,brand,short_description,long_description,specifications,price,availability,product_url</pre>
            <p><strong>Especificaciones:</strong> Usa punto y coma (;) para separar</p>
            <p><strong>Keywords:</strong> Se generan automáticamente</p>
        </div>
        
        <div class="info-box">
            <h2>📋 Estructura de Empresa</h2>
            <p>Tu Excel debe tener estas columnas:</p>
            <pre>info_type,title,content,subcontent,order_index,keywords</pre>
            <p><strong>Tipos disponibles:</strong> empresa, ubicacion, contacto, horario, servicios, mision, marcas</p>
            <p><strong>Keywords:</strong> Palabras clave para búsqueda</p>
        </div>
    </div>
    
    <div class="quick-actions">
        <h2>Acciones rápidas</h2>
        <div class="action-buttons">
            <a href="<?php echo admin_url('admin.php?page=chat-rag-import-products'); ?>" class="button button-primary">Importar Productos</a>
            <a href="<?php echo admin_url('admin.php?page=chat-rag-import-company'); ?>" class="button button-primary">Importar Empresa</a>
            <a href="<?php echo admin_url('admin.php?page=chat-rag-products'); ?>" class="button">Ver Productos</a>
            <a href="<?php echo admin_url('admin.php?page=chat-rag-company'); ?>" class="button">Ver Info Empresa</a>
        </div>
    </div>
</div>

<style>
.chat-rag-dashboard {
    max-width: 1200px;
    margin: 20px auto;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.stat-icon {
    font-size: 48px;
}

.stat-content h3 {
    margin: 0 0 5px 0;
    color: #666;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #da291c;
    margin: 0;
}

.info-boxes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.info-box {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.info-box h2 {
    margin-top: 0;
    color: #23282d;
    border-bottom: 2px solid #da291c;
    padding-bottom: 10px;
}

.info-box pre {
    background: #f5f5f5;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

.quick-actions {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-top: 30px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-buttons .button {
    padding: 10px 20px;
    height: auto;
}
</style>