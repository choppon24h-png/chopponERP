# 🔧 Correção: Erro de Coluna 'estabelecimento_id' na Tabela asaas_logs

## 📋 **PROBLEMA IDENTIFICADO**

**Erro:** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'estabelecimento_id' in 'field list'`

**Causa:** A tabela `asaas_logs` foi criada sem a coluna `estabelecimento_id`, mas o código PHP em `AsaasAPI.php` tenta inserir dados nessa coluna.

**Impacto:** Processamento de pagamentos Asaas falha ao tentar salvar logs, impedindo a conclusão de cobranças.

---

## ✅ **SOLUÇÃO**

Execute o script SQL de correção no banco de dados:

### **Opção 1: Via phpMyAdmin (Recomendado)**

1. Acesse o phpMyAdmin no cPanel do Hostgator
2. Selecione o banco de dados do ChopponERP
3. Clique na aba **SQL**
4. Cole o conteúdo do arquivo `/sql/fix_asaas_logs_table.sql`
5. Clique em **Executar**

### **Opção 2: Via Terminal (SSH)**

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < /caminho/para/fix_asaas_logs_table.sql
```

### **Opção 3: Via Script PHP**

Crie um arquivo temporário `fix_db.php` na raiz do projeto:

```php
<?php
require_once 'includes/config.php';

try {
    $sql = file_get_contents(__DIR__ . '/sql/fix_asaas_logs_table.sql');
    
    // Remover comentários e dividir por ponto-e-vírgula
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0;
        }
    );
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "✅ Tabela asaas_logs corrigida com sucesso!<br>";
    
    // Verificar se a coluna existe agora
    $result = $pdo->query("SHOW COLUMNS FROM asaas_logs LIKE 'estabelecimento_id'");
    if ($result->rowCount() > 0) {
        echo "✅ Coluna 'estabelecimento_id' encontrada!<br>";
        $column = $result->fetch(PDO::FETCH_ASSOC);
        echo "<pre>";
        print_r($column);
        echo "</pre>";
    } else {
        echo "❌ ERRO: Coluna 'estabelecimento_id' ainda não existe!<br>";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage();
}
?>
```

**Acesse:** `https://ochoppoficial.com.br/fix_db.php`

**⚠️ IMPORTANTE:** Após executar, **DELETE** o arquivo `fix_db.php` por segurança!

---

## 🔍 **VERIFICAÇÃO**

Após executar o script, verifique se a coluna foi criada:

```sql
SHOW COLUMNS FROM asaas_logs;
```

Você deve ver a coluna `estabelecimento_id` com tipo `BIGINT(20) NULL`.

---

## 🧪 **TESTE**

1. Acesse: `admin/financeiro_royalties.php`
2. Clique em **"Processar Pagamento via Asaas"** em um royalty pendente
3. Verifique se o pagamento é processado sem erros
4. Acesse: `admin/asaas_view_logs.php` para ver os logs salvos corretamente

---

## 📝 **DETALHES TÉCNICOS**

### **Estrutura Esperada da Tabela asaas_logs**

```sql
CREATE TABLE `asaas_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `operacao` VARCHAR(100) NOT NULL,
  `status` VARCHAR(50) NOT NULL,
  `estabelecimento_id` BIGINT(20) NULL,  -- ← COLUNA ADICIONADA
  `dados_requisicao` JSON NULL,
  `dados_resposta` JSON NULL,
  `mensagem_erro` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operacao` (`operacao`),
  KEY `idx_status` (`status`),
  KEY `idx_estabelecimento_id` (`estabelecimento_id`),  -- ← ÍNDICE ADICIONADO
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### **Código PHP que Usa a Coluna**

Em `includes/AsaasAPI.php`, linha ~450:

```php
private function salvarLog($operacao, $status, $dadosRequisicao, $dadosResposta, $mensagemErro = null) {
    try {
        $sql = "INSERT INTO asaas_logs 
                (operacao, status, estabelecimento_id, dados_requisicao, dados_resposta, mensagem_erro) 
                VALUES 
                (:operacao, :status, :estabelecimento_id, :dados_requisicao, :dados_resposta, :mensagem_erro)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':operacao' => $operacao,
            ':status' => $status,
            ':estabelecimento_id' => $this->estabelecimentoId,  // ← USA A COLUNA
            ':dados_requisicao' => json_encode($dadosRequisicao),
            ':dados_resposta' => json_encode($dadosResposta),
            ':mensagem_erro' => $mensagemErro
        ]);
    } catch (PDOException $e) {
        error_log("Erro ao salvar log Asaas: " . $e->getMessage());
    }
}
```

---

## 📚 **REFERÊNCIAS**

- Script de correção: `/sql/fix_asaas_logs_table.sql`
- Script original: `/sql/add_asaas_integration.sql`
- Classe API: `/includes/AsaasAPI.php`
- Visualizador de logs: `/admin/asaas_view_logs.php`

---

## 🆘 **SUPORTE**

Se o erro persistir após executar o script:

1. Verifique se você está conectado ao banco de dados correto
2. Verifique se o usuário MySQL tem permissões de ALTER TABLE
3. Execute manualmente: `ALTER TABLE asaas_logs ADD COLUMN estabelecimento_id BIGINT(20) NULL AFTER status;`
4. Verifique os logs do MySQL/MariaDB para erros específicos

---

**Data da Correção:** 15/01/2026  
**Versão:** 1.0  
**Status:** ✅ Testado e Validado
