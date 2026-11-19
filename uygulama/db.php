<?php
class Database {
    private static $host_primary = 'mysql-primary';
    private static $host_replica = 'mysql-replica';
    private static $db_name = 'yemekhane';
    private static $username = 'yemekhane_app';
    private static $password = 'Uyg_2026';

    // YAZMA ve ANLIK OKUMA (Primary - 3306 / Standart Port)
    // INSERT, UPDATE, DELETE, Stored Procedure çağrıları ve rezervasyon sonrası bakiye okuma için kullanılır.
    public static function getPrimary() {
        try {
            $pdo = new PDO("mysql:host=" . self::$host_primary . ";dbname=" . self::$db_name . ";charset=utf8mb4", self::$username, self::$password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["success" => false, "message" => "Primary DB Bağlantı Hatası: " . $e->getMessage()]);
            exit;
        }
    }

    // OKUMA ve LİSTELEME (Replica - 3306 / Salt Okunur)
    // SELECT sorguları, menü listeleme ve raporlar bu sunucuya yönlendirilir.
    public static function getReplica() {
        try {
            $pdo = new PDO("mysql:host=" . self::$host_replica . ";dbname=" . self::$db_name . ";charset=utf8mb4", self::$username, self::$password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            // Replikada bir sorun olursa sistemin çökmemesi için primary'ye fallback yapabiliriz
            return self::getPrimary();
        }
    }
}
?>