<?php
/**
 * Helper para Upload Seguro de Arquivos
 * Inclui validação de tipo MIME, tamanho, extensão e conteúdo
 */

class SecureUpload {
    
    // Extensões permitidas por tipo
    private static $allowed_extensions = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'video' => ['mp4', 'avi', 'mov', 'wmv']
    ];
    
    // MIME types permitidos por tipo
    private static $allowed_mimes = [
        'image' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ],
        'video' => [
            'video/mp4',
            'video/x-msvideo',
            'video/quicktime',
            'video/x-ms-wmv'
        ]
    ];
    
    // Tamanhos máximos por tipo (em bytes)
    private static $max_sizes = [
        'image' => 5242880,      // 5MB
        'document' => 10485760,  // 10MB
        'video' => 52428800      // 50MB
    ];
    
    /**
     * Valida e faz upload de arquivo
     * 
     * @param array $file Array $_FILES['campo']
     * @param string $type Tipo: 'image', 'document', 'video'
     * @param string $upload_dir Diretório de destino
     * @return array ['success' => bool, 'path' => string, 'error' => string]
     */
    public static function upload($file, $type, $upload_dir) {
        // Verificar se arquivo foi enviado
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => 'Nenhum arquivo foi enviado ou erro no upload.'
            ];
        }
        
        // Verificar tamanho
        if ($file['size'] > self::$max_sizes[$type]) {
            $max_mb = self::$max_sizes[$type] / 1048576;
            return [
                'success' => false,
                'error' => "Arquivo muito grande. Tamanho máximo: {$max_mb}MB"
            ];
        }
        
        // Verificar extensão
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, self::$allowed_extensions[$type])) {
            $allowed = implode(', ', self::$allowed_extensions[$type]);
            return [
                'success' => false,
                'error' => "Extensão não permitida. Permitidas: {$allowed}"
            ];
        }
        
        // Verificar MIME type real do arquivo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, self::$allowed_mimes[$type])) {
            Logger::security("Tentativa de upload de arquivo com MIME inválido", [
                'mime_detected' => $mime_type,
                'extension' => $file_ext,
                'original_name' => $file['name']
            ]);
            return [
                'success' => false,
                'error' => 'Tipo de arquivo não permitido.'
            ];
        }
        
        // Validações específicas por tipo
        if ($type === 'image') {
            $validation = self::validateImage($file['tmp_name']);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => $validation['error']
                ];
            }
        }
        
        // Criar diretório se não existir
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Gerar nome seguro para o arquivo
        $safe_filename = self::generateSafeFilename($file_ext);
        $target_path = $upload_dir . $safe_filename;
        
        // Mover arquivo
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            // Definir permissões seguras
            chmod($target_path, 0644);
            
            Logger::info("Upload realizado com sucesso", [
                'type' => $type,
                'filename' => $safe_filename,
                'size' => $file['size'],
                'mime' => $mime_type
            ]);
            
            return [
                'success' => true,
                'path' => $target_path,
                'filename' => $safe_filename
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Erro ao mover arquivo para destino.'
            ];
        }
    }
    
    /**
     * Valida se arquivo é realmente uma imagem
     */
    private static function validateImage($file_path) {
        // Tentar carregar como imagem
        $image_info = @getimagesize($file_path);
        
        if ($image_info === false) {
            return [
                'valid' => false,
                'error' => 'Arquivo não é uma imagem válida.'
            ];
        }
        
        // Verificar dimensões mínimas (opcional)
        $min_width = 50;
        $min_height = 50;
        
        if ($image_info[0] < $min_width || $image_info[1] < $min_height) {
            return [
                'valid' => false,
                'error' => "Imagem muito pequena. Mínimo: {$min_width}x{$min_height}px"
            ];
        }
        
        // Verificar dimensões máximas (opcional)
        $max_width = 5000;
        $max_height = 5000;
        
        if ($image_info[0] > $max_width || $image_info[1] > $max_height) {
            return [
                'valid' => false,
                'error' => "Imagem muito grande. Máximo: {$max_width}x{$max_height}px"
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Gera nome de arquivo seguro e único
     */
    private static function generateSafeFilename($extension) {
        // Usar timestamp + random para garantir unicidade
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        return "{$timestamp}_{$random}.{$extension}";
    }
    
    /**
     * Remove arquivo de forma segura
     */
    public static function deleteFile($file_path) {
        if (file_exists($file_path) && is_file($file_path)) {
            if (unlink($file_path)) {
                Logger::info("Arquivo removido", ['path' => $file_path]);
                return true;
            }
        }
        return false;
    }
    
    /**
     * Redimensiona imagem (útil para thumbnails)
     */
    public static function resizeImage($source_path, $dest_path, $max_width, $max_height) {
        $image_info = getimagesize($source_path);
        if ($image_info === false) {
            return false;
        }
        
        list($orig_width, $orig_height, $type) = $image_info;
        
        // Calcular novas dimensões mantendo proporção
        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        $new_width = (int)($orig_width * $ratio);
        $new_height = (int)($orig_height * $ratio);
        
        // Criar imagem source
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($source_path);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($source_path);
                break;
            default:
                return false;
        }
        
        // Criar imagem destino
        $dest = imagecreatetruecolor($new_width, $new_height);
        
        // Preservar transparência para PNG e GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
        }
        
        // Redimensionar
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
        
        // Salvar
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($dest, $dest_path, 90);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($dest, $dest_path, 9);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($dest, $dest_path);
                break;
            case IMAGETYPE_WEBP:
                $result = imagewebp($dest, $dest_path, 90);
                break;
        }
        
        // Liberar memória
        imagedestroy($source);
        imagedestroy($dest);
        
        return $result;
    }
}
?>
