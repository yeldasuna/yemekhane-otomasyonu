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
        if (!is_array($veri) || empty($veri['id']) && empty($veri['kullanici_id'])) {
            return null;
        }
        if (empty($veri['kullanici_id']) && !empty($veri['id'])) {
            $veri['kullanici_id'] = (int)$veri['id'];
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
        $kullanici['kullanici_id'] = (int)$kullanici['id'];
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

    public static function gunSonuTtlSaniye(?string $tarih = null): int {
        $bazTarih = ($tarih && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih))
            ? $tarih
            : date('Y-m-d');
        $simdi = time();
        $gunSonu = strtotime($bazTarih . ' 23:59:59');
        if ($gunSonu === false || $gunSonu <= $simdi) {
            return 60;
        }
        return max(60, $gunSonu - $simdi);
    }

    public static function menuOnbellekAnahtari(int $yemekhaneId, string $tarih, int $ogunId): string {
        return "menu:{$yemekhaneId}:{$tarih}:{$ogunId}";
    }

    public static function menuleriGetirOnbellekli(?int $yemekhaneId, ?string $baslangic, ?string $bitis, ?int $ogunId = null): array {
        $tekGunTekMenu = ($yemekhaneId !== null && $yemekhaneId > 0
            && $ogunId !== null && $ogunId > 0
            && $baslangic !== null && $baslangic !== ''
            && ($bitis === null || $bitis === '' || $bitis === $baslangic));

        $redis = VeriKatmani::redisBaglan();
        if ($tekGunTekMenu) {
            $anahtar = self::menuOnbellekAnahtari($yemekhaneId, $baslangic, $ogunId);
            if ($redis) {
                $kayitli = $redis->get($anahtar);
                if ($kayitli) {
                    $cozulen = json_decode($kayitli, true);
                    if (is_array($cozulen)) {
                        error_log("[REDIS-CACHE] HIT anahtar={$anahtar} kaynak=redis");
                        return ['kaynak' => 'redis', 'anahtar' => $anahtar, 'data' => $cozulen];
                    }
                }
            }

            $menuler = self::menuleriGrupla($yemekhaneId, $baslangic, $baslangic, $ogunId);
            if ($redis) {
                $ttl = self::gunSonuTtlSaniye($baslangic);
                $redis->setex($anahtar, $ttl, json_encode($menuler, JSON_UNESCAPED_UNICODE));
            }
            error_log("[REDIS-CACHE] MISS anahtar={$anahtar} kaynak=mysql-replica");
            return ['kaynak' => 'mysql-replica', 'anahtar' => $anahtar, 'data' => $menuler];
        }

        $menuler = self::menuleriGrupla($yemekhaneId, $baslangic, $bitis, $ogunId);
        if ($redis && !empty($menuler)) {
            $gruplar = [];
            foreach ($menuler as $m) {
                $key = self::menuOnbellekAnahtari((int)$m['yemekhane_id'], (string)$m['tarih'], (int)$m['ogun_id']);
                $gruplar[$key][] = $m;
            }
            foreach ($gruplar as $key => $parca) {
                $tarihParca = explode(':', $key)[2] ?? null;
                $redis->setex($key, self::gunSonuTtlSaniye($tarihParca), json_encode($parca, JSON_UNESCAPED_UNICODE));
            }
        }
        error_log("[MENU-SORGU] kaynak=mysql-replica");
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
                $ogunAd = (string)$s['ogun'];
                $kahvaltiMi = (stripos($ogunAd, 'kahvalt') !== false || stripos($ogunAd, 'kahvalti') !== false);
                $ogrFiyat = (float)$s['ogrenci_fiyati'];
                $perFiyat = (float)$s['personel_fiyati'];
                $menuler[$mid] = [
                    'menu_id'             => $mid,
                    'tarih'               => $s['tarih'],
                    'yemekhane_id'        => (int)$s['yemekhane_id'],
                    'yemekhane'           => $s['yemekhane'],
                    'ogun_id'             => (int)$s['ogun_id'],
                    'ogun'                => $ogunAd,
                    'ogun_sira'           => (int)$s['ogun_sira'],
                    'rezervasyon_son'     => $s['rezervasyon_son'],
                    'ogrenci_fiyati'      => $ogrFiyat,
                    'personel_fiyati'     => $perFiyat,
                    'yarim_izinli'        => !$kahvaltiMi,
                    'ogrenci_yarim_fiyat' => $kahvaltiMi ? null : round($ogrFiyat * 0.60, 2),
                    'personel_yarim_fiyat'=> $kahvaltiMi ? null : round($perFiyat * 0.60, 2),
                    'kapasite'            => isset($dol['kapasite']) ? (int)$dol['kapasite'] : null,
                    'rezerve'             => isset($dol['rezerve']) ? (int)$dol['rezerve'] : null,
                    'bos_yer'             => isset($dol['bos_yer']) ? (int)$dol['bos_yer'] : null,
                    'doluluk_yuzde'       => isset($dol['doluluk_yuzde']) ? (float)$dol['doluluk_yuzde'] : null,
                    'toplam_kalori'       => 0,
                    'yemekler'            => [],
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
        if ($yemekhaneId && $yemekhaneId > 0 && $tarih && $tarih !== '' && $ogunId && $ogunId > 0) {
            $anahtar = self::menuOnbellekAnahtari($yemekhaneId, $tarih, $ogunId);
            $redis->del($anahtar);
            error_log("[REDIS-CACHE] INVALIDATE anahtar={$anahtar}");
            return;
        }
        $keys = $redis->keys('menu:*');
        if (is_array($keys) && !empty($keys)) {
            $redis->del($keys);
            error_log("[REDIS-CACHE] INVALIDATE adet=" . count($keys));
        }
    }

    public static function rezervasyonYap(int $kullaniciId, int $menuId, string $porsiyon = 'tam'): array {
        $sonuc = VeriKatmani::spRezervasyonYap($kullaniciId, $menuId, $porsiyon);
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