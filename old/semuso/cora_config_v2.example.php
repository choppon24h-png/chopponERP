<?php
/**
 * Configurações da API Banco Cora v2
 * 
 * CONFORMIDADE COM DOCUMENTAÇÃO OFICIAL:
 * https://developers.cora.com.br/docs/instrucoes-iniciais
 * https://developers.cora.com.br/reference/emissão-de-boleto-registrado-v2
 * 
 * INSTRUÇÕES:
 * 1. Renomeie este arquivo para cora_config_v2.php
 * 2. Preencha as credenciais obtidas em Conta > Integrações via APIs
 * 3. Certifique-se de que este arquivo não está acessível publicamente
 * 
 * COMO OBTER AS CREDENCIAIS:
 * 
 * 1. Acesse sua conta Cora em https://cora.com.br
 * 2. Vá em Conta > Integrações via APIs
 * 3. Clique em "Criar Integração" ou "Nova Aplicação"
 * 4. Preencha os dados da aplicação
 * 5. Copie o Client ID e Client Secret gerados
 * 6. Salve as credenciais de forma segura
 * 
 * IMPORTANTE:
 * - Nunca commite este arquivo com credenciais reais no Git
 * - Adicione cora_config_v2.php ao .gitignore
 * - Mantenha o Client Secret seguro (nunca exponha no frontend)
 * - Use variáveis de ambiente em produção
 */

// ============================================
// CREDENCIAIS OAUTH 2.0
// ============================================

// Client ID obtido em Conta > Integrações via APIs
define('CORA_CLIENT_ID', 'app-teste-doc');

// Client Secret obtido em Conta > Integrações via APIs
// IMPORTANTE: Nunca exponha esta chave no frontend ou em repositórios públicos
define('CORA_CLIENT_SECRET', '81d231f4-f8e5-4b52-9c08-24dc45321a16');

// Ambiente: 'stage' para testes ou 'production' para produção
define('CORA_ENVIRONMENT', 'stage');

// ============================================
// CONFIGURAÇÕES ADICIONAIS
// ============================================

// Habilitar logs detalhados (desabilitar em produção)
define('CORA_DEBUG_MODE', true);

// Timeout para requisições (em segundos)
define('CORA_REQUEST_TIMEOUT', 30);

// Valor mínimo de boleto (em reais)
define('CORA_MIN_AMOUNT', 5.00);

// ============================================
// DADOS DO BENEFICIÁRIO (PADRÃO)
// ============================================

// Estes dados podem ser sobrescritos por estabelecimento
define('CORA_BENEFICIARY_NAME', 'Sua Empresa LTDA');
define('CORA_BENEFICIARY_DOCUMENT', '12345678000190'); // CNPJ sem formatação
define('CORA_BENEFICIARY_EMAIL', 'financeiro@suaempresa.com.br');

// ============================================
// WEBHOOK (OPCIONAL)
// ============================================

// URL para receber notificações de pagamento de boletos
// Será chamada quando um boleto for pago
define('CORA_WEBHOOK_URL', 'https://seu-dominio.com.br/webhook/cora');

// Token de segurança para validar webhooks
define('CORA_WEBHOOK_SECRET', 'seu-webhook-secret-aqui');

// ============================================
// OBSERVAÇÕES IMPORTANTES
// ============================================

/*
 * FLUXO DE AUTENTICAÇÃO:
 * 
 * 1. Sistema faz requisição POST para https://auth.stage.cora.com.br/oauth/token
 * 2. Envia: grant_type=client_credentials, client_id, client_secret
 * 3. Cora retorna: access_token (válido por 24h) e expires_in
 * 4. Sistema usa o access_token em requisições subsequentes
 * 5. Quando token expirar, solicita um novo automaticamente
 * 
 * ESTRUTURA DO BOLETO:
 * 
 * {
 *   "amount": 10050,                    // Valor em centavos (R$ 100,50)
 *   "due_date": "2025-12-31",          // Data de vencimento
 *   "description": "Royalties Dezembro", // Descrição
 *   "payer": {                          // Dados do pagador
 *     "name": "Cliente LTDA",
 *     "document": "12345678000190",    // CNPJ ou CPF sem formatação
 *     "email": "cliente@empresa.com.br",
 *     "phone": "1133334444"
 *   },
 *   "beneficiary": {                    // Dados do beneficiário
 *     "name": "Sua Empresa LTDA",
 *     "document": "12345678000190",
 *     "email": "financeiro@suaempresa.com.br"
 *   }
 * }
 * 
 * STATUS DO BOLETO:
 * - PENDING: Aguardando pagamento
 * - OVERDUE: Vencido
 * - PAID: Pago
 * - CANCELED: Cancelado
 * - REJECTED: Rejeitado
 * 
 * ENDPOINTS DISPONÍVEIS:
 * - POST /v2/invoices - Criar boleto
 * - GET /v2/invoices/{id} - Consultar boleto
 * - GET /v2/invoices - Listar boletos
 * - DELETE /v2/invoices/{id} - Cancelar boleto
 * 
 * AMBIENTE STAGE vs PRODUCTION:
 * - Stage: Para testes, usa dados fictícios
 * - Production: Para produção, boletos reais
 * - Credenciais diferentes para cada ambiente
 * 
 * PLANO NECESSÁRIO:
 * - CoraPro (R$ 44,90/mês) para acesso a APIs
 * 
 * SUPORTE:
 * - Documentação: https://developers.cora.com.br
 * - Email: suporte@cora.com.br
 */
?>
