# 🚀 Guia Rápido: Implementação da Correção asaas_logs

## ⚡ **AÇÃO IMEDIATA NECESSÁRIA**

O sistema está com erro ao processar pagamentos Asaas devido à falta da coluna `estabelecimento_id` na tabela `asaas_logs`.

---

## 📋 **PASSO A PASSO - ESCOLHA UMA OPÇÃO:**

### **🟢 OPÇÃO 1: Script PHP Automático (MAIS FÁCIL)**

1. **Acesse no navegador:**
   ```
   https://ochoppoficial.com.br/fix_db_asaas_logs.php
   ```

2. **Aguarde a execução** (você verá uma página com o progresso)

3. **Verifique se apareceu:** ✅ "CORREÇÃO CONCLUÍDA COM SUCESSO!"

4. **⚠️ IMPORTANTE:** DELETE o arquivo via FTP/cPanel:
   ```
   /home2/inlaud99/ochoppoficial.com.br/fix_db_asaas_logs.php
   ```

---

### **🔵 OPÇÃO 2: phpMyAdmin (MANUAL)**

1. **Acesse phpMyAdmin** no cPanel do Hostgator

2. **Selecione o banco de dados** do ChopponERP

3. **Clique na aba "SQL"**

4. **Cole este comando:**
   ```sql
   ALTER TABLE asaas_logs 
   ADD COLUMN estabelecimento_id BIGINT(20) NULL 
   AFTER status;
   
   ALTER TABLE asaas_logs 
   ADD INDEX idx_estabelecimento_id (estabelecimento_id);
   ```

5. **Clique em "Executar"**

6. **Verifique:** Deve aparecer "2 linhas afetadas"

---

### **🟡 OPÇÃO 3: Terminal SSH**

Se você tem acesso SSH ao servidor:

```bash
cd /home2/inlaud99/ochoppoficial.com.br/
mysql -u SEU_USUARIO -p SEU_BANCO < sql/fix_asaas_logs_table.sql
```

---

## ✅ **VERIFICAÇÃO**

Após executar qualquer opção acima, verifique se funcionou:

### **1. Verificar no phpMyAdmin:**

```sql
SHOW COLUMNS FROM asaas_logs;
```

Você deve ver a coluna `estabelecimento_id` na lista.

### **2. Testar pagamento Asaas:**

1. Acesse: `https://ochoppoficial.com.br/admin/financeiro_royalties.php`
2. Clique em **"Processar Pagamento via Asaas"** em um royalty pendente
3. Verifique se o pagamento é processado **SEM ERROS**

### **3. Verificar logs:**

1. Acesse: `https://ochoppoficial.com.br/admin/asaas_view_logs.php`
2. Você deve ver logs sendo salvos corretamente com o `estabelecimento_id`

---

## 🔍 **DIAGNÓSTICO DE PROBLEMAS**

### **Se o erro persistir:**

1. **Verifique se está no banco correto:**
   ```sql
   SELECT DATABASE();
   ```

2. **Verifique permissões do usuário MySQL:**
   ```sql
   SHOW GRANTS FOR CURRENT_USER();
   ```
   Deve ter permissão de `ALTER` na tabela.

3. **Execute manualmente:**
   ```sql
   ALTER TABLE asaas_logs ADD COLUMN estabelecimento_id BIGINT(20) NULL;
   ```

4. **Verifique logs de erro do PHP:**
   - cPanel → Logs → Error Log
   - Procure por "asaas_logs" ou "estabelecimento_id"

---

## 📊 **ESTRUTURA ESPERADA**

Após a correção, a tabela `asaas_logs` deve ter estas colunas:

| Coluna | Tipo | Null | Key | Default |
|--------|------|------|-----|---------|
| id | INT(11) | NO | PRI | NULL |
| operacao | VARCHAR(100) | NO | MUL | NULL |
| status | VARCHAR(50) | NO | MUL | NULL |
| **estabelecimento_id** | **BIGINT(20)** | **YES** | **MUL** | **NULL** |
| dados_requisicao | JSON | YES | | NULL |
| dados_resposta | JSON | YES | | NULL |
| mensagem_erro | TEXT | YES | | NULL |
| created_at | TIMESTAMP | NO | MUL | CURRENT_TIMESTAMP |

---

## 📁 **ARQUIVOS RELACIONADOS**

- **Script SQL:** `/sql/fix_asaas_logs_table.sql`
- **Script PHP:** `/fix_db_asaas_logs.php` (DELETE após usar!)
- **Documentação:** `/docs/FIX_ASAAS_LOGS_ERROR.md`
- **Código fonte:** `/includes/AsaasAPI.php` (método `salvarLog()`)

---

## 🆘 **PRECISA DE AJUDA?**

Se nenhuma das opções funcionar:

1. Tire um print da mensagem de erro
2. Execute `SHOW CREATE TABLE asaas_logs;` no phpMyAdmin
3. Copie o resultado completo
4. Entre em contato com suporte técnico

---

## ✨ **APÓS A CORREÇÃO**

Quando tudo estiver funcionando:

1. ✅ Pagamentos Asaas processarão normalmente
2. ✅ Logs serão salvos com `estabelecimento_id`
3. ✅ Visualizador de logs mostrará dados completos
4. ✅ Webhooks serão processados corretamente

---

**Data:** 15/01/2026  
**Status:** 🔴 **AGUARDANDO IMPLEMENTAÇÃO**  
**Prioridade:** 🔥 **ALTA - SISTEMA BLOQUEADO**
