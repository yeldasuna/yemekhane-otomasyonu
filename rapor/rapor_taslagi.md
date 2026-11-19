# Yemekhane Otomasyonu - Proje Raporu

## 1. Mimari ve Teknik Özeti
- Mimari Seçimi: Web API + Frontend (HTML5/CSS3) yaklaşımı kullanılmıştır. Statik arayüz dosyaları Apache üzerinden sunulurken `/api/...` istekleri PHP 8.2 tabanlı `uygulama` servisine yönlendirilmektedir.
- Katmanlı Yapı: `VeriKatmani` (PDO primary/replica + Redis), `ServisKatmani` (iş kuralları, oturum/token ve önbellek yönetimi), `Kontrolcu` ve `index.php` ayrımı uygulanmıştır.
- Veritabanı ve Replikasyon: MySQL 8.4 üzerinde `mysql-primary` (yazma ve anlık bakiye okuma) ve `mysql-replica` (salt okunur listeleme/raporlama) ayrımı uygulanmış; para hareketleri stored procedure'ler (`sp_rezervasyon_yap`, `sp_rezervasyon_iptal`, `sp_bakiye_yukle`, `sp_turnike_gecis`) üzerinden yürütülmüştür.
- Redis: Kullanıcı oturumları (`oturum:{token}`) ve menü önbelleği (`menu:...`) Redis üzerinde tutulmaktadır.

## 2. Aşama 1: Altyapı ve Replikasyon Doğrulama
- Tüm container'lar (`mysql-primary`, `mysql-replica`, `redis`, `uygulama`, `frontend`) sağlıklı şekilde başlatıldı; `replica-setup` kurulumu tamamlayıp `Exited (0)` ile çıktı.
- `mysql-primary` üzerinde `sp_bakiye_yukle` ile bakiye güncellendi ve replikaya yansıdığı doğrulandı.
- `mysql-replica` üzerinde salt okunur (read-only) kısıtı doğrulandı; replika durdurulup yeniden başlatıldığında aradaki değişiklikleri yakaladığı teyit edildi.

## 3. Aşama 2: Uygulama Katmanı ve 7 Ekran
- 1. Giriş ve Oturum, 2. Günün Menüsü, 3. Haftalık Menü & Rezervasyon, 4. Rezervasyonlarım, 5. Bakiye, 6. Değerlendirme ve 7. Yönetici Paneli ekranları oluşturuldu.
- Mobil görünüm (~400px) uyumu ve Türkçe hata mesajlarının arayüze aktarımı sağlandı.

## 4. Aşama 3: Redis Oturum ve Önbellek
- Giriş işlemlerinde Redis'te `oturum:{token}` anahtarları oluşturuldu.
- Menü listeleme isteklerinde Redis önbellek (`menu:...`) kullanımı ve menü güncellemelerinde önbellek temizliği eklendi.

## 5. Aşama 4: Veritabanı Değişikliği (Migration)
- `db/init/04_degisiklik.sql` dosyası oluşturularak `yemek_notlari` tablosu eklendi ve `mysql-primary` üzerinden uygulanıp `mysql-replica` üzerinde replikasyonla doğrulandı.