<?php
/**
 * Script de Correção Automática - Tabela asaas_logs
 * 
 * ATENÇÃO: Este script deve ser executado UMA VEZ e depois DELETADO por segurança!
 * 
 * Função: Adicionar coluna 'estabelecimento_id' na tabela asaas_logs
 * Data: 2026-01-15
 */

// Configuração de exibição de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Correção Banco de Dados - asaas_logs</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 15px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; border: 1px solid #dee2e6; }
        .step { margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #4CAF50; }
        .step-title { font-weight: bold; color: #4CAF50; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Correção: Tabela asaas_logs</h1>
        <div class='info'>
            <strong>Objetivo:</strong> Adicionar coluna 'estabelecimento_id' na tabela asaas_logs
        </div>
";

try {
    // Incluir configuração do banco de dados
    require_once __DIR__ . '/includes/config.php';
    
    echo "<div class='step'><div class='step-title'>✅ Passo 1: Conexão com banco de dados</div>Conectado com sucesso!</div>";
    
    // Verificar se a coluna já existe
    echo "<div class='step'><div class='step-title'>🔍 Passo 2: Verificando estrutura atual</div>";
    
    $checkColumn = $pdo->query("SHOW COLUMNS FROM asaas_logs LIKE 'estabelecimento_id'");
    $columnExists = $checkColumn->rowCount() > 0;
    
    if ($columnExists) {
        echo "<div class='warning'>⚠️ A coluna 'estabelecimento_id' JÁ EXISTE na tabela asaas_logs!</div>";
        
        $columnInfo = $checkColumn->fetch(PDO::FETCH_ASSOC);
        echo "<pre>";
        print_r($columnInfo);
        echo "</pre>";
        
        echo "<div class='info'>✅ Nenhuma ação necessária. O banco de dados já está correto!</div>";
        
    } else {
        echo "<div class='warning'>❌ Coluna 'estabelecimento_id' NÃO encontrada. Será criada agora...</div></div>";
        
        // Adicionar a coluna
        echo "<div class='step'><div class='step-title'>🔨 Passo 3: Adicionando coluna estabelecimento_id</div>";
        
        $alterTable = "ALTER TABLE asaas_logs 
                       ADD COLUMN estabelecimento_id BIGINT(20) NULL 
                       AFTER status";
        
        $pdo->exec($alterTable);
        echo "<div class='success'>✅ Coluna 'estabelecimento_id' adicionada com sucesso!</div></div>";
        
        // Adicionar índice
        echo "<div class='step'><div class='step-title'>📊 Passo 4: Adicionando índice</div>";
        
        try {
            $addIndex = "ALTER TABLE asaas_logs 
                        ADD INDEX idx_estabelecimento_id (estabelecimento_id)";
            $pdo->exec($addIndex);
            echo "<div class='success'>✅ Índice 'idx_estabelecimento_id' criado com sucesso!</div>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "<div class='info'>ℹ️ Índice já existe, pulando...</div>";
            } else {
                throw $e;
            }
        }
        echo "</div>";
        
        // Verificar resultado
        echo "<div class='step'><div class='step-title'>✅ Passo 5: Verificação final</div>";
        
        $verify = $pdo->query("SHOW COLUMNS FROM asaas_logs LIKE 'estabelecimento_id'");
        if ($verify->rowCount() > 0) {
            echo "<div class='success'>✅ SUCESSO! Coluna criada e verificada!</div>";
            
            $columnInfo = $verify->fetch(PDO::FETCH_ASSOC);
            echo "<strong>Detalhes da coluna:</strong><pre>";
            print_r($columnInfo);
            echo "</pre>";
        } else {
            echo "<div class='error'>❌ ERRO: Coluna não foi criada corretamente!</div>";
        }
        echo "</div>";
    }
    
    // Mostrar estrutura completa da tabela
    echo "<div class='step'><div class='step-title'>📋 Estrutura Completa da Tabela asaas_logs</div>";
    
    $columns = $pdo->query("SHOW COLUMNS FROM asaas_logs");
    echo "<pre>";
    printf("%-25s %-20s %-10s %-10s %-20s\n", "Field", "Type", "Null", "Key", "Default");
    echo str_repeat("-", 85) . "\n";
    
    while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
        printf("%-25s %-20s %-10s %-10s %-20s\n", 
            $col['Field'], 
            $col['Type'], 
            $col['Null'], 
            $col['Key'], 
            $col['Default'] ?? 'NULL'
        );
    }
    echo "</pre></div>";
    
    // Testar inserção
    echo "<div class='step'><div class='step-title'>🧪 Passo 6: Teste de inserção</div>";
    
    $testInsert = "INSERT INTO asaas_logs 
                   (operacao, status, estabelecimento_id, dados_requisicao, dados_resposta, mensagem_erro) 
                   VALUES 
                   ('teste_correcao', 'sucesso', 1, '{}', '{}', 'Teste de correção do banco')";
    
    $pdo->exec($testInsert);
    $testId = $pdo->lastInsertId();
    
    echo "<div class='success'>✅ Teste de inserção bem-sucedido! ID do registro: {$testId}</div>";
    
    // Limpar teste
    $pdo->exec("DELETE FROM asaas_logs WHERE id = {$testId}");
    echo "<div class='info'>ℹ️ Registro de teste removido.</div></div>";
    
    // Mensagem final
    echo "<div class='success' style='margin-top: 30px; font-size: 18px;'>
            <strong>🎉 CORREÇÃO CONCLUÍDA COM SUCESSO!</strong><br><br>
            A tabela asaas_logs agora possui a coluna 'estabelecimento_id' e está pronta para uso.
          </div>";
    
    echo "<div class='error' style='margin-top: 20px;'>
            <strong>⚠️ AÇÃO OBRIGATÓRIA:</strong><br>
            Por segurança, DELETE este arquivo imediatamente:<br>
            <code>fix_db_asaas_logs.php</code>
          </div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>
            <strong>❌ ERRO DE BANCO DE DADOS:</strong><br>
            {$e->getMessage()}<br><br>
            <strong>Código:</strong> {$e->getCode()}<br>
            <strong>Arquivo:</strong> {$e->getFile()}<br>
            <strong>Linha:</strong> {$e->getLine()}
          </div>";
    
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    
} catch (Exception $e) {
    echo "<div class='error'>
            <strong>❌ ERRO GERAL:</strong><br>
            {$e->getMessage()}
          </div>";
}

echo "
    </div>
</body>
</html>";
?>
