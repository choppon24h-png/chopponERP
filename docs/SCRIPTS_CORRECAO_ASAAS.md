# 🔧 Scripts de Correção - Tabela asaas_logs

## 📋 **PROBLEMA**

Erro ao processar pagamentos Asaas:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'estabelecimento_id' in 'field list'
```

---

## 🛠️ **SOLUÇÕES DISPONÍVEIS**

### **✅ VERSÃO 1: fix_db_asaas_logs.php (RECOMENDADO)**

**Características:**
- ✅ Usa `config.php` do sistema
- ✅ Aproveita função `getDBConnection()`
- ✅ Integrado com sistema existente
- ✅ Mais seguro (não expõe credenciais)

**Como usar:**
```
https://ochoppoficial.com.br/fix_db_asaas_logs.php
```

**Quando usar:**
- Sistema já configurado corretamente
- `config.php` funcionando
- Ambiente de produção normal

---

### **✅ VERSÃO 2: fix_db_asaas_logs_v2.php (ALTERNATIVA)**

**Características:**
- ✅ Conexão direta ao banco
- ✅ Não depende de `config.php`
- ✅ Funciona mesmo com problemas de sessão
- ⚠️ Credenciais hardcoded (mais arriscado)

**Como usar:**
```
https://ochoppoficial.com.br/fix_db_asaas_logs_v2.php
```

**Quando usar:**
- Versão 1 falhou
- Problemas com `config.php`
- Erro de sessão ou includes
- Ambiente de desenvolvimento/debug

---

## 🔄 **DIFERENÇAS ENTRE AS VERSÕES**

| Aspecto | Versão 1 | Versão 2 |
|---------|----------|----------|
| **Conexão** | Via `getDBConnection()` | PDO direto |
| **Config** | Usa `config.php` | Hardcoded |
| **Segurança** | ✅ Alta | ⚠️ Média |
| **Dependências** | Requer `config.php` | Independente |
| **Sessão** | Pode ter conflito | Sem conflito |
| **Recomendação** | 🥇 Primeira escolha | 🥈 Fallback |

---

## 📝 **HISTÓRICO DE CORREÇÕES**

### **Versão 1.0 (Original)**
- ❌ **Problema:** Variável `$pdo` indefinida
- **Causa:** `config.php` usa função, não variável global
- **Erro:** `Undefined variable $pdo on line 51`

### **Versão 1.1 (Corrigida)**
- ✅ **Correção:** Adicionado `$pdo = getDBConnection();`
- **Commit:** `958acb9`
- **Data:** 15/01/2026

### **Versão 2.0 (Alternativa)**
- ✅ **Nova versão:** Conexão direta sem `config.php`
- **Commit:** `2cbea56`
- **Data:** 15/01/2026

---

## 🚀 **PASSO A PASSO**

### **1️⃣ Tente a Versão 1 primeiro:**

```
https://ochoppoficial.com.br/fix_db_asaas_logs.php
```

**Se funcionar:**
- ✅ Veja mensagem de sucesso
- ✅ DELETE o arquivo
- ✅ Teste pagamento Asaas

**Se falhar com erro de $pdo ou sessão:**
- ⬇️ Vá para Versão 2

---

### **2️⃣ Se necessário, use a Versão 2:**

```
https://ochoppoficial.com.br/fix_db_asaas_logs_v2.php
```

**Se funcionar:**
- ✅ Veja mensagem de sucesso
- ✅ DELETE os dois arquivos (v1 e v2)
- ✅ Teste pagamento Asaas

**Se ainda falhar:**
- ⬇️ Use método manual (phpMyAdmin)

---

### **3️⃣ Método Manual (phpMyAdmin):**

Se ambos os scripts falharem:

```sql
ALTER TABLE asaas_logs 
ADD COLUMN estabelecimento_id BIGINT(20) NULL 
AFTER status;

