<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

$host = '127.0.0.1'; // ou 'localhost' ou host do HostGator
$port = 3306;        // ajuste se necessário
$db   = 'inlaud99_choppontap';
$user = 'admin';
$pass = 'Admin259087@';

$mysqli = @new mysqli($host, $user, $pass, $db, $port);

if ($mysqli->connect_errno) {
    echo "Falha na conexão: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
    exit;
}
echo "Conectado com sucesso! Versão MySQL: " . $mysqli->server_info;
$mysqli->close();
