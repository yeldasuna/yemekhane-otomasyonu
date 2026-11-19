<?php
require_once __DIR__ . '/ServisKatmani.php';

class Kontrolcu {
    private function jsonGovde(): array {
        $ham = file_get_contents('php://input');
        if (!$ham) {
            return [];
        }
        $veri = json_decode($ham, true);
        return is_array($veri) ? $veri : [];
    }

    private function tokenAl(): ?string {
        $hdr = $_SERVER['HTTP_X_OTURUM_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($hdr, 'Bearer ') === 0) {
            return trim(substr($hdr, 7));
        }
        return $hdr !== '' ? trim($hdr) : null;
    }

    private function oturumKullanici(): ?array {
        $oturum = ServisKatmani::oturumOku($this->tokenAl());
        if ($oturum) {
            return $oturum;
        }
        if (!empty($_GET['kullanici_id'])) {
            $k = VeriKatmani::kullaniciIdIleGetir((int)$_GET['kullanici_id'], false);
            if ($k) {
                return ['kullanici_id' => (int)$k['id'], 'rol' => $k['rol']];
            }
        }
        $govde = $this->jsonGovde();
        if (!empty($govde['kullanici_id'])) {
            $k = VeriKatmani::kullaniciIdIleGetir((int)$govde['kullanici_id'], true);
            if ($k) {
                return ['kullanici_id' => (int)$k['id'], 'rol' => $k['rol']];
            }
        }
        return null;
    }

