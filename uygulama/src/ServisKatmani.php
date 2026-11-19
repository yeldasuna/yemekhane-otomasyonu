<?php
require_once __DIR__ . '/VeriKatmani.php';

class ServisKatmani {
    public static function temizHataMesaji(Throwable $e): string {
        $msg = $e->getMessage();
        if ($e instanceof PDOException && isset($e->errorInfo[2])) {
            $msg = (string)$e->errorInfo[2];
        }
        if (preg_match('/1644\s*\([^)]+\):\s*(.+)$/', $msg, $m)) {
            return trim($m[1]);
        }
        return $msg;
    }

    public static function oturumOku(?string $token): ?array {
        if (!$token) {
            return null;
        }
        $redis = VeriKatmani::redisBaglan();
        if (!$redis) {
            return null;
        }
        $anahtar = "oturum:{$token}";
        $ham = $redis->get($anahtar);
        if (!$ham) {
            return null;
        }
        $redis->expire($anahtar, 1800);
        $veri = json_decode($ham, true);
        if (!is_array($veri) || empty($veri['kullanici_id'])) {
            return null;
        }
        $veri['token'] = $token;
        return $veri;
    }

    public static function girisYap(string $eposta, string $sifre): array {
        $kullanici = VeriKatmani::kullaniciEpostaIleGetir(trim($eposta));
        if (!$kullanici || (int)$kullanici['aktif'] !== 1) {
            throw new RuntimeException("E-posta veya şifre hatalı.");
        }
        if (!password_verify($sifre, (string)$kullanici['sifre_hash'])) {
            throw new RuntimeException("E-posta veya şifre hatalı.");
        }

        unset($kullanici['sifre_hash']);
        $token = bin2hex(random_bytes(24));
        $redis = VeriKatmani::redisBaglan();
        if ($redis) {
            $redis->setex("oturum:{$token}", 1800, json_encode($kullanici, JSON_UNESCAPED_UNICODE));
        }

        return [
            'token' => $token,
            'kullanici' => $kullanici,
        ];
    }

    public static function cikisYap(?string $token): void {
        if (!$token) {
            return;
        }
        $redis = VeriKatmani::redisBaglan();
        if ($redis) {
            $redis->del("oturum:{$token}");
        }
    }

    public static function menuOnbellekAnahtari(?int $yemekhaneId, ?string $baslangic, ?string $bitis, ?int $ogunId): string {
        $y = $yemekhaneId ?: 'tumu';
        $b = $baslangic ?: 'yok';
        $s = $bitis ?: 'yok';
        $o = $ogunId ?: 'tumu';
        return "menu:{$y}:{$b}:{$s}:{$o}";
    }

    public static function menuleriGetirOnbellekli(?int $yemekhaneId, ?string $baslangic, ?string $bitis, ?int $ogunId = null): array {
        $anahtar = self::menuOnbellekAnahtari($yemekhaneId, $baslangic, $bitis, $ogunId);
        $redis = VeriKatmani::redisBaglan();
        if ($redis) {
            $kayitli = $redis->get($anahtar);
            if ($kayitli) {
                $cozulen = json_decode($kayitli, true);
                if (is_array($cozulen)) {
                    return ['kaynak' => 'redis', 'data' => $cozulen];
                }
            }
        }

        $menuler = self::menuleriGrupla($yemekhaneId, $baslangic, $bitis, $ogunId);
        if ($redis) {
            $redis->setex($anahtar, 300, json_encode($menuler, JSON_UNESCAPED_UNICODE));
        }
        return ['kaynak' => 'mysql-replica', 'data' => $menuler];
    }

