<?php
/**
 * Configurações da API Banco Cora
 * 
 * INSTRUÇÕES:
 * 1. Renomeie este arquivo para cora_config.php
 * 2. Preencha as credenciais obtidas no aplicativo Cora ou Cora Web
 * 3. Coloque os arquivos de certificado na pasta /certs/
 * 4. Certifique-se de que a pasta /certs/ não está acessível publicamente
 */

// Client ID obtido no aplicativo Cora
define('CORA_CLIENT_ID', 'seu-client-id-aqui');

// Caminho para o certificado digital (.pem)
define('CORA_CERTIFICATE_PATH', __DIR__ . '/certs/cora_certificate.pem');

// Caminho para a chave privada (.key)
define('CORA_PRIVATE_KEY_PATH', __DIR__ . '/certs/cora_private_key.key');

// Ambiente: 'stage' para testes ou 'production' para produção
define('CORA_ENVIRONMENT', 'stage');

/**
 * IMPORTANTE:
 * 
 * 1. Cada ambiente (stage/production) possui suas próprias credenciais
 * 2. Nunca commite este arquivo com credenciais reais no Git
 * 3. Adicione cora_config.php ao .gitignore
 * 4. Os arquivos .pem e .key devem ter permissões restritas (chmod 600)
 * 5. Para obter as credenciais:
 *    - Acesse o aplicativo Cora ou Cora Web
 *    - Vá em Configurações > Integrações > API
 *    - Solicite as credenciais de Integração Direta
 *    - Faça o download do certificado e da chave privada
 * 
 * DADOS NECESSÁRIOS PARA INTEGRAÇÃO:
 * 
 * ✓ Client ID (string alfanumérica)
 * ✓ Certificado Digital (arquivo .pem)
 * ✓ Private Key (arquivo .key)
 * ✓ Ambiente (stage ou production)
 * 
 * OBSERVAÇÕES:
 * 
 * - O plano CoraPro é necessário (R$ 44,90/mês)
 * - Token de acesso expira em 24 horas (renovação automática)
 * - Valor mínimo de boleto: R$ 5,00
 * - Idempotency-Key é gerado automaticamente (UUID v4)
 */
?>
