/**
 * Chopp On Tap - JavaScript Principal
 * Versão Profissional 2.0
 */

// ========================================
// Menu Toggle (Mobile)
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
        
        // Fechar sidebar ao clicar fora (mobile)
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    }
});

// ========================================
// Toggle Submenu
// ========================================
function toggleSubmenu(event, element) {
    event.preventDefault();
    const parent = element.parentElement;
    const submenu = parent.querySelector('.submenu');
    
    if (submenu) {
        // Toggle classe 'show' no submenu
        submenu.classList.toggle('show');
        
        // Toggle classe 'open' no item pai para rotacionar a seta
        parent.classList.toggle('open');
    }
}

// Auto-expandir submenu se página atual estiver ativa
document.addEventListener('DOMContentLoaded', function() {
    const activeSubmenuItem = document.querySelector('.submenu a.active');
    if (activeSubmenuItem) {
        const submenu = activeSubmenuItem.closest('.submenu');
        const parent = submenu.closest('.menu-item-has-children');
        
        submenu.classList.add('show');
        parent.classList.add('open');
    }
});

// ========================================
// Formatação de Moeda
// ========================================
function formatCurrency(input) {
    let value = input.value.replace(/\D/g, '');
    value = (value / 100).toFixed(2);
    value = value.replace('.', ',');
    value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
    input.value = value;
}

// Auto-aplicar formatação em campos de moeda
// DESABILITADO: Conflita com input type="number" em bebidas.php
// Use input type="number" com step="0.01" para campos monetários
/*
document.addEventListener('DOMContentLoaded', function() {
    const currencyInputs = document.querySelectorAll('input[name="valor"], input[name="taxa_fixa"]');
    currencyInputs.forEach(input => {
        input.addEventListener('input', function() {
            formatCurrency(this);
        });
    });
});
*/

// ========================================
// Formatação de Percentual
// ========================================
function formatPercentage(input) {
    let value = input.value.replace(/\D/g, '');
    if (value.length > 0) {
        value = (parseFloat(value) / 100).toFixed(2);
        input.value = value.replace('.', ',');
    }
}

// Auto-aplicar formatação em campos de percentual
document.addEventListener('DOMContentLoaded', function() {
    const percentInputs = document.querySelectorAll('input[name="taxa_percentual"]');
    percentInputs.forEach(input => {
        input.addEventListener('input', function() {
            formatPercentage(this);
        });
    });
});

// ========================================
// Modal Functions
// ========================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

// Fechar modal ao clicar fora dele
document.addEventListener('DOMContentLoaded', function() {
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    };
});

// ========================================
// Confirmação de Exclusão
// ========================================
function confirmDelete(message = 'Tem certeza que deseja excluir este item?') {
    return confirm(message);
}

// ========================================
// Fechar Alertas
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        // Auto-fechar após 5 segundos
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
        
        // Adicionar botão de fechar se não existir
        if (!alert.querySelector('.close-alert')) {
            const closeBtn = document.createElement('button');
            closeBtn.className = 'close-alert';
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'background: none; border: none; font-size: 20px; cursor: pointer; margin-left: auto; padding: 0 8px;';
            closeBtn.onclick = function() {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            };
            alert.appendChild(closeBtn);
        }
    });
});

// ========================================
// Validação de Formulários
// ========================================
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('is-invalid');
            field.style.borderColor = '#ef4444';
        } else {
            field.classList.remove('is-invalid');
            field.style.borderColor = '';
        }
    });
    
    if (!isValid) {
        alert('Por favor, preencha todos os campos obrigatórios.');
    }
    
    return isValid;
}

// Remover indicação de erro ao digitar
document.addEventListener('DOMContentLoaded', function() {
    const requiredFields = document.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        field.addEventListener('input', function() {
            if (this.value.trim()) {
                this.classList.remove('is-invalid');
                this.style.borderColor = '';
            }
        });
    });
});

// ========================================
// Copiar para Clipboard
// ========================================
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('Copiado para a área de transferência!', 'success');
        }).catch(err => {
            console.error('Erro ao copiar:', err);
        });
    } else {
        // Fallback para navegadores antigos
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Copiado para a área de transferência!', 'success');
    }
}

// ========================================
// Toast Notifications
// ========================================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        animation: slideInRight 0.3s ease;
    `;
    
    const colors = {
        success: '#10b981',
        error: '#ef4444',
        warning: '#f59e0b',
        info: '#06b6d4'
    };
    
    toast.style.borderLeft = `4px solid ${colors[type] || colors.info}`;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}

// Adicionar animações CSS para toast
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    @keyframes slideOutRight {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(100px);
        }
    }
`;
document.head.appendChild(style);

// ========================================
// Loading Overlay
// ========================================
function showLoading(message = 'Carregando...') {
    const overlay = document.createElement('div');
    overlay.id = 'loadingOverlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(4px);
    `;
    
    overlay.innerHTML = `
        <div style="text-align: center; color: white;">
            <div class="spinner" style="
                border: 4px solid rgba(255, 255, 255, 0.3);
                border-top: 4px solid white;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 0 auto 16px;
            "></div>
            <p style="font-size: 16px; font-weight: 600;">${message}</p>
        </div>
    `;
    
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

// Adicionar animação de spinner
const spinnerStyle = document.createElement('style');
spinnerStyle.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(spinnerStyle);

// ========================================
// Máscaras de Input
// ========================================
function maskPhone(input) {
    let value = input.value.replace(/\D/g, '');
    if (value.length <= 10) {
        value = value.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
    } else {
        value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    }
    input.value = value;
}

function maskCPF(input) {
    let value = input.value.replace(/\D/g, '');
    value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    input.value = value;
}

function maskCNPJ(input) {
    let value = input.value.replace(/\D/g, '');
    value = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    input.value = value;
}

function maskCEP(input) {
    let value = input.value.replace(/\D/g, '');
    value = value.replace(/(\d{5})(\d{3})/, '$1-$2');
    input.value = value;
}

// ========================================
// Debounce Function
// ========================================
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ========================================
// Print Function
// ========================================
function printPage() {
    window.print();
}

// ========================================
// Export to CSV
// ========================================
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const csvRow = [];
        cols.forEach(col => {
            csvRow.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(csvRow.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    if (navigator.msSaveBlob) {
        navigator.msSaveBlob(blob, filename);
    } else {
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    }
}

// ========================================
// Inicialização
// ========================================
console.log('Chopp On Tap - Sistema carregado com sucesso! 🍺');
