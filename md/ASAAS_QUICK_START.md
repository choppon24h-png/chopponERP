# 🚀 Guia Rápido - Integração Asaas

## ⚡ Instalação em 5 Passos

### 1️⃣ Executar SQL
```bash
# Conectar ao MySQL/MariaDB
mysql -u seu_usuario -p seu_banco_de_dados

# Executar o script
source /caminho/para/sql/add_asaas_integration.sql;
```

Ou via phpMyAdmin:
1. Acessar phpMyAdmin
2. Selecionar banco de dados
3. Clicar em "Importar"
4. Selecionar arquivo `sql/add_asaas_integration.sql`
5. Clicar em "Executar"

### 2️⃣ Obter API Key do Asaas

**Sandbox (Testes):**
1. Acessar: https://sandbox.asaas.com/
2. Fazer login ou criar conta
3. Ir em: **Minha Conta → Integrações → API**
4. Clicar em "Gerar nova chave de API"
5. Copiar chave (começa com `$aact_hmlg_`)

**Produção:**
1. Acessar: https://www.asaas.com/
2. Fazer login
3. Ir em: **Minha Conta → Integrações → API**
4. Clicar em "Gerar nova chave de API"
5. Copiar chave (começa com `$aact_prod_`)

⚠️ **IMPORTANTE:** A chave é exibida apenas UMA vez!

### 3️⃣ Configurar no Sistema

1. Acessar: `https://seu-dominio.com/admin/asaas_config.php`
2. Clicar em "Nova Configuração"
3. Preencher:
   - **Estabelecimento:** Selecionar
   - **Ambiente:** Sandbox (para testes) ou Produção
   - **API Key:** Colar a chave copiada
   - **Webhook Token:** (Opcional) Gerar token aleatório
   - **Ativo:** Marcar checkbox
4. Clicar em "Salvar"
5. Testar conexão clicando no botão "✓"

### 4️⃣ Configurar Webhook no Asaas

1. Acessar painel do Asaas
2. Ir em: **Minha Conta → Integrações → Webhooks**
3. Clicar em "Novo Webhook"
4. Preencher:
   - **Nome:** ChopponERP
   - **URL:** `https://seu-dominio.com/webhook/asaas_webhook.php`
   - **Token de acesso:** (mesmo configurado no passo 3)
   - **Eventos:** Selecionar todos os eventos de "Cobranças"
   - **Status:** Ativo
5. Clicar em "Salvar"

### 5️⃣ Testar Pagamento

1. Acessar: `https://seu-dominio.com/admin/financeiro_royalties.php`
2. Selecionar um royalty pendente
3. Clicar em "Pagar"
4. Selecionar "Asaas"
5. Será redirecionado para página de pagamento
6. Escolher método (Boleto, PIX ou Cartão)
7. Após pagamento, sistema atualiza automaticamente via webhook

---

## 🧪 Testar em Sandbox

### Dados de Teste (Sandbox)

**Cliente Teste:**
- Nome: João da Silva
- CPF: 111.111.111-11
- Email: teste@teste.com

**Pagamento PIX:**
- QR Code é gerado automaticamente
- Pagamento é instantâneo no sandbox

**Boleto:**
- Linha digitável é gerada
- Simular pagamento no painel do Asaas

**Cartão de Crédito:**
- Número: 5162306219378829
- Validade: Qualquer data futura
- CVV: 318
- Nome: Teste Teste

---

## 🔍 Verificar Funcionamento

### Logs
```bash
# Ver logs de webhook
tail -f logs/asaas_webhook.log

# Ver logs de erros PHP
tail -f logs/php_errors.log
```

### Banco de Dados
```sql
-- Verificar webhooks recebidos
SELECT * FROM asaas_webhooks ORDER BY data_recebimento DESC LIMIT 10;

-- Verificar pagamentos
SELECT * FROM asaas_pagamentos ORDER BY data_criacao DESC LIMIT 10;

-- Verificar logs de operações
SELECT * FROM asaas_logs ORDER BY data_criacao DESC LIMIT 10;

-- Verificar clientes criados
SELECT * FROM asaas_clientes ORDER BY data_criacao DESC LIMIT 10;
```

