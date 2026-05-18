// WebSocket Client for Admin
class WebSocketClient {
    constructor() {
        this.ws = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.connected = false;
        this.listeners = {};
    }
    
    connect() {
        this.ws = new WebSocket('ws://localhost:8080');
        
        this.ws.onopen = () => {
            console.log('✅ Connected to WebSocket Server');
            this.connected = true;
            this.reconnectAttempts = 0;
            
            // Authenticate
            this.send({
                type: 'auth',
                user_id: window.userId,
                role: window.userRole,
                username: window.username
            });
        };
        
        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleMessage(data);
        };
        
        this.ws.onclose = () => {
            console.log('❌ WebSocket Disconnected');
            this.connected = false;
            this.reconnect();
        };
        
        this.ws.onerror = (error) => {
            console.error('WebSocket Error:', error);
        };
    }
    
    reconnect() {
        if(this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            setTimeout(() => this.connect(), 3000 * this.reconnectAttempts);
        }
    }
    
    send(message) {
        if(this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(message));
        }
    }
    
    handleMessage(data) {
        switch(data.type) {
            case 'welcome':
                this.showNotification('Connected to real-time server', 'success');
                break;
                
            case 'auth_success':
                this.showNotification('Authenticated successfully', 'success');
                break;
                
            case 'PRODUCT_LIST':
                this.onProductsReceived(data.payload);
                break;
                
            case 'PRODUCT_CREATED':
                this.showNotification(`🆕 New product: ${data.payload.name} added`, 'info');
                this.refreshProducts();
                break;
                
            case 'PRODUCT_UPDATED':
                this.showNotification(`✏️ Product updated: ${data.payload.name}`, 'info');
                this.refreshProducts();
                break;
                
            case 'PRODUCT_DELETED':
                this.showNotification(`🗑️ Product deleted: ${data.payload.name}`, 'warning');
                this.refreshProducts();
                break;
                
            case 'ORDER_CREATED':
                this.showNotification(`📦 New order #${data.payload.order_number} from ${data.payload.username}`, 'success');
                this.refreshOrders();
                break;
                
            case 'CREATE_SUCCESS':
                this.showNotification(data.message, 'success');
                break;
                
            case 'UPDATE_SUCCESS':
                this.showNotification(data.message, 'success');
                break;
                
            case 'DELETE_SUCCESS':
                this.showNotification(data.message, 'success');
                break;
        }
        
        // Call custom listeners
        if(this.listeners[data.type]) {
            this.listeners[data.type].forEach(callback => callback(data));
        }
    }
    
    on(event, callback) {
        if(!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event].push(callback);
    }
    
    refreshProducts() {
        this.send({ type: 'READ_PRODUCTS', payload: {} });
    }
    
    refreshOrders() {
        this.send({ type: 'READ_ORDERS', payload: {} });
    }
    
    createProduct(product) {
        this.send({
            type: 'CREATE_PRODUCT',
            payload: product
        });
    }
    
    updateProduct(product) {
        this.send({
            type: 'UPDATE_PRODUCT',
            payload: product
        });
    }
    
    deleteProduct(id, name) {
        this.send({
            type: 'DELETE_PRODUCT',
            payload: { id, name }
        });
    }
    
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span>${type === 'success' ? '✅' : type === 'warning' ? '⚠️' : '🔔'}</span>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 5000);
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    window.wsClient = new WebSocketClient();
    window.wsClient.connect();
    
    // Load products on connection
    window.wsClient.on('auth_success', () => {
        window.wsClient.refreshProducts();
        window.wsClient.refreshOrders();
    });
});