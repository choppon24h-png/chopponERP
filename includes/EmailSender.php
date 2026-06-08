<?php
/**
 * EmailSender — LEGADO (compatibilidade reversa)
 *
 * Este arquivo existia antes do EmailManager e usava colunas antigas
 * (host, username, password, encryption, ativo).
 *
 * Agora é um wrapper fino que delega ao EmailManager para manter
 * compatibilidade com qualquer código legado que ainda instancie EmailSender.
 *
 * NÃO adicione lógica nova aqui. Use EmailManager diretamente.
 */

if (!class_exists('EmailManager')) {
    require_once __DIR__ . '/EmailManager.php';
}

class EmailSender
{
    private EmailManager $manager;

    public function __construct(\PDO $conn)
    {
        $this->manager = new EmailManager($conn);
    }

    /**
     * Envia e-mail delegando ao EmailManager.
     * Compatível com a assinatura antiga: enviar($para, $assunto, $corpo, $anexos=[])
     */
    public function enviar($para, string $assunto, string $corpo, array $anexos = []): array
    {
        $destinatario = is_array($para) ? implode(',', $para) : $para;
        return $this->manager->enviar($destinatario, $assunto, $corpo, EmailManager::TIPO_OUTRO);
    }

    /**
     * Envia e-mail de teste delegando ao EmailManager.
     */
    public function enviarEmailTeste(string $email): array
    {
        return $this->manager->enviarTeste($email);
    }

    /**
     * Alias para compatibilidade com código que chama enviarEmail().
     */
    public function enviarEmail($para, string $assunto, string $corpo): array
    {
        return $this->enviar($para, $assunto, $corpo);
    }
}
