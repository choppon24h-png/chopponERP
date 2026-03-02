<?php
/**
 * Teste de Detecção de URL
 * Verifica se o SITE_URL está sendo detectado corretamente
 */

require_once 'includes/config.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de URL - Chopp On Tap</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #FF8C00; margin-bottom: 20px; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; color: #1976d2; }
        .success { background: #d4edda; padding: 15px; border-radius: 4px; margin-bottom: 20px; color: #155724; }
        .error { background: #f8d7da; padding: 15px; border-radius: 4px; margin-bottom: 20px; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .test-link { display: inline-block; margin: 10px 10px 10px 0; padding: 10px 20px; background: #FF8C00; color: #fff; text-decoration: none; border-radius: 4px; }
        .test-link:hover { background: #e67e00; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Teste de Detecção de URL</h1>
        
        <div class="info">
            <strong>Objetivo:</strong> Verificar se o sistema está detectando corretamente a URL base e carregando os assets (CSS, JS, imagens).
        </div>

        <h2>Configurações Detectadas</h2>
        <table>
            <tr>
                <th>Variável</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td><strong>SITE_URL</strong></td>
                <td><code><?php echo SITE_URL; ?></code></td>
            </tr>
            <tr>
                <td><strong>HTTP_HOST</strong></td>
                <td><code><?php echo $_SERVER['HTTP_HOST'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td><strong>SCRIPT_NAME</strong></td>
                <td><code><?php echo $_SERVER['SCRIPT_NAME'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td><strong>REQUEST_URI</strong></td>
                <td><code><?php echo $_SERVER['REQUEST_URI'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td><strong>HTTPS</strong></td>
                <td><code><?php echo (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Sim' : 'Não'; ?></code></td>
            </tr>
            <tr>
                <td><strong>Protocolo</strong></td>
                <td><code><?php echo (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://'; ?></code></td>
            </tr>
        </table>

        <h2>URLs dos Assets</h2>
        <table>
            <tr>
                <th>Asset</th>
                <th>URL Completa</th>
            </tr>
            <tr>
                <td><strong>CSS</strong></td>
                <td><code><?php echo SITE_URL; ?>/assets/css/style.css</code></td>
            </tr>
            <tr>
                <td><strong>JavaScript</strong></td>
                <td><code><?php echo SITE_URL; ?>/assets/js/main.js</code></td>
            </tr>
            <tr>
                <td><strong>Logo PNG</strong></td>
                <td><code><?php echo SITE_URL; ?>/assets/images/logo.png</code></td>
            </tr>
            <tr>
                <td><strong>Logo JPEG</strong></td>
                <td><code><?php echo SITE_URL; ?>/assets/images/logo.jpeg</code></td>
            </tr>
        </table>

        <h2>Teste de Carregamento</h2>
        
        <?php
        $css_path = __DIR__ . '/assets/css/style.css';
        $js_path = __DIR__ . '/assets/js/main.js';
        $logo_png = __DIR__ . '/assets/images/logo.png';
        $logo_jpeg = __DIR__ . '/assets/images/logo.jpeg';
        
        $all_ok = true;
        ?>
        
        <table>
            <tr>
                <th>Arquivo</th>
                <th>Existe?</th>
                <th>Tamanho</th>
            </tr>
            <tr>
                <td>style.css</td>
                <td><?php echo file_exists($css_path) ? '✅ Sim' : '❌ Não'; ?></td>
                <td><?php echo file_exists($css_path) ? number_format(filesize($css_path) / 1024, 2) . ' KB' : 'N/A'; ?></td>
            </tr>
            <tr>
                <td>main.js</td>
                <td><?php echo file_exists($js_path) ? '✅ Sim' : '❌ Não'; ?></td>
                <td><?php echo file_exists($js_path) ? number_format(filesize($js_path) / 1024, 2) . ' KB' : 'N/A'; ?></td>
            </tr>
            <tr>
                <td>logo.png</td>
                <td><?php echo file_exists($logo_png) ? '✅ Sim' : '❌ Não'; ?></td>
                <td><?php echo file_exists($logo_png) ? number_format(filesize($logo_png) / 1024, 2) . ' KB' : 'N/A'; ?></td>
            </tr>
            <tr>
                <td>logo.jpeg</td>
                <td><?php echo file_exists($logo_jpeg) ? '✅ Sim' : '❌ Não'; ?></td>
                <td><?php echo file_exists($logo_jpeg) ? number_format(filesize($logo_jpeg) / 1024, 2) . ' KB' : 'N/A'; ?></td>
            </tr>
        </table>

        <?php if (file_exists($css_path) && file_exists($js_path)): ?>
            <div class="success">
                <strong>✅ Sucesso!</strong> Todos os arquivos necessários foram encontrados.
            </div>
        <?php else: ?>
            <div class="error">
                <strong>❌ Erro!</strong> Alguns arquivos estão faltando. Verifique se você fez upload de todos os arquivos.
            </div>
        <?php endif; ?>

        <h2>Teste Visual do CSS</h2>
        <div style="margin: 20px 0;">
            <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
            <div class="card" style="padding: 20px; margin: 10px 0;">
                <h3>Se você vê este card estilizado, o CSS está carregando!</h3>
                <p>Este é um teste visual. Se este card tiver cores e estilos, significa que o CSS foi carregado corretamente.</p>
            </div>
        </div>

        <h2>Links de Teste</h2>
        <div>
            <a href="<?php echo SITE_URL; ?>/assets/css/style.css" target="_blank" class="test-link">Abrir CSS</a>
            <a href="<?php echo SITE_URL; ?>/assets/js/main.js" target="_blank" class="test-link">Abrir JS</a>
            <a href="<?php echo SITE_URL; ?>/assets/images/logo.png" target="_blank" class="test-link">Abrir Logo PNG</a>
            <a href="<?php echo SITE_URL; ?>/index.php" class="test-link">Ir para Login</a>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 4px; color: #856404;">
            <strong>⚠️ Importante:</strong> Delete este arquivo após testar!<br>
            <code>rm test_url.php</code>
        </div>
    </div>
</body>
</html>
