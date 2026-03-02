# Implementação da Integração Asaas no ChopponERP

**Data:** 12 de Janeiro de 2026  
**Versão:** 1.0.0  
**Status:** ✅ Implementação Completa

---

## 📋 Resumo

Este documento descreve a implementação completa da integração com o gateway de pagamento **Asaas** no sistema ChopponERP, incluindo configuração, processamento de pagamentos, webhooks e integração com o sistema de royalties.

---

## 🎯 Objetivos Alcançados

1. ✅ Análise completa da documentação da API Asaas (docs.asaas.com)
2. ✅ Criação de estrutura de banco de dados para Asaas
3. ✅ Implementação da classe AsaasAPI com todos os métodos necessários
4. ✅ Interface de configuração em admin/asaas_config.php
5. ✅ Integração com processamento de pagamentos de royalties
6. ✅ Webhook para confirmação automática de pagamentos
7. ✅ Sistema de logs completo para auditoria

---

## 📁 Arquivos Criados/Modificados

### Novos Arquivos

1. **`sql/add_asaas_integration.sql`**
   - Script SQL completo para criar todas as tabelas necessárias
   - Tabelas: `asaas_config`, `asaas_clientes`, `asaas_pagamentos`, `asaas_webhooks`, `asaas_logs`
   - Atualização da tabela `royalties` para incluir opção 'asaas'

2. **`includes/AsaasAPI.php`**
   - Classe completa para integração com API Asaas
   - Métodos implementados:
     - `criarCliente()` - Criar cliente no Asaas
     - `buscarOuCriarCliente()` - Buscar ou criar cliente (evita duplicação)
     - `criarCobranca()` - Criar cobrança (boleto, PIX, cartão)
     - `consultarCobranca()` - Consultar status de cobrança
     - `obterQRCodePix()` - Obter QR Code para pagamento PIX
     - `obterLinhaDigitavel()` - Obter linha digitável do boleto
     - `atualizarCobranca()` - Atualizar cobrança existente
     - `excluirCobranca()` - Excluir cobrança
     - `processarWebhook()` - Processar webhooks recebidos
     - `validarConfiguracao()` - Testar conexão com API
     - `mapearStatus()` - Mapear status Asaas para status interno

3. **`admin/asaas_config.php`**
   - Interface completa para configuração do Asaas
   - Funcionalidades:
     - Cadastrar/editar configurações por estabelecimento
     - Testar conexão com API
     - Gerenciar múltiplas configurações
     - Validação de API Keys (sandbox vs produção)
     - Exibição da URL do webhook

4. **`webhook/asaas_webhook.php`**
   - Endpoint para receber notificações do Asaas
   - Funcionalidades:
     - Validação de token (segurança)
     - Idempotência (evita processamento duplicado)
     - Atualização automática de status de royalties
     - Log completo de todos os eventos
     - Tratamento de erros robusto

### Arquivos Modificados

1. **`admin/royalty_processar_pagamento.php`**
   - Adicionado método 'asaas' na validação de métodos
   - Implementada função `processarAsaas()`
   - Criação automática de cliente no Asaas
   - Geração de cobrança com múltiplas formas de pagamento

2. **`admin/royalty_selecionar_pagamento.php`**
   - Adicionada verificação de configuração Asaas
   - Novo card de seleção para pagamento via Asaas
   - Interface visual consistente com outros métodos

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: `asaas_config`
Armazena configurações do Asaas por estabelecimento.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT(11) | ID único da configuração |
| estabelecimento_id | BIGINT(20) | ID do estabelecimento |
| asaas_api_key | VARCHAR(500) | API Key do Asaas |
| asaas_webhook_token | VARCHAR(255) | Token para autenticação de webhooks |
| ambiente | ENUM | 'sandbox' ou 'production' |
| ativo | TINYINT(1) | 1=Ativo, 0=Inativo |
| created_at | TIMESTAMP | Data de criação |
| updated_at | TIMESTAMP | Data de atualização |

### Tabela: `asaas_clientes`
Mapeamento entre clientes locais e clientes no Asaas.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT(11) | ID único |
| cliente_id | BIGINT(20) | ID do cliente local |
| estabelecimento_id | BIGINT(20) | ID do estabelecimento |
| asaas_customer_id | VARCHAR(100) | ID do cliente no Asaas |
| cpf_cnpj | VARCHAR(18) | CPF/CNPJ do cliente |
| data_criacao | TIMESTAMP | Data de criação |
| data_atualizacao | TIMESTAMP | Data de atualização |

