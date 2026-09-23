# YF-8 Veri Geçişi ve Demo Hazırlık Raporu

> Tarih: 3 Eylül 2026
> Ortam: Yerel PostgreSQL
> Sonuç: Başarılı; YF-9 yerel kullanıcı kabulüne hazır
> Güvenlik politikası: `security-invariants-not-template-equality`

## 1. Amaç ve sınır

Bu faz yeni birim yetki matrisini veri kaybetmeden doğrular ve yerel kabul verisini hazırlar. Eski global `coordinator` / `staff` rol yetkileri, eski üyelik alanları ve pasif kurallar silinmemiştir. Geçiş araçları yalnız yeni kayıt ekler, açıkça yanlış sahipteki aktif kuralı pasifleştirir veya ayrıca `--reset-defaults` verilirse varsayılanı geri yükler.

Bu rapor yerel geliştirme verisinin sonucudur; canlı ortamın hazır olduğu anlamına gelmez. Canlı pilot için YF-10 kapıları ayrıca uygulanacaktır.

## 2. Yerel veri envanteri

| Ölçüm | Sonuç |
|---|---:|
| Aktif proje | 6 |
| Aktif koordinasyon birimi | 9 |
| Beklenen hizmet sorumluluğu | 24 |
| Aktif hizmet sorumluluğu | 24 |
| Aktif birim permission kuralı | 745 |
| Tarihsel permission kuralı | 902 |
| Pasif/tarihsel kural | 157 |
| Hedef varsayılan kural | 745 |
| Hedefte bulunan aktif kural | 745 |
| Şablonla birebir aktif kural | 745 |
| Hedef dışı ek aktif admin kuralı | 0 |

Plan hazırlanırken kaydedilen `864 aktif kural` eski bir envanter anlık görüntüsüydü. YF-2 ve YF-3 sırasında yanlış proje/hizmet ailesi kuralları silinmeden pasifleştirildi, ayrık action setleri olgunlaştırıldı. Bu nedenle YF-8 başlangıcındaki güncel ve doğrulanan sayı 745'tir. Fark veri kaybı değildir: toplam 902 tarihsel satır korunmaktadır.

## 3. Dry-run, apply ve idempotency sonucu

Uygulanan sıra:

```bash
php artisan coordination-units:backfill --apply --strict
php artisan coordination-units:sync-permissions --apply --strict
php artisan coordination-units:sync-permissions --apply --strict
php artisan coordination-units:cutover-readiness --target=enforce --strict
```

Sonuçlar:

- Backfill `0` yeni değişiklik ve `0` blocker verdi.
- İlk permission apply `0` değişiklik ve `0` blocker verdi; önceki fazların hedef veriyi zaten kurduğu doğrulandı.
- İkinci permission apply yine `0` değişiklik verdi; işlem idempotenttir.
- Enforce readiness yerelde `19` aktif authority kullanıcı, `19` aktif üyelik, `0` teknik engel ve `0` veri engeliyle hazırdır.
- Bu apply sırasında pasifleştirilen yeni bir satır olmadığı için rollback ID listesi boştur: `[]`.
- İleride dry-run yanlış sahipli bir kural bulursa rapordaki `rollback.deactivated_rule_ids` listesi uygulanmadan önce saklanacaktır. Kayıt silinmeyecek, yalnız `passive` olacaktır.

## 4. Readiness güvenlik invariant'ları

Readiness artık bütün satırların kod şablonuyla birebir aynı olmasını istemez. Böylece Yetki Matrisi'nde admin tarafından yapılan güvenli daraltma veya kapsam seçimi geçişi gereksiz yere durdurmaz. Buna karşılık şu güvenlik koşulları zorunludur:

1. Altı aktif projenin her biri için `media`, `finance_procurement`, `organization` ve `community_culture` sorumluluğu tam bir kez bulunmalıdır.
2. Her sorumluluk doğru hizmet birimine, aktif projeye ve aktif birime bağlı olmalıdır.
3. Aktif yetki kuralı aktif birime ve var olan permission adına bağlı olmalıdır.
4. Pozisyon yalnız `coordinator` veya `staff`; scope yalnız desteklenen kaynaklardan biri olmalıdır.
5. Exclusive hizmet permission'ı başka hizmet veya proje birimine verilemez.
6. Proje-family permission'ı projenin türü ve özel modül metadata'sıyla uyumlu olmalıdır.
7. `linked_project` yalnız proje biriminde; `responsibility_projects` yalnız hizmet biriminin sahip olduğu domain ile kullanılabilir.

