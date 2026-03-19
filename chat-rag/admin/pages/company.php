<div class="wrap">
    <h1>Información de la Empresa</h1>
    
    <?php if (empty($company_info)): ?>
        <div class="notice notice-warning">
            <p>No hay información de empresa importada todavía. <a href="<?php echo admin_url('admin.php?page=chat-rag-import-company'); ?>">Importar información</a></p>
        </div>
    <?php else: ?>
        <div class="company-info-grid">
            <?php 
            $grouped = [];
            foreach ($company_info as $info) {
                $grouped[$info->info_type][] = $info;
            }
            ?>
            
            <?php foreach ($grouped as $type => $items): ?>
                <div class="info-section">
                    <h2><?php echo ucfirst($type); ?></h2>
                    
                    <?php foreach ($items as $item): ?>
                        <div class="info-card">
                            <h3><?php echo esc_html($item->title); ?></h3>
                            <div class="content"><?php echo nl2br(esc_html($item->content)); ?></div>
                            <?php if (!empty($item->subcontent)): ?>
                                <div class="subcontent"><?php echo nl2br(esc_html($item->subcontent)); ?></div>
                            <?php endif; ?>
                            <div class="keywords"><small>Keywords: <?php echo esc_html($item->keywords); ?></small></div>
                            <div class="actions">
                                <a href="#" class="edit" data-id="<?php echo $item->id; ?>">Editar</a> |
                                <a href="#" class="delete" data-id="<?php echo $item->id; ?>">Eliminar</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <style>
        .company-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .info-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .info-section h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #da291c;
            text-transform: capitalize;
        }
        
        .info-card {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .info-card h3 {
            margin-top: 0;
            color: #333;
        }
        
        .content {
            line-height: 1.6;
        }
        
        .subcontent {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #e0e0e0;
            color: #666;
        }
        
        .keywords {
            margin-top: 10px;
            color: #999;
        }
        
        .actions {
            margin-top: 10px;
        }
        </style>
    <?php endif; ?>
</div>