---

## ⚠️ Problemas Comuns

### Webhook não está sendo recebido
```bash
# Testar se URL está acessível
curl -X POST https://seu-dominio.com/webhook/asaas_webhook.php \
  -H "Content-Type: application/json" \
  -d '{"event":"PAYMENT_CREATED","payment":{"id":"test"}}'

# Verificar logs
tail -f logs/asaas_webhook.log
```

**Soluções:**
- Verificar se URL está acessível externamente
- Verificar firewall/CloudFlare
- Verificar se webhook está ativo no Asaas
- Verificar token (se configurado)

### Erro ao criar cliente
**Causa:** CPF/CNPJ inválido

**Solução:**
```sql
-- Verificar CPF/CNPJ no banco
SELECT id, name, cnpj FROM estabelecimentos WHERE id = X;

-- Atualizar se necessário
UPDATE estabelecimentos SET cnpj = '12345678000190' WHERE id = X;
```

### Erro "Configuração não encontrada"
**Solução:**
```sql
-- Verificar configuração
SELECT * FROM asaas_config WHERE estabelecimento_id = X;

-- Verificar se está ativa
UPDATE asaas_config SET ativo = 1 WHERE id = X;
```

---

## 📊 Monitoramento

### Dashboard Rápido
```sql
-- Resumo de pagamentos Asaas
SELECT 
    status_asaas,
    COUNT(*) as total,
    SUM(valor) as valor_total
FROM asaas_pagamentos
GROUP BY status_asaas;

-- Webhooks não processados
SELECT COUNT(*) as pendentes
FROM asaas_webhooks
WHERE processado = 0;

-- Últimos erros
SELECT 
    operacao,
    mensagem_erro,
    data_criacao
FROM asaas_logs
WHERE status = 'erro'
ORDER BY data_criacao DESC
LIMIT 5;
```

---

## 🔄 Migração Sandbox → Produção

1. **Testar completamente em Sandbox**
   - Criar cobrança
   - Pagar via PIX, Boleto e Cartão
   - Verificar webhooks
   - Verificar atualização de status

2. **Obter API Key de Produção**
   - Acessar conta Asaas produção
   - Gerar nova chave (começa com `$aact_prod_`)

3. **Atualizar Configuração**
   ```sql
   UPDATE asaas_config 
   SET ambiente = 'production',
       asaas_api_key = '$aact_prod_XXXXXXXX'
   WHERE estabelecimento_id = X;
   ```

4. **Atualizar Webhook no Asaas**
   - Criar novo webhook em produção
   - Usar mesma URL e token

5. **Testar com Pagamento Real**
   - Fazer teste com valor baixo (R$ 1,00)
   - Verificar se webhook funciona
   - Verificar se status atualiza

---

## 📞 Suporte

### Documentação Completa
- Ver arquivo: `ASAAS_IMPLEMENTATION.md`

### Asaas
- Documentação: https://docs.asaas.com/
- Suporte: https://www.asaas.com/suporte
- Discord: https://discord.gg/asaas

### ChopponERP
- Verificar logs em `logs/asaas_webhook.log`
- Verificar tabela `asaas_logs`
- Contatar desenvolvedor

---

## ✅ Checklist Pós-Instalação

- [ ] SQL executado com sucesso
- [ ] API Key configurada
- [ ] Conexão testada (botão verde ✓)
- [ ] Webhook configurado no Asaas
- [ ] Teste de pagamento realizado
- [ ] Webhook recebido e processado
- [ ] Status do royalty atualizado
- [ ] Logs verificados

---

**Tempo estimado de instalação:** 15-20 minutos  
**Dificuldade:** ⭐⭐ Intermediário

🎉 **Pronto! Sistema Asaas integrado com sucesso!**
