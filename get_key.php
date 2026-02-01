<?php
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Chave CRON Telegram</title>
    <style>
        body { font-family: Arial; padding: 50px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .key { 
            background: #f0f0f0; 
            padding: 15px; 
            border-radius: 5px; 
            font-family: monospace; 
            font-size: 14px;
            word-break: break-all;
            border-left: 4px solid #4CAF50;
        }
        .warning { 
            background: #fff3cd; 
            padding: 15px; 
            border-radius: 5px; 
            margin-top: 20px;
            border-left: 4px solid #ffc107;
        }
        .copy-btn {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }
        .copy-btn:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 Chave de Segurança CRON Telegram</h1>
        
        <h3>Sua chave:</h3>
        <div class="key" id="key"><?php echo TELEGRAM_CRON_KEY; ?></div>
        
        <button class="copy-btn" onclick="copyKey()">📋 Copiar Chave</button>
        
        <div class="warning">
            <strong>⚠️ IMPORTANTE:</strong>  

            1. Copie a chave acima  

            2. <strong>DELETE ESTE ARQUIVO IMEDIATAMENTE</strong> após copiar  

            3. Nunca compartilhe esta chave publicamente
        </div>
        
        <h3>Como usar:</h3>
        <p><strong>Via cron-job.org:</strong></p>
        <div class="key">
            https://seu-dominio.com/cron/telegram_cron.php?key=<?php echo TELEGRAM_CRON_KEY; ?>
        </div>
    </div>
    
    <script>
        function copyKey( ) {
            const key = document.getElementById('key').textContent;
            navigator.clipboard.writeText(key).then(() => {
                alert('✅ Chave copiada para área de transferência!');
            });
        }
    </script>
</body>
</html>