Bir admin özelleştirmesi bu kuralları ihlal etmiyorsa korunur. Şablondan farklı olması tek başına blocker değildir. Aktif birim/pozisyon için hiç kural kalmaması ayrıca görünür bir uyarıdır.

## 5. Legacy global rol yetkileri

| Legacy rol | Birim-işi permission sayısı | Enforce sınıfı |
|---|---:|---|
| `coordinator` | 124 | `legacy_only_in_enforce` |
| `staff` | 51 | `legacy_only_in_enforce` |

Bu yetkiler henüz silinmemiştir ve `legacy` moda geri dönüş için korunur. `enforce` modunda coordinator/staff iş yetkisinin kaynağı global rol değil; seçili aktif üyeliğin birimi, pozisyonu, birim kuralı ve varsa üyelik-bazlı override'dır. Bu nedenle örneğin proje koordinatöründeki eski global `financial.*` kaydı Satın Alma ve Organizasyon biriminin yetkisini belirlemez ve proje koordinatörüne finans erişimi açmaz.

## 6. Demo kabul verisi

`DemoProjectRoleDataSeeder` yerel kabul için şunları idempotent biçimde üretir:

- 6 proje biriminin her biri için 1 coordinator ve 1 staff: 12 authority hesabı,
- 3 hizmet biriminin her biri için 1 coordinator ve 1 staff: 6 authority hesabı,
- toplam 18 yeni matris hesabı,
- 6 çekirdek program ve 6 topluluk etkinliği,
- dokuz birim, üyelikler, proje-hizmet sorumlulukları ve permission kuralları.

Mevcut `koordinator@kademe.org` legacy örnek hesabı da normalize üyeliğe sahip olduğundan readiness toplamı 19'dur; YF-9'un 18 hesaplı matrisi bundan ayrıdır. Seeder:

- yalnız `local` ve `testing` ortamlarında çalışır,
- production ortamında istisna vererek durur,
- ikinci çalıştırmada kayıt çoğaltmaz,
- adminin pasifleştirdiği birim kuralını kendiliğinden yeniden açmaz.

## 7. Otomatik güvence

YF-8 testleri şu durumları kapsar:

- güvenli admin özelleştirmesinin korunması,
- eski global rol permission'larının legacy-only raporlanması,
- yanlış sahipli exclusive kuralın readiness'i durdurması,
- yanlış kuralın silinmeden pasifleştirilmesi ve rollback ID'sinin raporlanması,
- ikinci apply'ın sıfır değişiklik vermesi,
- fazladan/yanlış hizmet sorumluluğunun invariant ihlali olması,
- 18 hesaplı demo verisinin kapsam ve rol ayrımı,
- production seeder koruması.

Doğrulama sonuçları:

- YF-8/readiness/senkron/demo odak paketi: **18 test, 410 assertion geçti**.
- Aktif birim, yönetim API, hizmet ayrımı, proje-family, kayıt politikası, Yetki Matrisi ve paylaşılan modül geniş paketi: **44 test, 650 assertion geçti**.
- Tam backend regresyonu: **454 test, 3490 assertion geçti; 3 bilinen ortam-bağımlı yarış/SQLite senaryosu skip kaldı**.
- İlgili PHP syntax, Pint ve `git diff --check` kontrolleri geçti.

## 8. Faz sonucu ve sonraki kapı

YF-8 kabul ölçütleri karşılandı. Eski yapılar korunmuş, hedef aktif matris tam, veri geçişi idempotent ve yerel enforce readiness temizdir.

Sıradaki faz YF-9'dur: dokuz birim × iki pozisyon için sidebar, route, liste verisi, proje dropdown kapsamı, görünür butonlar, doğrudan API 403, çapraz kayıt, export/indirme, dashboard, profil/izin, talep/destek ve çoklu üyelik context kabul matrisi çalıştırılacaktır.
