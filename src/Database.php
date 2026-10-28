<?php
declare(strict_types=1);
function openDatabase(array $db): PDO
{
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10];
    if ($db['tls'] ?? false) {
        $options[PDO::MYSQL_ATTR_SSL_CA]=$db['ca_file'] ?? '/etc/ssl/certs/ca-certificates.crt';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;
    }
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port']??3306,$db['name']),$db['user'],$db['password'],$options);
    if (($db['tls'] ?? false) && ($pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1]??'')==='') throw new RuntimeException('SQL TLS required.');
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
