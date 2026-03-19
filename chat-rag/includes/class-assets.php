<?php
class ChatRAG_Assets {
    
    public function __construct() {
        add_action('wp_footer', [$this, 'renderChatWidget']);
    }
    
    public function renderChatWidget() {
        ?>
        <div id="rag-chat-widget">
            <div id="rag-chat-button"></div>
            <div id="rag-chat-window">
                <div id="rag-chat-header">
                    <span>Asistente PBT</span>
                    <button id="rag-chat-close">×</button>
                </div>
                <div id="rag-chat-messages"></div>
                <div id="rag-chat-input-area">
                    <input type="text" id="rag-chat-input" placeholder="Escribe tu pregunta...">
                    <button id="rag-chat-send"></button>
                </div>
            </div>
        </div>
        <?php
    }
}