### Tabela: `asaas_pagamentos`
Armazena informações de pagamentos criados no Asaas.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT(11) | ID único |
| conta_receber_id | INT(11) | ID da conta a receber (royalty) |
| estabelecimento_id | BIGINT(20) | ID do estabelecimento |
| asaas_payment_id | VARCHAR(100) | ID do pagamento no Asaas |
| asaas_customer_id | VARCHAR(100) | ID do cliente no Asaas |
| tipo_cobranca | ENUM | BOLETO, CREDIT_CARD, PIX, UNDEFINED |
| valor | DECIMAL(10,2) | Valor da cobrança |
| data_vencimento | DATE | Data de vencimento |
| status_asaas | VARCHAR(50) | Status retornado pelo Asaas |
| url_boleto | VARCHAR(500) | URL do boleto |
| linha_digitavel | VARCHAR(500) | Linha digitável do boleto |
| qr_code_pix | TEXT | QR Code PIX |
| payload_pix | TEXT | Payload PIX |
| nosso_numero | VARCHAR(100) | Nosso número |
| url_fatura | VARCHAR(500) | URL da fatura |
| data_pagamento | TIMESTAMP | Data do pagamento |
| data_confirmacao | TIMESTAMP | Data de confirmação |
| data_credito | DATE | Data de crédito |
| valor_liquido | DECIMAL(10,2) | Valor líquido recebido |
| payload_completo | JSON | Payload completo do Asaas |
| data_criacao | TIMESTAMP | Data de criação |
| data_atualizacao | TIMESTAMP | Data de atualização |

### Tabela: `asaas_webhooks`
Log de webhooks recebidos do Asaas.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT(11) | ID único |
| event_id | VARCHAR(255) | ID único do evento no Asaas |
| event_type | VARCHAR(100) | Tipo do evento |
| asaas_payment_id | VARCHAR(100) | ID do pagamento no Asaas |
| payload | JSON | Payload completo do webhook |
| processado | TINYINT(1) | 0=Pendente, 1=Processado |
| data_recebimento | TIMESTAMP | Data de recebimento |
| data_processamento | TIMESTAMP | Data de processamento |
| erro_mensagem | TEXT | Mensagem de erro (se houver) |

### Tabela: `asaas_logs`
Log de todas as operações com a API Asaas.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT(11) | ID único |
| operacao | VARCHAR(100) | Tipo de operação |
| status | VARCHAR(50) | Status da operação |
| estabelecimento_id | BIGINT(20) | ID do estabelecimento |
| dados_requisicao | JSON | Dados enviados para API |
| dados_resposta | JSON | Resposta da API |
| mensagem_erro | TEXT | Mensagem de erro (se houver) |
| data_criacao | TIMESTAMP | Data de criação |

---

## 🔄 Fluxo de Pagamento

### 1. Configuração Inicial
1. Administrador acessa `admin/asaas_config.php`
2. Cadastra API Key do Asaas (sandbox ou produção)
3. Opcionalmente configura token de webhook
4. Sistema valida conexão com API

### 2. Processamento de Pagamento
1. Usuário seleciona royalty a pagar
2. Sistema exibe opções de pagamento disponíveis (incluindo Asaas)
3. Usuário seleciona "Pagar com Asaas"
4. Sistema:
   - Busca ou cria cliente no Asaas
   - Cria cobrança com tipo UNDEFINED (cliente escolhe método)
   - Salva informações no banco
   - Redireciona para página de pagamento do Asaas

### 3. Confirmação via Webhook
1. Cliente efetua pagamento no Asaas
2. Asaas envia webhook para `webhook/asaas_webhook.php`
3. Sistema valida token (se configurado)
4. Verifica idempotência (evita processamento duplicado)
5. Atualiza status do royalty
6. Registra log de pagamento
7. Responde com HTTP 200 (sucesso)

---

## 🔐 Segurança

### Validação de Token
- Token configurável em `asaas_config`
- Enviado pelo Asaas no header `asaas-access-token`
- Validado antes de processar webhook

### Idempotência
- Cada evento possui ID único (`event_id`)
- Sistema verifica se evento já foi processado
- Evita duplicação de pagamentos

### Logs Completos
- Todos os eventos são registrados em `asaas_logs`
- Webhooks salvos em `asaas_webhooks`
- Arquivo de log: `logs/asaas_webhook.log`

---

## 📊 Eventos de Webhook Suportados

| Evento | Descrição | Ação do Sistema |
|--------|-----------|-----------------|
| PAYMENT_CREATED | Nova cobrança criada | Registra log |
| PAYMENT_UPDATED | Cobrança atualizada | Atualiza informações |
| PAYMENT_CONFIRMED | Pagamento confirmado | Atualiza status para 'confirmado' |
| PAYMENT_RECEIVED | Cobrança recebida | Atualiza status para 'pago' |
| PAYMENT_OVERDUE | Cobrança vencida | Mantém status 'pendente' |
| PAYMENT_DELETED | Cobrança removida | Registra log |
| PAYMENT_RESTORED | Cobrança restaurada | Registra log |
| PAYMENT_REFUNDED | Cobrança estornada | Atualiza status para 'cancelado' |
| PAYMENT_ANTICIPATED | Cobrança antecipada | Registra log |