ALTER TABLE asaas_logs 
ADD INDEX idx_estabelecimento_id (estabelecimento_id);
```

---

## ✅ **VERIFICAÇÃO**

Após qualquer método, verifique:

### **1. Estrutura do banco:**
```sql
SHOW COLUMNS FROM asaas_logs;
```

Deve mostrar a coluna `estabelecimento_id`.

### **2. Teste de inserção:**
```sql
INSERT INTO asaas_logs 
(operacao, status, estabelecimento_id, dados_requisicao, dados_resposta) 
VALUES 
('teste', 'sucesso', 1, '{}', '{}');
```

Não deve dar erro.

### **3. Teste real:**
- Acesse: `admin/financeiro_royalties.php`
- Processe um pagamento Asaas
- Verifique logs em: `admin/asaas_view_logs.php`

---

## 🗑️ **LIMPEZA**

Após sucesso, **DELETE os arquivos:**

```bash
# Via FTP ou cPanel File Manager:
/home2/inlaud99/ochoppoficial.com.br/fix_db_asaas_logs.php
/home2/inlaud99/ochoppoficial.com.br/fix_db_asaas_logs_v2.php
```

**⚠️ IMPORTANTE:** Estes scripts contêm lógica de alteração de banco e devem ser removidos após uso!

---

## 🐛 **TROUBLESHOOTING**

### **Erro: "session_start(): Session cannot be started"**
**Solução:** Use a Versão 2 (não depende de sessão)

### **Erro: "Undefined variable $pdo"**
**Solução:** Versão 1.1 já corrige isso. Baixe novamente do GitHub.

### **Erro: "Access denied for user"**
**Solução:** Verifique credenciais em `config.php` ou na Versão 2

### **Erro: "Table 'asaas_logs' doesn't exist"**
**Solução:** Execute primeiro `sql/add_asaas_integration.sql`

### **Erro: "Duplicate column name"**
**Solução:** Coluna já existe! Vá direto para testes.

---

## 📊 **LOGS DE DEBUG**

Se precisar debugar, verifique:

1. **Logs PHP:** cPanel → Logs → Error Log
2. **Logs do script:** Aparecem na página ao executar
3. **Logs MySQL:** Via phpMyAdmin → SQL → SHOW WARNINGS

---

## 📁 **ARQUIVOS RELACIONADOS**

- `/fix_db_asaas_logs.php` - Versão 1 (usa config.php)
- `/fix_db_asaas_logs_v2.php` - Versão 2 (conexão direta)
- `/sql/fix_asaas_logs_table.sql` - SQL puro
- `/includes/config.php` - Configurações do sistema
- `/includes/AsaasAPI.php` - Classe que usa a coluna

---

## 🔐 **SEGURANÇA**

### **Versão 1:**
- ✅ Não expõe credenciais
- ✅ Usa configuração centralizada
- ⚠️ Pode ter conflito de sessão

### **Versão 2:**
- ⚠️ Credenciais no código
- ✅ Funciona sem dependências
- ⚠️ **DEVE ser deletada após uso!**

---

## 📚 **REFERÊNCIAS**

- **Documentação completa:** `/docs/FIX_ASAAS_LOGS_ERROR.md`
- **Guia rápido:** `/IMPLEMENTACAO_CORRECAO.md`
- **Resumo executivo:** `/RESUMO_CORRECAO_ASAAS.md`
- **Checklist:** `/CHECKLIST_IMPLEMENTACAO.md` (na raiz do projeto)

---

## 🎯 **RECOMENDAÇÃO FINAL**

1. ✅ **Primeira tentativa:** Versão 1
2. ✅ **Se falhar:** Versão 2
3. ✅ **Se ambos falharem:** phpMyAdmin manual
4. ✅ **Sempre:** DELETE os scripts após uso
5. ✅ **Sempre:** Teste o pagamento Asaas após correção

---

**Última atualização:** 15/01/2026  
**Status:** ✅ Ambas versões testadas e funcionais  
**Commits:** `958acb9` (v1.1), `2cbea56` (v2.0)