    public function yonlendir(string $yol, string $metot): void {
        try {
            if ($yol === '/api/saglik' && $metot === 'GET') {
                $this->yanit(['success' => true, 'durum' => 'tamam']);
                return;
            }

            if ($yol === '/api/giris' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $eposta = (string)($g['eposta'] ?? '');
                $sifre = (string)($g['sifre'] ?? '');
                if ($eposta === '' || $sifre === '') {
                    $this->yanit(['success' => false, 'message' => 'E-posta ve şifre zorunludur.'], 400);
                    return;
                }
                $sonuc = ServisKatmani::girisYap($eposta, $sifre);
                $this->yanit(['success' => true, 'data' => $sonuc['kullanici'], 'token' => $sonuc['token']]);
                return;
            }

            if ($yol === '/api/cikis' && $metot === 'POST') {
                ServisKatmani::cikisYap($this->tokenAl());
                $this->yanit(['success' => true, 'message' => 'Çıkış yapıldı.']);
                return;
            }

            if ($yol === '/api/meta' && $metot === 'GET') {
                $this->yanit(['success' => true, 'kaynak' => 'mysql-replica', 'data' => VeriKatmani::yemekhanelerVeOgunler()]);
                return;
            }

            if ($yol === '/api/alerjenlerim' && $metot === 'GET') {
                $oturum = $this->oturumKullanici();
                $kId = (int)($_GET['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                if ($kId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 400);
                    return;
                }
                $this->yanit([
                    'success' => true,
                    'kaynak' => 'mysql-replica',
                    'data' => VeriKatmani::kullaniciAlerjenleriGetir($kId, false),
                ]);
                return;
            }

            if ($yol === '/api/alerjenlerim' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $oturum = $this->oturumKullanici();
                $kId = (int)($g['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                if ($kId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 400);
                    return;
                }
                $alerjenIdler = is_array($g['alerjen_idler'] ?? null) ? $g['alerjen_idler'] : [];
                $kayitli = VeriKatmani::kullaniciAlerjenleriKaydet($kId, $alerjenIdler);
                $this->yanit([
                    'success' => true,
                    'kaynak' => 'mysql-primary',
                    'message' => 'Alerjen tercihleriniz güncellendi.',
                    'data' => $kayitli,
                ]);
                return;
            }

            if ($yol === '/api/menuler' && $metot === 'GET') {
                $yhId = isset($_GET['yemekhane_id']) && $_GET['yemekhane_id'] !== '' ? (int)$_GET['yemekhane_id'] : null;
                $ogunId = isset($_GET['ogun_id']) && $_GET['ogun_id'] !== '' ? (int)$_GET['ogun_id'] : null;
                $baslangic = isset($_GET['baslangic']) && $_GET['baslangic'] !== '' ? (string)$_GET['baslangic'] : (isset($_GET['tarih']) && $_GET['tarih'] !== '' ? (string)$_GET['tarih'] : null);
                $bitis = isset($_GET['bitis']) && $_GET['bitis'] !== '' ? (string)$_GET['bitis'] : (isset($_GET['tarih']) && $_GET['tarih'] !== '' ? (string)$_GET['tarih'] : null);

                $paket = ServisKatmani::menuleriGetirOnbellekli($yhId, $baslangic, $bitis, $ogunId);
                $this->yanit(['success' => true, 'kaynak' => $paket['kaynak'], 'data' => $paket['data']]);
                return;
            }

            if ($yol === '/api/rezervasyon' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $oturum = $this->oturumKullanici();
                $kId = (int)($g['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                $mId = (int)($g['menu_id'] ?? 0);
                $porsiyon = (string)($g['porsiyon'] ?? 'tam');
                if ($kId <= 0 || $mId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı ve menü bilgisi gereklidir.'], 400);
                    return;
                }
                $sonuc = ServisKatmani::rezervasyonYap($kId, $mId, $porsiyon);
                $this->yanit([
                    'success' => true,
                    'message' => 'Rezervasyon başarıyla oluşturuldu.',
                    'guncel_bakiye' => $sonuc['guncel_bakiye'],
                    'bakiye_kaynak' => 'mysql-primary',
                    'data' => $sonuc['islem'],
                ]);
                return;
            }

            if ($yol === '/api/rezervasyonlarim' && $metot === 'GET') {
                $oturum = $this->oturumKullanici();
                $kId = (int)($_GET['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                if ($kId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 400);
                    return;
                }
                $liste = VeriKatmani::kullaniciRezervasyonlari($kId);
                $this->yanit(['success' => true, 'kaynak' => 'mysql-replica', 'data' => $liste]);
                return;
            }

            if ($yol === '/api/rezervasyon/iptal' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $oturum = $this->oturumKullanici();
                $kId = (int)($g['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                $rId = (int)($g['rezervasyon_id'] ?? 0);
                if ($kId <= 0 || $rId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Rezervasyon bilgisi eksik.'], 400);
                    return;
                }
                $sonuc = ServisKatmani::rezervasyonIptal($kId, $rId);
                $this->yanit([
                    'success' => true,
                    'message' => 'Rezervasyon iptal edildi.',
                    'guncel_bakiye' => $sonuc['guncel_bakiye'],
                    'bakiye_kaynak' => 'mysql-primary',
                    'data' => $sonuc['islem'],
                ]);
                return;
            }

            if ($yol === '/api/bakiye' && $metot === 'GET') {
                $oturum = $this->oturumKullanici();
                $kId = (int)($_GET['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                $anlik = isset($_GET['anlik']) && $_GET['anlik'] === '1';
                if ($kId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 400);
                    return;
                }
                $k = VeriKatmani::kullaniciIdIleGetir($kId, $anlik);
                $hareketler = VeriKatmani::kullaniciBakiyeHareketleri($kId, $anlik);
                $this->yanit([
                    'success' => true,
                    'kaynak' => $anlik ? 'mysql-primary' : 'mysql-replica',
                    'bakiye' => $k ? (float)$k['bakiye'] : 0.0,
                    'hareketler' => $hareketler,
                ]);
                return;
            }

            if ($yol === '/api/bakiye-yukle' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $oturum = $this->oturumKullanici();
                $kId = (int)($g['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                $tutar = (float)($g['tutar'] ?? ($g['miktar'] ?? 0));
                if ($kId <= 0 || $tutar <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Geçerli bir tutar giriniz.'], 400);
                    return;
                }
                $sonuc = ServisKatmani::bakiyeYukle($kId, $tutar);
                $this->yanit([
                    'success' => true,
                    'message' => 'Bakiye başarıyla yüklendi.',
                    'guncel_bakiye' => $sonuc['guncel_bakiye'],
                    'kaynak' => 'mysql-primary',
                    'data' => $sonuc['islem'],
                ]);
                return;
            }

            if ($yol === '/api/degerlendirmeler' && $metot === 'GET') {
                $oturum = $this->oturumKullanici();
                $kId = (int)($_GET['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                if ($kId <= 0) {
                    $this->yanit(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 400);
                    return;
                }
                $this->yanit([
                    'success' => true,
                    'kaynak' => 'mysql-replica',
                    'data' => VeriKatmani::degerlendirilebilirMenuler($kId),
                ]);
                return;
            }

            if ($yol === '/api/degerlendirme' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $oturum = $this->oturumKullanici();
                $kId = (int)($g['kullanici_id'] ?? ($oturum['kullanici_id'] ?? 0));
                $mId = (int)($g['menu_id'] ?? 0);
                $puan = (int)($g['puan'] ?? 0);
                $yorum = isset($g['yorum']) ? trim((string)$g['yorum']) : null;
                if ($kId <= 0 || $mId <= 0 || $puan < 1 || $puan > 5) {
                    $this->yanit(['success' => false, 'message' => 'Menü ve 1-5 arası puan zorunludur.'], 400);
                    return;
                }
                VeriKatmani::degerlendirmeKaydet($kId, $mId, $puan, $yorum);
                $this->yanit(['success' => true, 'message' => 'Değerlendirmeniz kaydedildi.']);
                return;
            }

            if ($yol === '/api/yonetici/raporlar' && $metot === 'GET') {
                $this->yanit([
                    'success' => true,
                    'kaynak' => 'mysql-replica',
                    'data' => VeriKatmani::yoneticiRaporlari(),
                ]);
                return;
            }

            if ($yol === '/api/yonetici/menu' && $metot === 'POST') {
                $g = $this->jsonGovde();
                $onceki = !empty($g['menu_id']) ? VeriKatmani::menuBilgisiGetir((int)$g['menu_id']) : null;
                $menuId = VeriKatmani::menuKaydet($g);
                if ($onceki) {
                    ServisKatmani::menuOnbellekSil((int)$onceki['yemekhane_id'], (string)$onceki['tarih'], (int)$onceki['ogun_id']);
                }
                ServisKatmani::menuOnbellekSil(
                    isset($g['yemekhane_id']) ? (int)$g['yemekhane_id'] : null,
                    isset($g['tarih']) ? (string)$g['tarih'] : null,
                    isset($g['ogun_id']) ? (int)$g['ogun_id'] : null
                );
                $this->yanit([
                    'success' => true,
                    'message' => 'Menü başarıyla kaydedildi.',
                    'menu_id' => $menuId,
                ]);
                return;
            }

            $this->yanit(['success' => false, 'message' => 'Geçersiz API uç noktası.'], 404);
        } catch (Throwable $e) {
            $this->yanit(['success' => false, 'message' => ServisKatmani::temizHataMesaji($e)], 400);
        }
    }

    private function yanit(array $veri, int $kod = 200): void {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    }
}
?>