    public static function menuleriGrupla(?int $yemekhaneId, ?string $baslangic, ?string $bitis, ?int $ogunId = null): array {
        $satirlar = VeriKatmani::menuDetaySatirlari($yemekhaneId, $baslangic, $bitis, $ogunId);
        $doluluklar = VeriKatmani::menuDolulukSatirlari($yemekhaneId, $baslangic, $bitis);
        $alerjenHarita = VeriKatmani::yemekAlerjenHaritasi();

        $dolulukMap = [];
        foreach ($doluluklar as $d) {
            $dolulukMap[(int)$d['menu_id']] = $d;
        }

        $menuler = [];
        foreach ($satirlar as $s) {
            $mid = (int)$s['menu_id'];
            if (!isset($menuler[$mid])) {
                $dol = $dolulukMap[$mid] ?? [];
                $menuler[$mid] = [
                    'menu_id'         => $mid,
                    'tarih'           => $s['tarih'],
                    'yemekhane_id'    => (int)$s['yemekhane_id'],
                    'yemekhane'       => $s['yemekhane'],
                    'ogun_id'         => (int)$s['ogun_id'],
                    'ogun'            => $s['ogun'],
                    'ogun_sira'       => (int)$s['ogun_sira'],
                    'rezervasyon_son' => $s['rezervasyon_son'],
                    'ogrenci_fiyati'  => (float)$s['ogrenci_fiyati'],
                    'personel_fiyati' => (float)$s['personel_fiyati'],
                    'kapasite'        => isset($dol['kapasite']) ? (int)$dol['kapasite'] : null,
                    'rezerve'         => isset($dol['rezerve']) ? (int)$dol['rezerve'] : null,
                    'bos_yer'         => isset($dol['bos_yer']) ? (int)$dol['bos_yer'] : null,
                    'doluluk_yuzde'   => isset($dol['doluluk_yuzde']) ? (float)$dol['doluluk_yuzde'] : null,
                    'toplam_kalori'   => 0,
                    'yemekler'        => [],
                ];
            }

            if (!empty($s['yemek_id'])) {
                $yid = (int)$s['yemek_id'];
                $kal = (int)($s['kalori'] ?? 0);
                $menuler[$mid]['toplam_kalori'] += $kal;
                $menuler[$mid]['yemekler'][] = [
                    'yemek_id'   => $yid,
                    'ad'         => $s['yemek'],
                    'kategori'   => $s['kategori'],
                    'kalori'     => $kal,
                    'vejetaryen' => (int)($s['vejetaryen'] ?? 0) === 1,
                    'alerjenler' => $alerjenHarita[$yid] ?? [],
                ];
            }
        }

        return array_values($menuler);
    }

    public static function menuOnbellekSil(?int $yemekhaneId, ?string $tarih, ?int $ogunId): void {
        $redis = VeriKatmani::redisBaglan();
        if (!$redis) {
            return;
        }
        $keys = $redis->keys('menu:*');
        if (is_array($keys) && !empty($keys)) {
            $redis->del($keys);
        }
    }

    public static function rezervasyonYap(int $kullaniciId, int $menuId): array {
        $sonuc = VeriKatmani::spRezervasyonYap($kullaniciId, $menuId);
        $guncel = VeriKatmani::kullaniciIdIleGetir($kullaniciId, true);
        return [
            'islem' => $sonuc,
            'guncel_bakiye' => $guncel ? (float)$guncel['bakiye'] : null,
        ];
    }

    public static function rezervasyonIptal(int $kullaniciId, int $rezervasyonId): array {
        $sonuc = VeriKatmani::spRezervasyonIptal($kullaniciId, $rezervasyonId);
        $guncel = VeriKatmani::kullaniciIdIleGetir($kullaniciId, true);
        return [
            'islem' => $sonuc,
            'guncel_bakiye' => $guncel ? (float)$guncel['bakiye'] : null,
        ];
    }

    public static function bakiyeYukle(int $kullaniciId, float $tutar): array {
        if ($tutar <= 0) {
            throw new RuntimeException("Yüklenecek tutar sıfırdan büyük olmalıdır.");
        }
        $sonuc = VeriKatmani::spBakiyeYukle($kullaniciId, $tutar);
        $guncel = VeriKatmani::kullaniciIdIleGetir($kullaniciId, true);
        return [
            'islem' => $sonuc,
            'guncel_bakiye' => $guncel ? (float)$guncel['bakiye'] : null,
        ];
    }
}
?>