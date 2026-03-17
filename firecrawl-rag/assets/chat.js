jQuery(document).ready(function($) {
    class RAGChat {
        constructor() {
            this.widget = $('#rag-chat-widget');
            this.button = $('#rag-chat-button');
            this.window = $('#rag-chat-window');
            this.closeBtn = $('#rag-chat-close');
            this.messages = $('#rag-chat-messages');
            this.input = $('#rag-chat-input');
            this.sendBtn = $('#rag-chat-send');
            
            this.isOpen = false;
            this.isTyping = false;
            
            this.init();
        }
        
        init() {
            this.button.empty();
            this.sendBtn.html('<svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>');
            this.addTooltip();
            this.bindEvents();
            this.addTypingIndicator();
            this.loadHistory();
            this.adjustHeights(); // Ajusta alturas al inicio
        }

        adjustHeights() {
            // Asegura que el área de mensajes ocupe el espacio correcto
            const headerHeight = this.window.find('#rag-chat-header').outerHeight() || 60;
            const inputHeight = this.window.find('#rag-chat-input-area').outerHeight() || 60;
            const typingHeight = 40; // Altura aproximada del indicador de escritura
            
            const messagesHeight = this.window.height() - headerHeight - inputHeight - typingHeight;
            this.messages.css('max-height', messagesHeight + 'px');
        }

        addTooltip() {
            this.tooltip = $('<div id="rag-chat-tooltip" class="rag-chat-tooltip">¡Hola! ¿Necesitas ayuda? 👋</div>');
            this.widget.append(this.tooltip);
            this.tooltip.on('click', (e) => {
                e.stopPropagation();
                this.toggleChat();
            });
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
                if (e.key === 'Escape' && this.isOpen) {
                    this.toggleChat();
                }
            });

            this.window.on('click', (e) => {
                e.stopPropagation();
            });

            // Reajustar alturas cuando cambie el tamaño de la ventana
            $(window).on('resize', () => this.adjustHeights());
        }
        
        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.window.fadeIn(300);
                this.input.trigger('focus');
                this.tooltip.fadeOut(200);
                setTimeout(() => this.adjustHeights(), 100); // Reajusta después de abrir
            } else {
                this.window.fadeOut(200);
                this.tooltip.fadeIn(200);
            }
        }
        
        sendMessage() {
            const message = this.input.val().trim();
            if (!message) return;
            
            this.addMessage(message, 'user');
            this.input.val('');
            this.showTyping(true);
            
            $.ajax({
                url: rag_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rag_query',
                    question: message,
                    nonce: rag_ajax.nonce
                },
                success: (response) => {
                    this.showTyping(false);
                    if (response.success) {
                        this.addMessage(response.data, 'bot');
                        this.saveToHistory(message, response.data);
                    } else {
                        this.addMessage('Lo siento, hubo un error...', 'bot error');
                    }
                },
                error: () => {
                    this.showTyping(false);
                    this.addMessage('Error de conexión.', 'bot error');
                },
                timeout: 30000
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
            text = text.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
            
            let lines = text.split('\n');
            let inList = false;
            let formattedLines = [];
            
            for (let line of lines) {
                if (line.trim().startsWith('•')) {
                    if (!inList) { 
                        formattedLines.push('<ul>'); 
                        inList = true; 
                    }
                    formattedLines.push(`<li>${line.substring(1).trim()}</li>`);
                } else {
                    if (inList) { 
                        formattedLines.push('</ul>'); 
                        inList = false; 
                    }
                    if (line.trim() !== '') { 
                        formattedLines.push(`<p>${line}</p>`); 
                    }
                }
            }
            if (inList) formattedLines.push('</ul>');
            return formattedLines.join('');
        }
        
        showTyping(show) {
            if (show && !this.isTyping) {
                this.isTyping = true;
                const typingDiv = $('<div>').attr('id', 'rag-chat-typing');
                typingDiv.append('<span class="typing-dot"></span>'.repeat(3));
                this.messages.append(typingDiv);
                this.scrollToBottom();
                setTimeout(() => this.adjustHeights(), 50);
            } else if (!show && this.isTyping) {
                this.isTyping = false;
                $('#rag-chat-typing').remove();
                this.adjustHeights();
            }
        }
        
        scrollToBottom() { 
            this.messages.scrollTop(this.messages[0].scrollHeight); 
        }
        
        getCurrentTime() {
            const now = new Date();
            return now.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
        }
        
        saveToHistory(question, answer) {
            let history = JSON.parse(localStorage.getItem('rag_chat_history') || '[]');
            history.push({ question, answer, timestamp: new Date().toISOString() });
            if (history.length > 20) history = history.slice(-20);
            localStorage.setItem('rag_chat_history', JSON.stringify(history));
        }
        
        loadHistory() {
            const welcomeMsg = '¡Hola! Soy el asistente virtual de **PBTechnologies**.\n\n¿Qué te gustaría saber hoy?';
            this.addMessage(welcomeMsg, 'bot');
        }
    }
    
    const chat = new RAGChat();
    window.ragChat = chat;
});