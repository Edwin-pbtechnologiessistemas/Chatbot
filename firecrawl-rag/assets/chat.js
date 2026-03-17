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
            this.bindEvents();
            this.addTypingIndicator();
            this.loadHistory();
        }
        
        bindEvents() {
            this.button.on('click', () => this.toggleChat());
            this.closeBtn.on('click', () => this.toggleChat());
            
            this.sendBtn.on('click', () => this.sendMessage());
            this.input.on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.toggleChat();
                }
            });
        }
        
        toggleChat() {
            this.isOpen = !this.isOpen;
            
            if (this.isOpen) {
                this.window.fadeIn(300);
                this.input.trigger('focus');
            } else {
                this.window.fadeOut(200);
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
                        this.addMessage('Lo siento, hubo un error procesando tu pregunta. ¿Podrías intentar de nuevo?', 'bot error');
                    }
                },
                error: () => {
                    this.showTyping(false);
                    
                    let errorMsg = 'Error de conexión. ';
                    if (!navigator.onLine) {
                        errorMsg += 'No tienes conexión a internet.';
                    } else {
                        errorMsg += 'Por favor, intenta de nuevo.';
                    }
                    
                    this.addMessage(errorMsg, 'bot error');
                },
                timeout: 30000
            });
        }
        
        addMessage(text, type) {
            const messageDiv = $('<div>').addClass(`rag-message ${type}`);
            
            const formattedText = this.formatMessage(text);
            messageDiv.html(formattedText);
            
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
            
            if (inList) {
                formattedLines.push('</ul>');
            }
            
            return formattedLines.join('');
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
        
        addTypingIndicator() {}
        
        scrollToBottom() {
            this.messages.scrollTop(this.messages[0].scrollHeight);
        }
        
        getCurrentTime() {
            const now = new Date();
            return now.toLocaleTimeString('es-BO', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
        }
        
        saveToHistory(question, answer) {
            let history = JSON.parse(localStorage.getItem('rag_chat_history') || '[]');
            
            history.push({
                question: question,
                answer: answer,
                timestamp: new Date().toISOString()
            });
            
            if (history.length > 20) {
                history = history.slice(-20);
            }
            
            localStorage.setItem('rag_chat_history', JSON.stringify(history));
        }
        
        loadHistory() {
            const welcomeMsg = '¡Hola! Soy el asistente virtual de **PBTechnologies**.\n\n' +
                'Puedo ayudarte con información sobre:\n' +
                '• **Productos** - SONEL, FOTRIC, TROTEC, RIGEL, HOBO\n' +
                '• **Servicios** - Calibración, soporte técnico\n' +
                '• **Empresa** - Quiénes somos, ubicación\n' +
                '• **Contacto** - Teléfonos, email\n\n' +
                '¿Qué te gustaría saber hoy?';
            
            this.addMessage(welcomeMsg, 'bot');
        }
    }
    
    const chat = new RAGChat();
    window.ragChat = chat;
});