---

## 🧪 Testes

### Ambiente Sandbox
- URL: `https://api-sandbox.asaas.com/v3`
- API Keys começam com: `$aact_hmlg_`
- Emails e SMS funcionam normalmente
- **NÃO usar dados reais de clientes**

### Ambiente Produção
- URL: `https://api.asaas.com/v3`
- API Keys começam com: `$aact_prod_`
- Pagamentos reais são processados
- Usar apenas após testes completos em sandbox

### Testar Configuração
1. Acessar `admin/asaas_config.php`
2. Clicar no botão "Testar" ao lado da configuração
3. Sistema faz requisição de teste à API
4. Exibe mensagem de sucesso ou erro

---

## 📝 Como Usar

### 1. Instalação
```sql
-- Executar script SQL
mysql -u usuario -p banco_de_dados < sql/add_asaas_integration.sql
```

### 2. Configuração
1. Acessar: `https://seu-dominio.com/admin/asaas_config.php`
2. Clicar em "Nova Configuração"
3. Selecionar estabelecimento
4. Escolher ambiente (Sandbox ou Produção)
5. Colar API Key do Asaas
6. (Opcional) Configurar token de webhook
7. Salvar

### 3. Configurar Webhook no Asaas
1. Acessar painel do Asaas
2. Ir em **Minha Conta → Integrações → Webhooks**
3. Clicar em "Novo Webhook"
4. URL: `https://seu-dominio.com/webhook/asaas_webhook.php`
5. Token: (mesmo configurado no sistema)
6. Eventos: Selecionar eventos de cobrança
7. Salvar

### 4. Processar Pagamento
1. Acessar lista de royalties
2. Clicar em "Pagar" no royalty desejado
3. Selecionar "Asaas"
4. Cliente será redirecionado para página do Asaas
5. Cliente escolhe método (Boleto, PIX ou Cartão)
6. Após pagamento, webhook atualiza automaticamente

---

## 🔧 Manutenção

### Logs
- **Webhooks:** `logs/asaas_webhook.log`
- **Operações API:** Tabela `asaas_logs`
- **Webhooks recebidos:** Tabela `asaas_webhooks`

### Monitoramento
- Verificar tabela `asaas_webhooks` para webhooks não processados
- Analisar `asaas_logs` para erros de API
- Asaas envia email se fila de webhook for pausada

### Troubleshooting

**Problema:** Webhook não está sendo recebido
- Verificar se URL está acessível externamente
- Verificar logs em `logs/asaas_webhook.log`
- Verificar configuração no painel do Asaas
- Testar URL manualmente com curl

**Problema:** Token inválido
- Verificar se token em `asaas_config` é igual ao configurado no Asaas
- Token é case-sensitive

**Problema:** Cliente não é criado
- Verificar se CPF/CNPJ está válido
- Verificar logs em `asaas_logs`
- Testar API Key em `admin/asaas_config.php`

---

## 📚 Referências

- **Documentação Oficial:** https://docs.asaas.com/
- **API Reference:** https://docs.asaas.com/reference
- **Webhooks:** https://docs.asaas.com/docs/sobre-os-webhooks
- **Eventos:** https://docs.asaas.com/docs/eventos-de-webhooks
- **Suporte:** https://www.asaas.com/suporte

---

## 👥 Integração com Royalties

### Quando Royalty é Criado
- Cliente no Asaas = Estabelecimento
- Sistema busca ou cria cliente automaticamente
- CPF/CNPJ do estabelecimento é usado

### Dados Enviados
- **customer_id:** ID do estabelecimento no Asaas
- **valor:** Valor do royalty
- **vencimento:** +7 dias da data atual
- **descrição:** Período do royalty
- **referencia_externa:** `ROYALTY_{id}`

### Atualização de Status
- **PAYMENT_RECEIVED:** Status → 'pago'
- **PAYMENT_CONFIRMED:** Status → 'confirmado'
- **PAYMENT_REFUNDED:** Status → 'cancelado'
- **PAYMENT_OVERDUE:** Status → 'pendente'

---

## ✅ Checklist de Implementação

- [x] Estrutura de banco de dados criada
- [x] Classe AsaasAPI implementada
- [x] Interface de configuração criada
- [x] Integração com royalties implementada
- [x] Webhook implementado
- [x] Sistema de logs implementado
- [x] Documentação completa
- [x] Validação de segurança (token)
- [x] Idempotência implementada
- [x] Mapeamento de status implementado

---

## 🚀 Próximos Passos (Opcional)

1. Implementar split de pagamento (divisão de valores)
2. Adicionar suporte a assinaturas recorrentes
3. Implementar link de pagamento direto
4. Adicionar relatórios de pagamentos Asaas
5. Implementar antecipação de recebíveis

---

**Desenvolvido por:** ChopponERP Team  
**Data:** 12/01/2026  
**Versão do Sistema:** 1.0.0
