CREATE TABLE IF NOT EXISTS yemek_notlari (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    yemek_id     SMALLINT UNSIGNED NOT NULL,
    not_metni    VARCHAR(200) NOT NULL,
    olusturma    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_yemek_not_yemek (yemek_id),
    CONSTRAINT fk_yn_yemek FOREIGN KEY (yemek_id) REFERENCES yemekler (id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO yemek_notlari (yemek_id, not_metni)
SELECT id, 'Gunluk taze hazirlanmistir.'
FROM yemekler
LIMIT 3
ON DUPLICATE KEY UPDATE not_metni = VALUES(not_metni);