# YF-10B — Yerelden Canlıya Güvenli Geçiş Kapısı

> Hazırlık tarihi: 23 Eylül 2026
> Durum: Kod, yerel migration, strict enforce ve yalnız demo hesaplı pilot hazır; gerçek ortam erişimi ve canlı kullanıcı/birim verisi bekleniyor
> Temel ilke: Önce `legacy`, sonra `shadow`, sonra sınırlı `pilot`, en son `enforce`

## 1. Bu pakette canlıya taşınacak son iş kararı

Topluluk ve Kültür personeli:

- ortak etkinliği ve galeriyi görüntüler,
- manuel yoklama alır,
- lojistik alanlarını günceller,
- ortak etkinlik oluşturamaz veya etkinliğin çekirdek alanlarını güncelleyemez.

Koordinatör create/update yetkisini korur. `2026_09_23_000001_retire_community_staff_event_write_permissions.php` migration'ı mevcut staff create/update kural satırlarını silmez; yalnız `passive` yapar ve bitiş zamanını kaydeder.

## 2. Yerel kapının kapanma şartları

**Kapı sonucu — 23 Eylül 2026: Tamamlandı.** İki ek yerel deneme hesabı mevcut proje ilişkilerine göre Eurodesk ve Diplomasi360 proje birimlerine bağlandı. Strict enforce readiness **21 authority kullanıcı, 23 aktif üyelik ve 0 engel** verdi. Pilot readiness yalnız 18 demo coordinator/staff hesabıyla **0 engel** geçti; demo dışı hesaplar test aktörü yapılmadı.

Canlı dağıtımdan önce:

1. Yerel veritabanındaki üyeliği olmayan aktif authority hesapları sınıflandırılır.
2. Gerçek hesapsa doğru birime ve pozisyona bağlanır; deneme hesabıysa kullanıcı sahibinin kararıyla pasifleştirilir.
3. Aşağıdaki komut sıfır veri engeli vermelidir:

```bash
php artisan coordination-units:cutover-readiness --target=enforce --strict
```

4. Topluluk coordinator hesabında “Yeni Ortak Etkinlik” ve “Düzenle”; staff hesabında yalnız Yoklama, Galeri ve Lojistik kontrolleri elle görülür.
5. Güncel kalite kapısı korunur: frontend tam lint 0 hata ve production build 101 route ile geçmiştir.

## 3. Canlı ön koşulları

Depo incelemesinde hem backend hem frontend `Dockerfile` dosyalarının Railway/Docker dağıtımına göre hazırlandığı görüldü; iki depo da GitHub `main` dalındaki ayrı origin adreslerine bağlıdır. Ancak Railway servisinin hangi dalı otomatik dağıttığı, staging servisi bulunup bulunmadığı, release/migration komutu ve PostgreSQL yedekleme yöntemi depo içinden doğrulanamaz. Bunlar push öncesinde Railway panelinden kesinleştirilir.

- Canlı sunucu/SSH veya platform dağıtım yöntemi bilinmelidir.
- Uygulamanın gerçek `.env` dosyası ve PostgreSQL bağlantısı sunucuda mevcut olmalıdır; sır veya parola dokümana yazılmaz.
- Dağıtımdan hemen önce doğrulanmış PostgreSQL yedeği/snapshot alınmalıdır.
- Gerçek coordinator/staff → birim → pozisyon listesi hazır olmalıdır.
- Mümkünse staging kullanılmalıdır. Staging yoksa canlı ilk açılış `legacy` kipinde kalır ve yalnız açık pilot kullanıcılarla ilerlenir.
- `DemoProjectRoleDataSeeder` staging veya production üzerinde kesinlikle çalıştırılmaz.

## 4. Dağıtım sırası

### A. Güvenli kod ve şema yayılımı

Canlı başlangıç kipi:

