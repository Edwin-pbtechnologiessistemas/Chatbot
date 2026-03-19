jQuery(document).ready(function($) {
    class RAGChat {
        constructor() {
            this.widget   = $('#rag-chat-widget');
            this.button   = $('#rag-chat-button');
            this.window   = $('#rag-chat-window');
            this.closeBtn = $('#rag-chat-close');
            this.messages = $('#rag-chat-messages');
            this.input    = $('#rag-chat-input');
            this.sendBtn  = $('#rag-chat-send');

            this.isOpen   = false;
            this.isTyping = false;

            this.init();
        }

        init() {
            this.sendBtn.html('<svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>');
            this.addTooltip();
            this.bindEvents();
            this.loadHistory();
        }

        addTooltip() {
            if (!$('.rag-chat-tooltip').length) {
                this.tooltip = $('<div class="rag-chat-tooltip">¡Hola! ¿Necesitas ayuda? 👋</div>');
                this.widget.append(this.tooltip);
                this.tooltip.on('click', (e) => {
                    e.stopPropagation();
                    this.toggleChat();
                });
            }
        }

        bindEvents() {
            this.button.on('click', (e) => {
                e.stopPropagation();
                this.toggleChat();
            });

            this.closeBtn.on('click', () => this.toggleChat());

            this.sendBtn.on('click', () => this.sendMessage());
            this.input.on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            $(document).on('click', (e) => {
                if (this.isOpen && !$(e.target).closest('#rag-chat-widget').length) {
                    this.toggleChat();
                }
            });

            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) this.toggleChat();
            });

            this.window.on('click', (e) => e.stopPropagation());
        }

        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.window.fadeIn(300);
                this.input.trigger('focus');
                if (this.tooltip) this.tooltip.fadeOut(200);
                setTimeout(() => this.scrollToBottom(), 100);
            } else {
                this.window.fadeOut(200);
                if (this.tooltip) this.tooltip.fadeIn(200);
            }
        }

        sendMessage() {
            const message = this.input.val().trim();
            if (!message || this.isTyping) return;

            this.addMessage(message, 'user');
            this.input.val('');
            this.showTyping(true);

            $.ajax({
                url:  chat_rag.ajax_url,
                type: 'POST',
                data: {
                    action:   'chat_rag_query',
                    question: message,
                    nonce:    chat_rag.nonce
                },
                success: (response) => {
                    this.showTyping(false);

                    if (response.success) {
                        // Soporta tanto string directo como objeto {answer: "..."}
                        const text = (typeof response.data === 'object' && response.data.answer)
                            ? response.data.answer
                            : response.data;
                        this.addMessage(text, 'bot');
                    } else {
                        const errMsg = (typeof response.data === 'object' && response.data.message)
                            ? response.data.message
                            : (response.data || 'Error desconocido');
                        this.addMessage('⚠️ ' + errMsg, 'bot error');
                    }
                },
                error: (xhr, status, error) => {
                    this.showTyping(false);

                    // Log para diagnóstico — ver en DevTools > Console
                    console.error('ChatRAG AJAX error:', {
                        status:       xhr.status,
                        statusText:   xhr.statusText,
                        responseText: xhr.responseText,  // ← aquí verás el error PHP si lo hay
                        error:        error
                    });

                    let userMsg = 'Error de conexión.';
                    if (xhr.status === 403) userMsg = 'Error de seguridad. Recarga la página.';
                    if (xhr.status === 500) userMsg = 'Error interno del servidor. Revisa los logs de PHP.';
                    if (xhr.status === 0)   userMsg = 'Sin conexión. Verifica tu internet.';

                    this.addMessage('⚠️ ' + userMsg, 'bot error');
                }
            });
        }

        addMessage(text, type) {
            const messageDiv = $('<div>').addClass(`rag-message ${type}`);
            messageDiv.html(this.formatMessage(text));
            const time = $('<div>').addClass('message-time').text(this.getCurrentTime());
            messageDiv.append(time);
            this.messages.append(messageDiv);
            this.scrollToBottom();
        }

        formatMessage(text) {
            if (!text) return '';

            // Escapar HTML para evitar XSS, luego aplicar markdown
            text = String(text);

            // URLs → links (antes de escapar para no romper https://)
            text = text.replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

            // Negritas **texto**
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

            // Itálicas _texto_
            text = text.replace(/_(.*?)_/g, '<em>$1</em>');

            // Procesar líneas
            const lines    = text.split('\n');
            let inList     = false;
            let formatted  = [];

            for (const line of lines) {
                const trimmed = line.trim();

                if (trimmed.startsWith('•') || trimmed.startsWith('-')) {
                    if (!inList) { formatted.push('<ul>'); inList = true; }
                    formatted.push(`<li>${trimmed.substring(1).trim()}</li>`);
                } else {
                    if (inList) { formatted.push('</ul>'); inList = false; }
                    if (trimmed !== '') {
                        formatted.push(`<p>${trimmed}</p>`);
                    }
                }
            }

            if (inList) formatted.push('</ul>');

            return formatted.join('');
        }

        showTyping(show) {
            if (show && !this.isTyping) {
                this.isTyping = true;
                const typingDiv = $('<div>').attr('id', 'rag-chat-typing');
                typingDiv.append('<span class="typing-dot"></span>'.repeat(3));
                this.messages.append(typingDiv);
                this.scrollToBottom();
            } else if (!show && this.isTyping) {
                this.isTyping = false;
                $('#rag-chat-typing').remove();
            }
        }

        scrollToBottom() {
            this.messages.scrollTop(this.messages[0].scrollHeight);
        }

        getCurrentTime() {
            return new Date().toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
        }

        loadHistory() {
            const welcome = '**¡Hola!** 👋\n\nSoy el asistente virtual de **PBTechnologies**.\n\n¿En qué puedo ayudarte hoy? Puedes preguntarme sobre:\n• Productos y herramientas\n• Precios y disponibilidad\n• Ubicación y contacto\n• Horarios de atención\n• Servicios que ofrecemos';
            this.addMessage(welcome, 'bot');
        }
    }

    // Inyectar HTML si no existe
    if (!$('#rag-chat-widget').length) {
        $('body').append(`
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
        `);
    }

    new RAGChat();
});