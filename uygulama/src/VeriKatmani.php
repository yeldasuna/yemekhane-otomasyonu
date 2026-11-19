<?php
class VeriKatmani {
    private static function pdoBaglan(string $host): PDO {
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_ADI') ?: 'yemekhane';
        $user = getenv('DB_KULLANICI') ?: 'yemekhane_app';
        $pass = getenv('DB_SIFRE') ?: 'Uyg_2026';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_AUTOCOMMIT => true,
        ]);
        return $pdo;
    }

    public static function yazmaPdo(): PDO {
        $host = getenv('DB_YAZMA_HOST') ?: 'mysql-primary';
        return self::pdoBaglan($host);
    }

    public static function okumaPdo(): PDO {
        $host = getenv('DB_OKUMA_HOST') ?: 'mysql-replica';
        try {
            return self::pdoBaglan($host);
        } catch (Throwable $e) {
            return self::yazmaPdo();
        }
    }

    public static function redisBaglan(): ?Redis {
        if (!class_exists('Redis')) {
            return null;
        }
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        try {
            $redis = new Redis();
            $redis->connect($host, $port, 2.0);
            return $redis;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function kullaniciEpostaIleGetir(string $eposta): ?array {
        $stmt = self::okumaPdo()->prepare(
            "SELECT id, ad, soyad, eposta, sifre_hash, rol, ogrenci_no, bakiye, aktif
             FROM kullanicilar WHERE eposta = ? LIMIT 1"
        );
        $stmt->execute([$eposta]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function kullaniciIdIleGetir(int $id, bool $primary = false): ?array {
        $pdo = $primary ? self::yazmaPdo() : self::okumaPdo();
        $stmt = $pdo->prepare(
            "SELECT id, ad, soyad, eposta, rol, ogrenci_no, bakiye, aktif
             FROM kullanicilar WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function yemekhanelerVeOgunler(): array {
        $pdo = self::okumaPdo();
        $yh  = $pdo->query("SELECT id, ad, konum, kapasite FROM yemekhaneler ORDER BY id")->fetchAll();
        $og  = $pdo->query("SELECT id, ad, sira, son_saat, servis_baslangic, servis_bitis FROM ogunler ORDER BY sira")->fetchAll();
        $yem = $pdo->query("SELECT id, kategori_id, ad, kalori, vejetaryen FROM yemekler ORDER BY id")->fetchAll();
        $kat = $pdo->query("SELECT id, ad, sira FROM yemek_kategorileri ORDER BY sira")->fetchAll();
        $alr = $pdo->query("SELECT id, ad FROM alerjenler ORDER BY id")->fetchAll();
        return [
            'yemekhaneler' => $yh,
            'ogunler'      => $og,
            'yemekler'     => $yem,
            'kategoriler'  => $kat,
            'alerjenler'   => $alr,
        ];
    }

    public static function kullaniciAlerjenleriGetir(int $kullaniciId, bool $primary = false): array {
        $pdo = $primary ? self::yazmaPdo() : self::okumaPdo();
        $stmt = $pdo->prepare(
            "SELECT a.id, a.ad
             FROM kullanici_alerjenleri ka
             JOIN alerjenler a ON a.id = ka.alerjen_id
             WHERE ka.kullanici_id = ?
             ORDER BY a.id ASC"
        );
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public static function kullaniciAlerjenleriKaydet(int $kullaniciId, array $alerjenIdler): array {
        $pdo = self::yazmaPdo();
        $temizIdler = [];
        foreach ($alerjenIdler as $aid) {
            $id = (int)$aid;
            if ($id > 0) {
                $temizIdler[$id] = $id;
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM kullanici_alerjenleri WHERE kullanici_id = ?")->execute([$kullaniciId]);
            if (!empty($temizIdler)) {
                $ins = $pdo->prepare("INSERT INTO kullanici_alerjenleri (kullanici_id, alerjen_id) VALUES (?, ?)");
                foreach ($temizIdler as $id) {
                    $ins->execute([$kullaniciId, $id]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::kullaniciAlerjenleriGetir($kullaniciId, true);
    }

    public static function menuDetaySatirlari(?int $yemekhaneId = null, ?string $baslangicTarih = null, ?string $bitisTarih = null, ?int $ogunId = null): array {
        $sql = "SELECT * FROM v_menu_detay WHERE 1=1";
        $params = [];
        if ($yemekhaneId !== null) {
            $sql .= " AND yemekhane_id = ?";
            $params[] = $yemekhaneId;
        }
        if ($baslangicTarih !== null) {
            $sql .= " AND tarih >= ?";
            $params[] = $baslangicTarih;
        }
        if ($bitisTarih !== null) {
            $sql .= " AND tarih <= ?";
            $params[] = $bitisTarih;
        }
        if ($ogunId !== null) {
            $sql .= " AND ogun_id = ?";
            $params[] = $ogunId;
        }
        $sql .= " ORDER BY tarih ASC, ogun_sira ASC, kategori_sira ASC, yemek_id ASC";
        $stmt = self::okumaPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function menuDolulukSatirlari(?int $yemekhaneId = null, ?string $baslangicTarih = null, ?string $bitisTarih = null): array {
        $sql = "SELECT * FROM v_menu_doluluk WHERE 1=1";
        $params = [];
        if ($yemekhaneId !== null) {
            $sql .= " AND yemekhane_id = ?";
            $params[] = $yemekhaneId;
        }
        if ($baslangicTarih !== null) {
            $sql .= " AND tarih >= ?";
            $params[] = $baslangicTarih;
        }
        if ($bitisTarih !== null) {
            $sql .= " AND tarih <= ?";
            $params[] = $bitisTarih;
        }
        $sql .= " ORDER BY tarih ASC, menu_id ASC";
        $stmt = self::okumaPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function yemekAlerjenHaritasi(): array {
        $sql = "SELECT ya.yemek_id, a.id AS alerjen_id, a.ad AS alerjen_adi
                FROM yemek_alerjenleri ya
                JOIN alerjenler a ON a.id = ya.alerjen_id
                ORDER BY a.id ASC";
        $rows = self::okumaPdo()->query($sql)->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['yemek_id']][] = [
                'id' => (int)$r['alerjen_id'],
                'ad' => $r['alerjen_adi'],
            ];
        }
        return $map;
    }

    public static function kullaniciRezervasyonlari(int $kullaniciId): array {
        $sql = "SELECT
                    r.id AS rezervasyon_id,
                    r.menu_id,
                    r.durum,
                    r.ucret,
                    COALESCE(r.porsiyon, 'tam') AS porsiyon,
                    r.olusturma,
                    r.kullanma_zamani,
                    r.iptal_zamani,
                    m.tarih,
                    m.ogun_id,
                    m.yemekhane_id,
                    o.ad AS ogun,
                    TIMESTAMP(m.tarih, o.son_saat) AS rezervasyon_son,
                    yh.ad AS yemekhane
                FROM rezervasyonlar r
                JOIN menuler m ON m.id = r.menu_id
                JOIN ogunler o ON o.id = m.ogun_id
                JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
                WHERE r.kullanici_id = ?
                ORDER BY m.tarih DESC, o.sira DESC, r.id DESC";
        $stmt = self::okumaPdo()->prepare($sql);
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public static function kullaniciBakiyeHareketleri(int $kullaniciId, bool $primary = false): array {
        $pdo = $primary ? self::yazmaPdo() : self::okumaPdo();
        $stmt = $pdo->prepare(
            "SELECT id, tur, tutar, rezervasyon_id, aciklama, olusturma
             FROM bakiye_hareketleri
             WHERE kullanici_id = ?
             ORDER BY id DESC
             LIMIT 100"
        );
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public static function degerlendirilebilirMenuler(int $kullaniciId): array {
        $sql = "SELECT
                    m.id AS menu_id,
                    m.tarih,
                    yh.ad AS yemekhane,
                    o.ad AS ogun,
                    r.durum AS rezervasyon_durum,
                    r.kullanma_zamani,
                    d.puan AS mevcut_puan,
                    d.yorum AS mevcut_yorum
                FROM rezervasyonlar r
                JOIN menuler m ON m.id = r.menu_id
                JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
                JOIN ogunler o ON o.id = m.ogun_id
                LEFT JOIN degerlendirmeler d ON d.menu_id = m.id AND d.kullanici_id = r.kullanici_id
                WHERE r.kullanici_id = ? AND r.durum = 'kullanildi'
                ORDER BY m.tarih DESC, o.sira DESC";
        $stmt = self::okumaPdo()->prepare($sql);
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public static function yoneticiRaporlari(): array {
        $sql = "SELECT
                    d.menu_id,
                    d.tarih,
                    d.yemekhane_id,
                    d.yemekhane,
                    d.ogun_id,
                    d.ogun,
                    d.kapasite,
                    d.rezerve,
                    d.bos_yer,
                    d.doluluk_yuzde,
                    COALESCE(p.oy_sayisi, 0) AS oy_sayisi,
                    p.ortalama_puan
                FROM v_menu_doluluk d
                LEFT JOIN v_menu_puan p ON p.menu_id = d.menu_id
                ORDER BY d.tarih DESC, d.menu_id DESC
                LIMIT 60";
        return self::okumaPdo()->query($sql)->fetchAll();
    }

    public static function spRezervasyonYap(int $kullaniciId, int $menuId, string $porsiyon = 'tam'): array {
        $porsiyon = strtolower(trim($porsiyon)) === 'yarim' ? 'yarim' : 'tam';
        $stmt = self::yazmaPdo()->prepare("CALL sp_rezervasyon_yap(?, ?, ?)");
        $stmt->execute([$kullaniciId, $menuId, $porsiyon]);
        $res = $stmt->fetch() ?: [];
        while ($stmt->nextRowset()) {}
        return $res;
    }

    public static function spRezervasyonIptal(int $kullaniciId, int $rezervasyonId): array {
        $stmt = self::yazmaPdo()->prepare("CALL sp_rezervasyon_iptal(?, ?)");
        $stmt->execute([$kullaniciId, $rezervasyonId]);
        $res = $stmt->fetch() ?: [];
        while ($stmt->nextRowset()) {}
        return $res;
    }

    public static function spBakiyeYukle(int $kullaniciId, float $tutar): array {
        $stmt = self::yazmaPdo()->prepare("CALL sp_bakiye_yukle(?, ?)");
        $stmt->execute([$kullaniciId, number_format($tutar, 2, '.', '')]);
        $res = $stmt->fetch() ?: [];
        while ($stmt->nextRowset()) {}
        return $res;
    }

    public static function spTurnikeGecis(int $kullaniciId, int $yemekhaneId, ?string $zaman = null): array {
        $stmt = self::yazmaPdo()->prepare("CALL sp_turnike_gecis(?, ?, ?)");
        $stmt->execute([$kullaniciId, $yemekhaneId, $zaman]);
        $res = $stmt->fetch() ?: [];
        while ($stmt->nextRowset()) {}
        return $res;
    }

    public static function degerlendirmeKaydet(int $kullaniciId, int $menuId, int $puan, ?string $yorum): void {
        $pdo = self::yazmaPdo();
        $chk = $pdo->prepare(
            "SELECT id FROM rezervasyonlar WHERE kullanici_id = ? AND menu_id = ? AND durum = 'kullanildi' LIMIT 1"
        );
        $chk->execute([$kullaniciId, $menuId]);
        if (!$chk->fetch()) {
            throw new RuntimeException("Yalnızca kullanılmış menüler değerlendirilebilir.");
        }

        $sql = "INSERT INTO degerlendirmeler (kullanici_id, menu_id, puan, yorum)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE puan = VALUES(puan), yorum = VALUES(yorum)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kullaniciId, $menuId, $puan, $yorum !== '' ? $yorum : null]);
    }

    public static function menuKaydet(array $girdi): int {
        $pdo = self::yazmaPdo();
        $menuId = isset($girdi['menu_id']) && (int)$girdi['menu_id'] > 0 ? (int)$girdi['menu_id'] : 0;
        $yemekhaneId = (int)$girdi['yemekhane_id'];
        $tarih = (string)$girdi['tarih'];
        $ogunId = (int)$girdi['ogun_id'];
        $ogrenciFiyat = number_format((float)$girdi['ogrenci_fiyati'], 2, '.', '');
        $personelFiyat = number_format((float)$girdi['personel_fiyati'], 2, '.', '');
        $yemekIds = array_values(array_unique(array_map('intval', $girdi['yemek_idler'] ?? [])));

        if ($menuId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE menuler
                 SET yemekhane_id = ?, tarih = ?, ogun_id = ?, ogrenci_fiyati = ?, personel_fiyati = ?
                 WHERE id = ?"
            );
            $stmt->execute([$yemekhaneId, $tarih, $ogunId, $ogrenciFiyat, $personelFiyat, $menuId]);
            $pdo->prepare("DELETE FROM menu_yemekleri WHERE menu_id = ?")->execute([$menuId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO menuler (yemekhane_id, tarih, ogun_id, ogrenci_fiyati, personel_fiyati)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$yemekhaneId, $tarih, $ogunId, $ogrenciFiyat, $personelFiyat]);
            $menuId = (int)$pdo->lastInsertId();
        }

        if (!empty($yemekIds)) {
            $ins = $pdo->prepare("INSERT INTO menu_yemekleri (menu_id, yemek_id) VALUES (?, ?)");
            foreach ($yemekIds as $yid) {
                if ($yid > 0) {
                    $ins->execute([$menuId, $yid]);
                }
            }
        }

        return $menuId;
    }

    public static function menuBilgisiGetir(int $menuId): ?array {
        $stmt = self::okumaPdo()->prepare("SELECT id, yemekhane_id, tarih, ogun_id FROM menuler WHERE id = ? LIMIT 1");
        $stmt->execute([$menuId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
?>