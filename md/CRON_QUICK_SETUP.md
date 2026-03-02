# ⚡ Guia Rápido - Configurar CRON Telegram

## 🎯 Objetivo
Configurar notificações automáticas via Telegram no Hostgator.

---

## 📋 Checklist Pré-Requisitos

- [ ] Telegram configurado em: `Admin → Integrações → Telegram`
- [ ] Bot Token e Chat ID salvos
- [ ] Teste de conexão OK (botão verde ✓)
- [ ] Arquivos no servidor via Git ou FTP

---

## 🚀 Configuração em 3 Passos

### Passo 1: Obter Chave de Segurança

Criar arquivo temporário `get_key.php` na raiz:

```php
<?php
require_once 'includes/config.php';
echo "<h1>Sua Chave CRON:</h1>";
echo "<code>" . TELEGRAM_CRON_KEY . "</code>";
echo "<p>Copie e delete este arquivo!</p>";
?>
```

1. Acessar: `https://seu-dominio.com/get_key.php`
2. Copiar a chave exibida
3. **DELETAR** o arquivo `get_key.php`

---

### Passo 2: Configurar CRON no Hostgator

#### 2.1. Acessar cPanel
1. Login no cPanel
2. Buscar **"Cron Jobs"**
3. Clicar em **"Cron Jobs"**

#### 2.2. Adicionar Novo CRON

**Configuração Diária às 8h:**

| Campo | Valor |
|-------|-------|
| Minuto | `0` |
| Hora | `8` |
| Dia | `*` |
| Mês | `*` |
| Dia da Semana | `*` |

**Comando (escolha uma opção):**

**Opção 1 - Via PHP (Recomendado):**
```bash
/usr/local/bin/php /home/SEU_USUARIO/public_html/cron/telegram_cron.php
```

**Opção 2 - Via wget:**
```bash
wget -q -O /dev/null "https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE_AQUI"
```

**Opção 3 - Via curl:**
```bash
curl -s "https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE_AQUI" > /dev/null
```

⚠️ **Substituir:**
- `SEU_USUARIO` → Seu usuário do Hostgator
- `seu-dominio.com` → Seu domínio
- `SUA_CHAVE_AQUI` → Chave copiada no Passo 1

#### 2.3. Salvar

Clicar em **"Add New Cron Job"**

---

### Passo 3: Testar

#### 3.1. Teste Manual (Browser)

Acessar:
```
https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE
```

**Resultado Esperado:**
```json
{
  "success": true,
  "message": "Verificação concluída com sucesso",
  "resultado": {
    "estabelecimentos_processados": 1,
    "total_alertas": 0,
    ...
  }
}
```

#### 3.2. Verificar Telegram

- Deve receber mensagem de teste
- Se não receber, verificar configuração

#### 3.3. Verificar Logs

Via FTP ou File Manager:
```
logs/telegram_cron_2026-01-12.log
```

---

## 🔧 Descobrir Caminho do PHP

Se não souber o caminho, criar `phpinfo.php`:

```php
<?php phpinfo(); ?>
```

1. Acessar: `https://seu-dominio.com/phpinfo.php`
2. Buscar por **"_SERVER["PHP_SELF"]"**
3. Copiar caminho
4. **DELETAR** `phpinfo.php`

---

## 📅 Outras Frequências

**A cada 6 horas:**
```
0 */6 * * *
```

**A cada 12 horas (8h e 20h):**
```
0 8,20 * * *
```

**Apenas dias úteis:**
```
0 8 * * 1-5
```

**A cada hora:**
```
0 * * * *
```

---

## ❌ Troubleshooting

### Erro: "Chave de acesso inválida"

**Solução:** Verificar se chave na URL está correta

---

### Erro: "Configuração do Telegram não encontrada"

**Solução:** 
1. Acessar `Admin → Integrações → Telegram`
2. Marcar como **Ativo**
3. Salvar

---

### CRON não executa

**Soluções:**

1. **Verificar email do cPanel** (erros são enviados por email)

2. **Testar comando manualmente via SSH:**
```bash
/usr/local/bin/php /home/usuario/public_html/cron/telegram_cron.php
```

3. **Usar wget se PHP não funcionar:**
```bash
wget -q -O /dev/null "https://dominio.com/cron/telegram_cron.php?key=CHAVE"
```

---

## 📊 Verificar se Está Funcionando

### Via Logs
```
logs/telegram_cron_YYYY-MM-DD.log
```

### Via Banco de Dados
```sql
SELECT * FROM telegram_alerts 
ORDER BY created_at DESC 
LIMIT 10;
```

### Via Email
- Hostgator envia email se CRON falhar
- Verificar caixa de entrada

---

## 🎉 Pronto!

Agora você receberá notificações automáticas no Telegram sobre:
- 📦 Estoque mínimo
- 💰 Contas a pagar
- 🎉 Promoções ativas

---

## 📚 Documentação Completa

Ver: `TELEGRAM_NOTIFICATIONS_SETUP.md`

---

**Tempo de configuração:** 5-10 minutos  
**Dificuldade:** ⭐⭐ Intermediário
