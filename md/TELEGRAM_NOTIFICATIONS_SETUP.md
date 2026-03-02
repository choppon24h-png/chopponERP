# 📱 Sistema de Notificações Automáticas via Telegram

## 📋 Índice
1. [Visão Geral](#visão-geral)
2. [Arquitetura](#arquitetura)
3. [Instalação](#instalação)
4. [Configuração CRON](#configuração-cron)
5. [Testes](#testes)
6. [Troubleshooting](#troubleshooting)
7. [FAQ](#faq)

---

## 🎯 Visão Geral

Sistema robusto de notificações automáticas via Telegram desenvolvido seguindo melhores práticas de desenvolvimento. Envia alertas sobre:

- **📦 Estoque Mínimo**: Produtos que atingiram o estoque mínimo
- **💰 Contas a Pagar**: Contas vencendo hoje
- **🎉 Promoções**: Promoções ativas no dia

### ✨ Características

✅ **Classe PDO robusta** com tratamento de erros  
✅ **Mensagens formatadas** com emojis e Markdown  
✅ **Logs completos** de todas as operações  
✅ **Segurança** com chave de acesso  
✅ **Suporte a múltiplos estabelecimentos**  
✅ **Idempotência** (evita notificações duplicadas)  
✅ **Compatível** com Hostgator e cron-job.org  

---

## 🏗️ Arquitetura

### Arquivos do Sistema

```
chopponERP/
├── includes/
│   ├── config.php                  # Configurações (TELEGRAM_CRON_KEY)
│   └── TelegramNotifier.php        # Classe principal
├── cron/
│   └── telegram_cron.php           # Script de execução CRON
└── logs/
    ├── telegram_cron_YYYY-MM-DD.log      # Logs de execução
    └── telegram_notifier_YYYY-MM-DD.log  # Logs da classe
```

### Fluxo de Execução

```
CRON Trigger
    ↓
telegram_cron.php
    ↓
Validar Chave de Segurança
    ↓
Conectar ao Banco (PDO)
    ↓
Buscar Estabelecimentos Ativos
    ↓
Para cada estabelecimento:
    ├── Instanciar TelegramNotifier
    ├── verificarEstoqueMinimo()
    ├── verificarContasPagar()
    └── verificarPromocoes()
    ↓
Enviar Mensagens via API Telegram
    ↓
Registrar Logs
    ↓
Retornar Resultado (JSON ou CLI)
```

---

## 🚀 Instalação

### Passo 1: Verificar Arquivos

Certifique-se de que os arquivos estão no lugar correto:

```bash
# Verificar arquivos
ls -la includes/TelegramNotifier.php
ls -la cron/telegram_cron.php
ls -la includes/config.php
```

### Passo 2: Configurar Telegram

1. **Criar Bot no Telegram:**
   - Abrir [@BotFather](https://t.me/BotFather) no Telegram
   - Enviar `/newbot`
   - Seguir instruções e copiar o **Token**

2. **Obter Chat ID:**
   - Enviar mensagem para o bot criado
   - Acessar: `https://api.telegram.org/bot<TOKEN>/getUpdates`
   - Copiar o **chat_id** da resposta

3. **Configurar no Sistema:**
   - Acessar: `Admin → Integrações → Telegram`
   - Colar **Bot Token** e **Chat ID**
   - Marcar como **Ativo**
   - Testar conexão

### Passo 3: Obter Chave de Segurança

A chave é gerada automaticamente no `config.php`:

```php
define('TELEGRAM_CRON_KEY', 'choppon_telegram_2026_secure_key_' . md5(DB_NAME . DB_PASS));
```

Para visualizar sua chave, crie um arquivo temporário:

```php
<?php
require_once 'includes/config.php';
echo "Sua chave: " . TELEGRAM_CRON_KEY;
?>
```

Acesse via browser, copie a chave e **delete o arquivo**.

---

## ⚙️ Configuração CRON

### Opção 1: Hostgator (cPanel)

#### 1.1. Acessar cPanel

1. Login no cPanel do Hostgator
2. Buscar por **"Cron Jobs"**
3. Clicar em **"Cron Jobs"**

#### 1.2. Adicionar Novo CRON

**Configuração Recomendada: Diariamente às 08:00**

| Campo | Valor |
|-------|-------|
| **Minuto** | `0` |
| **Hora** | `8` |
| **Dia** | `*` |
| **Mês** | `*` |
| **Dia da Semana** | `*` |

**Comando:**
```bash
/usr/local/bin/php /home/SEU_USUARIO/public_html/cron/telegram_cron.php
```

⚠️ **Importante:** Substitua `SEU_USUARIO` pelo seu usuário real do Hostgator.

#### 1.3. Descobrir Caminho Completo

Se não souber o caminho, crie arquivo `path.php`:

```php
<?php echo __DIR__; ?>
```

Acesse via browser e copie o caminho.

#### 1.4. Outras Frequências

**A cada 6 horas:**
```
0 */6 * * * /usr/local/bin/php /home/SEU_USUARIO/public_html/cron/telegram_cron.php
```

**A cada 12 horas (8h e 20h):**
```
0 8,20 * * * /usr/local/bin/php /home/SEU_USUARIO/public_html/cron/telegram_cron.php
```

**Apenas dias úteis às 8h:**
```
0 8 * * 1-5 /usr/local/bin/php /home/SEU_USUARIO/public_html/cron/telegram_cron.php
```

---

### Opção 2: cron-job.org (Externo)

#### 2.1. Criar Conta

1. Acessar: [https://cron-job.org](https://cron-job.org)
2. Criar conta gratuita
3. Confirmar email

#### 2.2. Criar Novo Cronjob

1. Clicar em **"Create cronjob"**
2. Preencher:

| Campo | Valor |
|-------|-------|
| **Title** | `ChopponERP - Telegram Notifications` |
| **URL** | `https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE` |
| **Schedule** | `Every day at 08:00` |
| **Timezone** | `America/Sao_Paulo` |

3. Clicar em **"Create"**

#### 2.3. Obter URL Completa

```
https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE_AQUI
```

⚠️ **Substitua:**
- `seu-dominio.com` → Seu domínio real
- `SUA_CHAVE_AQUI` → Chave obtida no Passo 3

#### 2.4. Verificar Execução

- cron-job.org exibe histórico de execuções
- Verificar se retorna HTTP 200
- Ver resposta JSON no histórico

---

## 🧪 Testes

### Teste 1: Execução Manual (CLI)

```bash
# Via terminal SSH
cd /home/seu_usuario/public_html
php cron/telegram_cron.php
```

**Resultado Esperado:**
```
================================================================================
[2026-01-12 08:00:00] [INFO] INICIANDO VERIFICAÇÃO DE ALERTAS TELEGRAM
================================================================================
[2026-01-12 08:00:01] [INFO] Conectando ao banco de dados...
[2026-01-12 08:00:01] [SUCCESS] ✓ Conexão estabelecida com sucesso
...
================================================================================
[2026-01-12 08:00:05] [SUCCESS] VERIFICAÇÃO CONCLUÍDA COM SUCESSO
================================================================================
```

### Teste 2: Execução via Browser

```
https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE
```

**Resultado Esperado (JSON):**
```json
{
  "success": true,
  "message": "Verificação concluída com sucesso",
  "resultado": {
    "estabelecimentos_processados": 1,
    "total_alertas": 3,
    "alertas_estoque": 1,
    "alertas_contas": 1,
    "alertas_promocoes": 1,
    "erros": 0
  },
  "execution_time": 2.45
}
```

### Teste 3: Verificar Logs

```bash
# Ver logs de hoje
tail -f logs/telegram_cron_$(date +%Y-%m-%d).log

# Ver últimas 50 linhas
tail -n 50 logs/telegram_cron_$(date +%Y-%m-%d).log

# Buscar erros
grep ERROR logs/telegram_cron_*.log
```

### Teste 4: Simular Alerta de Estoque

```sql
-- Reduzir estoque de um produto para testar
UPDATE estoque_produtos 
SET estoque_atual = 1, estoque_minimo = 5 
WHERE id = 1;

-- Executar CRON manualmente
-- Verificar se recebeu notificação no Telegram

-- Reverter
UPDATE estoque_produtos 
SET estoque_atual = 100 
WHERE id = 1;
```

---

## 🔧 Troubleshooting

### Problema 1: "Chave de acesso inválida"

**Causa:** Chave incorreta na URL

**Solução:**
1. Obter chave correta:
```php
<?php
require_once 'includes/config.php';
echo TELEGRAM_CRON_KEY;
?>
```
2. Atualizar URL do CRON

---

### Problema 2: "Configuração do Telegram não encontrada"

**Causa:** Telegram não configurado ou inativo

**Solução:**
1. Acessar: `Admin → Integrações → Telegram`
2. Verificar se está **Ativo**
3. Testar conexão

---

### Problema 3: Nenhuma mensagem recebida

**Causa:** Bot Token ou Chat ID incorretos

**Solução:**
1. Verificar Token no BotFather
2. Obter Chat ID correto:
```bash
curl "https://api.telegram.org/bot<TOKEN>/getUpdates"
```
3. Atualizar configuração

---

### Problema 4: Erro de conexão com banco

**Causa:** Credenciais incorretas em `config.php`

**Solução:**
1. Verificar `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
2. Testar conexão:
```php
<?php
require_once 'includes/config.php';
try {
    $conn = getDBConnection();
    echo "Conexão OK!";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>
```

---

### Problema 5: CRON não executa no Hostgator

**Causa:** Caminho do PHP incorreto

**Soluções:**

**Tentar caminhos alternativos:**
```bash
# Opção 1
/usr/bin/php /home/usuario/public_html/cron/telegram_cron.php

# Opção 2
/usr/local/bin/php /home/usuario/public_html/cron/telegram_cron.php

# Opção 3 (via wget)
wget -q -O /dev/null "https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE"

# Opção 4 (via curl)
curl -s "https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE" > /dev/null
```

---

## ❓ FAQ

### 1. Posso usar em múltiplos estabelecimentos?

**Sim!** O sistema processa automaticamente todos os estabelecimentos com Telegram configurado.

---

### 2. Como desativar temporariamente?

**Opção 1:** No `config.php`:
```php
define('TELEGRAM_NOTIFICATIONS_ENABLED', false);
```

**Opção 2:** Desativar no Admin:
```
Admin → Integrações → Telegram → Desmarcar "Ativo"
```

**Opção 3:** Desativar CRON no cPanel ou cron-job.org

---

### 3. Posso personalizar as mensagens?

**Sim!** Editar métodos em `TelegramNotifier.php`:
- `formatarMensagemEstoque()`
- `formatarMensagemContas()`
- `formatarMensagemPromocoes()`

---

### 4. Como ver histórico de notificações?

**Banco de Dados:**
```sql
SELECT * FROM telegram_alerts 
ORDER BY created_at DESC 
LIMIT 50;
```

**Logs:**
```bash
cat logs/telegram_notifier_$(date +%Y-%m-%d).log
```

---

### 5. Posso enviar para múltiplos chats?

**Não diretamente**, mas você pode:
1. Criar grupo no Telegram
2. Adicionar bot ao grupo
3. Usar Chat ID do grupo

---

### 6. Qual a frequência ideal?

**Recomendações:**

| Tipo | Frequência |
|------|-----------|
| **Estoque** | A cada 6 horas |
| **Contas** | Diariamente às 8h |
| **Promoções** | Diariamente às 9h |
| **Completo** | Diariamente às 8h |

---

### 7. Como adicionar novos tipos de alerta?

1. Criar método na classe `TelegramNotifier.php`
2. Adicionar chamada em `telegram_cron.php`
3. Criar método de formatação de mensagem

**Exemplo:**
```php
// Em TelegramNotifier.php
public function verificarVendasDia() {
    // Lógica de verificação
    $mensagem = $this->formatarMensagemVendas($vendas);
    if ($this->enviarMensagem($mensagem)) {
        $this->contadores['vendas']++;
    }
    return $this->contadores['vendas'];
}
```

---

## 📊 Monitoramento

### Dashboard Rápido (SQL)

```sql
-- Últimas 10 notificações
SELECT 
    DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') as data,
    type,
    status,
    LEFT(message, 50) as preview
FROM telegram_alerts
ORDER BY created_at DESC
LIMIT 10;

-- Estatísticas do mês
SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as mes,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as sucesso,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as falhas
FROM telegram_alerts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY mes;
```

---

## 🎓 Boas Práticas

✅ **Testar em sandbox** antes de produção  
✅ **Monitorar logs** regularmente  
✅ **Backup** do banco de dados  
✅ **Documentar** personalizações  
✅ **Versionar** alterações no Git  
✅ **Revisar** frequência do CRON periodicamente  

---

## 📞 Suporte

**Logs:**
- `logs/telegram_cron_YYYY-MM-DD.log`
- `logs/telegram_notifier_YYYY-MM-DD.log`

**Banco de Dados:**
- Tabela: `telegram_config`
- Tabela: `telegram_alerts`

**Documentação Telegram:**
- [Bot API](https://core.telegram.org/bots/api)
- [Markdown](https://core.telegram.org/bots/api#markdown-style)

---

**Desenvolvido por:** ChopponERP Team  
**Versão:** 2.0.0  
**Data:** 12/01/2026  
**Licença:** Proprietária