```dotenv
COORDINATION_AUTHORIZATION_MODE=legacy
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

Yedek alındıktan sonra:

```bash
php artisan migrate --force
php artisan optimize:clear
```

Migration başarısızsa burada durulur. Permission sync veya enforce açılmaz.

### B. Salt okunur veri kontrolleri

```bash
php artisan coordination-units:backfill --format=json --strict
php artisan coordination-units:sync-permissions --format=json --strict
php artisan financials:backfill-processing-units --format=json
php artisan coordination-units:audit-global-overrides --json
php artisan coordination-units:cutover-readiness --target=shadow --format=json --strict
```

Dry-run çıktıları dağıtım kanıtı olarak saklanır. Açıklanamayan create/update/deactivate, eksik sorumluluk veya teknik blocker varsa apply yapılmaz.

### C. Onaylı veri apply

Yalnız dry-run incelendikten sonra:

```bash
php artisan coordination-units:backfill --apply --format=json --strict
php artisan coordination-units:sync-permissions --apply --format=json --strict
php artisan financials:backfill-processing-units --apply --format=json
```

İkinci permission sync dry-run `proposed_change_count=0` vermelidir.

### D. Gerçek üyelik girişi

Gerçek kullanıcılar `/panel/coordination-units` ekranından doğru birime `coordinator` veya `staff` pozisyonuyla bağlanır. Tahmini `staff_profiles.unit` eşlemesi veya doğrudan elle SQL kullanılmaz. Bir kullanıcı birden fazla birimde olabilir; yalnız bir aktif üyelik primary olur.

Ardından:

```bash
php artisan coordination-units:cutover-readiness --target=enforce --format=json --strict
```

`requested_target_ready=true` olmadan geniş açılım yapılmaz.

### E. Shadow

```dotenv
COORDINATION_AUTHORIZATION_MODE=shadow
COORDINATION_AUTHORIZATION_SHADOW_LOG=true
```

```bash
php artisan optimize:clear
```

Legacy sonuç kullanıcıya hizmet vermeye devam eder; yeni birim sonucu loglarda karşılaştırılır. Açıklanamayan `legacy_only_permissions`, `unit_only_permissions`, scope farkı veya kayıt sızıntısı varsa pilot açılmaz.

### F. Sınırlı pilot

Önce bir proje coordinator/staff ve üç hizmet biriminden birer coordinator/staff seçilir:

```dotenv
COORDINATION_AUTHORIZATION_MODE=pilot
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=GERCEK_KULLANICI_ID_LISTESI
```

```bash
php artisan coordination-units:cutover-readiness --target=pilot --user=ID --format=json --strict
php artisan optimize:clear
```

Pilot kullanıcılarında sidebar, doğrudan URL/API 403, kayıt scope'u, talep/destek, izin, program, mali işlem ve çoklu üyelik kontrol edilir. Diğer kullanıcılar legacy davranışında kalır.

### G. Tam enforce

Pilot kabulü ve tam readiness başarılı olduğunda:

```dotenv
COORDINATION_AUTHORIZATION_MODE=enforce
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

```bash
php artisan coordination-units:cutover-readiness --target=enforce --strict
php artisan optimize:clear
```

En az bir kabul dönemi boyunca 403/409/audit kayıtları izlenir. Bu izleme bitmeden YF-11 legacy temizliği başlamaz.

## 5. Hızlı geri dönüş

Yetki kaynaklı kritik sorun görülürse ilk işlem migration rollback değildir:

```dotenv
COORDINATION_AUTHORIZATION_MODE=legacy
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

```bash
php artisan optimize:clear
```

Yeni koordinasyon tabloları, üyelikler ve pasif tarihsel kurallar inceleme için korunur. Veritabanı geri yükleme yalnız veri bütünlüğü bozulmuşsa ve ayrıca onaylandıysa kullanılır.

## 6. Canlıya geçmeden kullanıcıdan gereken bilgiler

1. Canlı dağıtım yöntemi: SSH, panel, Docker veya başka bir platform.
2. Staging ortamı bulunup bulunmadığı.
3. PostgreSQL yedeğinin platformda nasıl alındığı.
4. Gerçek coordinator/staff kullanıcılarının hangi birim ve pozisyona bağlanacağı.
5. İlk pilotta kullanılacak kullanıcı ID'leri.

Bu bilgiler olmadan canlıda kullanıcı veya veritabanı değişikliği yapılmaz.
