# Koordinasyon Yetkilendirmesi Cutover Runbook

> Varsayılan güvenli kip: `legacy`
> Bu runbook production veritabanında otomatik çalıştırılmamıştır.

## 1. Değişmez güvenlik kuralları

- Önce güncel PostgreSQL yedeği alınır.
- Migration ve backfill aynı bakım penceresinde olsa bile ayrı adımlar olarak doğrulanır.
- Dry-run çıktısı saklanmadan `--apply` çalıştırılmaz.
- Gerçek üyelikler girilmeden `enforce` açılmaz.
- Gerçek üyelikler yokken geliştirme sentetik test üyelikleriyle sürdürülür; bu kayıtlar production verisine yazılmaz.
- Shadow farkları kabul edilmeden geniş kullanıcı grubunda enforce açılmaz.
- Rollback için önce migration geri almak yerine kip `legacy` yapılır.

## 2. Önerilen sıra

### Geliştirme aşaması — gerçek isimler henüz yoksa

Bu durum normaldir ve migration/backfill tasarımını engellemez:

- proje ve hizmet koordinatörlükleri üyeler olmadan oluşturulabilir,
- proje sorumlulukları ve permission şablonları üyeler olmadan hazırlanabilir,
- otomatik testler her rol için transaction içinde sentetik kullanıcı/üyelik üretir ve test sonunda siler,
- production/staging tablosuna sahte kullanıcı veya tahmini `staff_profiles.unit` eşlemesi yazılmaz; aşağıdaki demo seeder yalnız local/testing provası içindir,
- `shadow` teknik olarak hazırlanabilir; `pilot` ve `enforce` gerçek kullanıcı seçilene kadar açılmaz.

Salt okunur hazırlık kontrolü:

```bash
php artisan coordination-units:cutover-readiness --target=shadow --format=json --strict
```

Rapor `technical_ready=true`, `membership_data_ready=false` dönebilir. Bu, kod/şema hazırlığının tamam; canlı üyelik girişinin beklediği anlamına gelir ve geliştirme için hata değildir.

### Faz 11B-R — Local/testing demo kabul provası

Gerçek isimler gelmeden bütün organizasyon matrisini uçtan uca denemek için:

```bash
php artisan db:seed --class=DemoProjectRoleDataSeeder
php artisan coordination-units:cutover-readiness --target=enforce --format=json --strict
```

Seeder:

- aktif projelerden proje birimlerini ve üç hizmet birimini idempotent oluşturur,
- dört hizmet alanı için bütün aktif projelerde sorumlulukları ve permission şablonlarını kurar,
- her proje birimine bir demo coordinator/staff,
- Medya, Satın Alma ve Organizasyon, Topluluk ve Kültür birimlerinin her birine bir demo coordinator/staff ekler,
- proje kullanıcılarının legacy coordinator/staff pivotlarını da korur,
- bütün demo authority kullanıcılarına tam bir aktif ana üyelik verir,
- hesap sayısını artırmadan Medya coordinator hesabına Satın Alma ve Organizasyon staff; ilk proje staff hesabına Topluluk ve Kültür staff ikincil üyeliği vererek iki context-switch provası sağlar,
- demo hesapların eksik KVKK kabul zamanını doldurur; mevcut kabul zamanını ezmez,
- her aktif proje için bir çekirdek program ve Topluluk birimine ait bir ortak etkinlik oluşturur; yerel yoklama kabulini iki akışta da sınanabilir kılar,
- ikinci çalıştırmada kullanıcı, birim, üyelik, sorumluluk, permission rule veya program çoğaltmaz,
- staging/production dahil local/testing dışındaki ortamlarda açık hata ile durur.

Demo hesap kalıpları:

- proje: `demo.coordinator.pNN@kademe.org`, `demo.staff.pNN@kademe.org`,
- hizmet: `demo.coordinator.media@kademe.org`, `demo.coordinator.purchase.organization@kademe.org`, `demo.coordinator.community.culture@kademe.org` ve karşılık gelen `demo.staff.*` hesapları,
- yalnız local/testing için ortak parola: `Demo1234!`.

Bu prova gerçek kullanıcı kabulü değildir. Seeder production/staging üzerinde çalıştırılmaz; gerçek isimler geldiğinde demo e-postaları gerçek hesaplara dönüştürülmez veya kopyalanmaz. Canlı kullanıcılar Adım 6'daki yönetim ekranından ayrı üyelik olarak girilir. Demo readiness başarılı olsa bile uygulama kipi kendiliğinden `pilot` veya `enforce` olmaz.

YF-9 otomatik kabul paketi:

```bash
php artisan test tests/Feature/Yf9LocalAcceptanceMatrixTest.php
```

Bu paket 18 hesabın sidebar, action, proje/program kapsamı, doğrudan 403, export/download, dashboard, profil/izin, talep/destek ve iki çoklu üyelik geçişini sınar. Güncel yerel kabul sonucu `docs/YF9_LOCAL_ACCEPTANCE_REPORT.md` belgesindedir.

### YF-10A — Yerel frontend/backend/PostgreSQL entegrasyon provası

Dağıtımdan önce uygulamanın üç katmanı birlikte çalıştırılır:

```bash
php artisan migrate --force
php artisan db:seed --class=DemoProjectRoleDataSeeder
php artisan coordination-units:cutover-readiness --target=enforce --strict
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api` ile başlatılır. En az şu iki hesapta birim değişimi tarayıcıdan doğrulanır:

- `demo.coordinator.media@kademe.org`: Medya coordinator → Satın Alma ve Organizasyon staff,
- `demo.staff.p01@kademe.org`: Diplomasi360 staff → Topluluk ve Kültür staff.

Birim değişiminde eski sidebar'ın bir an görünmesi kabul edilmez. Yeni manifest gelene kadar yetkili menü boş/güvenli yükleme halinde kalmalı; gösterilen pozisyon aktif üyelikten gelmeli; açık route yeni bağlamda yasaksa güvenli ana sayfaya yönlenmelidir.

`PermissionResolver` aynı istek içinde kullanıcı + aktif üyelik + authorization kipi için tek snapshot kullanır. Middleware her HTTP isteğinin başında bu request cache'ini temizler; bu satır Octane, queue worker veya PHPUnit gibi uzun ömürlü süreçlerde kaldırılmamalıdır. Performans optimizasyonu sonrasında da 18 hesaplı kabul matrisi yeniden çalıştırılmalıdır.

Yerel entegrasyon sonucu ve güncel ölçümler `docs/YF10_LOCAL_INTEGRATION_REPORT.md` belgesindedir. Bu prova staging/production pilotu değildir ve gerçek kullanıcı verisi yerine geçmez.

23 Eylül 2026 işveren kararıyla Topluluk staff şablonundan `programs.community_event.create` ve `programs.community_event.update` çıkarılmıştır. `2026_09_23_000001_retire_community_staff_event_write_permissions.php` migration'ı mevcut staff kural satırlarını silmeden pasifleştirir; coordinator yazma kuralları ile staff görüntüleme/yoklama/lojistik kuralları korunur. Güncel yerelden canlıya kapı sırası `docs/YF10B_DEPLOYMENT_GATE.md` belgesindedir.

### Adım 1 — Migration

```bash
php artisan migrate --force
```

Bu adım yalnız additive tabloları ve nullable FK'leri ekler. Mevcut alanları silmez.

### Adım 2 — Birim dry-run

```bash
php artisan coordination-units:backfill --format=json --strict
```

Kontrol edilecek alanlar:

- `blocker_count = 0`
- aktif proje sayısı beklenen sayı
- gerçek üyelik değişikliği `0`
- üç hizmet birimi
- her aktif proje için bir proje birimi

### Adım 3 — Birim apply

```bash
php artisan coordination-units:backfill --apply --format=json --strict
```

Komut uygulama sonrasında ikinci planı çalıştırır. `verification.idempotent = true` ve `remaining_change_count = 0` olmalıdır.

### Adım 4 — Permission şablonu dry-run

```bash
php artisan coordination-units:sync-permissions --format=json --strict
```

Varsayılan politika `bootstrap-preserve-admin-decisions` olmalıdır:

- hiç kural almamış birimlerin varsayılanları planlanır,
- mevcut birimde eksik/pasif/scope'u değiştirilmiş ortak çekirdek default admin kararı kabul edilip korunur,
- YF-2 ile ilk kez uygulanabilir olan family action'ı ve YF-3 ile ilk kez gelen Gelen Kutusu/Kariyer default'u için hiçbir tarihsel rule yoksa eklenir; pasif veya soft-delete geçmişi varsa geri açılmaz,
- proje metadata'sına uymayan eski family kuralları silinmeden pasifleştirilmek üzere planlanır,
- finans, duyuru/içerik ve gönüllülük/motivasyon action'ı yanlış hizmet sahibinde veya proje biriminde aktifse satır silinmeden `exclusive_service_domain_not_owned` nedeniyle pasifleştirilmek üzere planlanır,
- Topluluk birimindeki eski `responsibility_projects` motivasyon scope'u global içerik sahipliğine uygun `all` scope'a taşınmak üzere update listesinde görünür,
- `preserved_customization_count` readiness blocker değildir,
- `create_count + update_count + deactivate_count = proposed_change_count` olmalıdır.

### Adım 5 — Permission şablonu apply

```bash
php artisan coordination-units:sync-permissions --apply --format=json --strict
```

`verification.healthy` ve `verification.idempotent` true olmalıdır.

Bu normal apply adminin pasifleştirdiği default kuralı geri açmaz. Proje içerik ekranından `special_modules` değiştirildiğinde yalnız ilgili proje biriminin family izinleri otomatik uzlaştırılır.

### Adım 5-Reset — Yalnız bilinçli varsayılana dönüş

Admin özelleştirmelerini bilerek silip bütün aktif birimleri güncel kod şablonuna döndürmek gerekiyorsa önce dry-run alınır:

```bash
php artisan coordination-units:sync-permissions --reset-defaults --format=json --strict
```

İşletme sahibi `update_count`, `create_count` ve `deactivate_count` listesini onayladıktan sonra:

```bash
php artisan coordination-units:sync-permissions --apply --reset-defaults --format=json --strict
```

`--reset-defaults` rutin deploy/seeder adımı değildir. Normal demo seeder ve normal sync bu bayrağı kullanmaz.

### Adım 5A — Eski mali kayıtlar için işleyen birim dry-run

```bash
php artisan financials:backfill-processing-units --format=json
```

Kontrol edilecek alanlar:

- `proposed_change_count`, işleyen birimi boş ve aktif `finance_procurement` sorumluluğuyla eşleşen kayıt sayısıdır.
- `skipped` içindeki projesiz veya sorumluluğu eksik satırlar işletme sahibiyle incelenir.
- Komut mevcut `processing_unit_id` değerlerini değiştirmez.

### Adım 5B — Onay sonrası mali kayıt apply

```bash
php artisan financials:backfill-processing-units --apply --format=json
```

`verification.idempotent = true` ve `remaining_change_count = 0` olmalıdır. `remaining_skipped_count` sıfır değilse bu kayıtlar legacy proje-scope fallback'inde kalır; otomatik olarak yanlış birime atanmaz.

### Adım 6 — Üyelik veri girişi

Gerçek kullanıcılar yönetim ekranı/API tamamlandıktan sonra birimlere atanır. Geçici SQL veya `staff_profiles.unit` tahminiyle üyelik üretilmez.

Gerçek üyelik, yeni bir kullanıcı türü değildir. Sistemdeki mevcut `coordinator` veya `staff` hesabına aşağıdaki kayıt eklenir:

- `unit_id`: bağlı olduğu proje veya hizmet koordinatörlüğü,
- `position`: `coordinator` ya da `staff`,
- `is_primary`: normal durumda tek üyelik için `true`,
- opsiyonel başlangıç/bitiş tarihi.

İsim listesi geldiğinde `/panel/coordination-units` ekranında ilgili birim açılır, mevcut kullanıcı seçilir ve pozisyon atanır. Bir kullanıcı ileride birden fazla birime eklenebilir; yalnız bir aktif üyelik primary kalır. Kullanıcı hesabı yoksa önce normal kullanıcı/personel ekranından oluşturulur, sonra birime bağlanır.

Veri girişinden sonra tam enforce kontrolü:

```bash
php artisan coordination-units:cutover-readiness --target=enforce --format=json --strict
```

`users_without_active_membership`, `users_without_single_primary_membership` ve `evaluated_units_without_coordinator` boş olmadan tam enforce açılmaz.

### Adım 7 — Legacy doğrulama

```dotenv
COORDINATION_AUTHORIZATION_MODE=legacy
```

Migration, birim ve permission kayıtları var olsa bile mevcut resolver sonucu yetkili kalır.

### Adım 8 — Shadow

```dotenv
COORDINATION_AUTHORIZATION_MODE=shadow
COORDINATION_AUTHORIZATION_SHADOW_LOG=true
```

Shadow kipinde API sonucu legacy'dir. Birim sonucu yalnız hesaplanır ve `coordination_authorization.shadow_diff` olayıyla karşılaştırılır.

İncelenecek farklar:

- `legacy_only_permissions`: yeni modelde bilinçli kaldırılan veya yanlış eksik kalan action'lar,
- `unit_only_permissions`: hizmet biriminin yeni aldığı action'lar,
- `scope_differences`: aynı action'ın proje/birim kapsam farkı.

### Adım 9 — Gerçek kullanıcıyla pilot

Global enforce yerine önce yalnız açıkça seçilmiş kullanıcılar yeni resolver'a geçirilir:

```dotenv
COORDINATION_AUTHORIZATION_MODE=pilot
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=123,456
```

Pilot readiness kontrolü aynı kullanıcılarla yapılır:

```bash
php artisan coordination-units:cutover-readiness --target=pilot --user=123 --user=456 --format=json --strict
```

Yalnız allowlist içindeki coordinator/staff yeni birim modelinden yetki alır. Diğer çalışanlar legacy'de kalır. Liste boşsa kimse pilot olmaz. Super admin ile öğrenci/mezun mevcut legacy/self davranışını korur.

### Adım 10 — Tam enforce

Pilot kabulünden ve tam readiness raporundan sonra:

```dotenv
COORDINATION_AUTHORIZATION_MODE=enforce
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

Authority kullanıcının aktif üyeliği yoksa enforce güvenli biçimde sıfır birim yetkisi üretir; bu nedenle readiness `requested_target_ready=true` olmadan açılmaz.

### Adım 10A — Aktif birim güvenlik bağlamı

Authority istemcileri bütün korumalı API çağrılarında seçili üyeliğin birim kimliğini gönderir:

```http
X-Coordination-Unit-Id: 42
```

Backend yalnız kullanıcının aktif ve süresi geçerli üyeliğini kabul eder. Header yoksa tek üyelik doğrudan; çoklu üyelikte ana üyelik kontrollü fallback olarak seçilir. Daha katı cutover için:

```dotenv
COORDINATION_ACTIVE_UNIT_FALLBACK=none
```

Bu ayarda çoklu üyelikte header yoksa API `409 coordination_unit_context_required`, geçersiz veya başka kullanıcıya ait birim seçilirse `403 invalid_coordination_unit_context` döndürür. Normal geçiş varsayımı `primary` değeridir.

Panel manifesti, `/auth/me` ve login cevabı kullanılan `active_unit_id` değerini taşır. Audit kayıtlarında `acting_unit_id` ve `acting_membership_id` bulunur. Eski bütün-üyelik birleşimi yalnız Koordinasyon Birimleri authorization preview/audit çıktısında tutulur; normal endpoint yetkilendirmesinde kullanılmaz.

Eski global coordinator/staff birim-işi override dry-run raporu:

```bash
php artisan coordination-units:audit-global-overrides --json
```

Komut hiçbir kaydı değiştirmez. Global birim-işi `allow` enforce modunda etkisiz legacy kayıt olarak raporlanır ve Yetki Matrisi ekranından hedef aktif üyeliğe yeniden tanımlanır. Yeni birim-işi allow/deny override'ları `coordination_unit_membership_permission_overrides` tablosunda tek üyeliğe bağlıdır. Eski global `deny` güvenli daraltma olarak etkisini korur.

### Adım 10B — YF-7 kayıt politikası ve alias kontrolü

Tam enforce/pilot öncesinde yalnız sidebar sonucuna bakılmaz. Aynı işin admin/panel/coordinator/staff uyumluluk yolları aynı aktif birim header'ı ile denenir ve liste, detay, export/indirme ve mutation sonucu karşılaştırılır.

Normalize edilmiş kayıtlarda aşağıdaki alanlar tek kayıt-scope kaynağıdır:

- talepte `target_unit_id` / `target_membership_id`,
- destekte `assigned_unit_id`,
- izinde `unit_id` / `membership_id`,
- mali işlemde `processing_unit_id`.

Bu alan doluyken eski metin, proje pivotu veya doğrudan atanmış kişi alternatif erişim kapısı olarak kullanılamaz. Legacy fallback yalnız ilgili normalize FK boşsa devreye girer. Ayrıntılı envanter ve test matrisi `docs/YF7_RECORD_POLICY_AUDIT.md` belgesindedir.

Pilot loglarında `authorization_signal=denied_without_recorded_permission_check` ve `authorization_signal=no_controller_permission_check_recorded` olayları incelenir. Açıklanamayan olay veya seçilmeyen birimde beklenmeyen 200, tam enforce kapısını kapatır.

### Adım 10C — YF-8 veri geçişi ve invariant kontrolü

Permission senkronu önce dry-run olarak incelenir. Varsayılan çalışma admin özelleştirmelerini korur; `--reset-defaults` yalnız açık bir operasyon kararıyla kullanılır:

```bash
php artisan coordination-units:sync-permissions --strict
php artisan coordination-units:sync-permissions --apply --strict
php artisan coordination-units:sync-permissions --apply --strict
php artisan coordination-units:cutover-readiness --target=enforce --strict
```

İkinci apply sıfır değişiklik vermelidir. Yanlış sahipli exclusive veya uygulanamaz proje-family kuralı silinmez; pasifleştirilir. Apply öncesi JSON raporundaki `rollback.deactivated_rule_ids` saklanır.

Readiness şablon eşitliğine değil güvenlik invariant'larına dayanır. Böylece güvenli admin scope/action kararları korunurken yanlış hizmet sahibi, geçersiz scope, eksik proje-hizmet sorumluluğu veya pasif üst kayda bağlı aktif satır enforce'u durdurur. YF-8 yerel sonuçları ve sayı mutabakatı `docs/YF8_DATA_MIGRATION_REPORT.md` belgesindedir.

## 3. Rollback

İlk rollback işlemi:

```dotenv
COORDINATION_AUTHORIZATION_MODE=legacy
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

Ardından uygulama config cache'i kontrollü biçimde yenilenir. Birim tabloları, üyelikler ve permission kuralları silinmez; inceleme ve yeniden shadow için korunur.

Migration rollback ancak yeni alanlara production yazımı başlamadıysa ve ayrıca onaylandıysa düşünülmelidir.

## 4. Zorunlu pilot kontrolleri

1. Proje koordinatörü yalnız bağlı projenin çekirdek action'larını alır.
2. Medya kullanıcısı yalnız medya action'larında sorumlu projeleri görür.
3. Satın Alma kullanıcısı finans action'larında sorumlu projeleri görür; katılımcı/başvuru action'larını alamaz.
4. Topluluk ve Kültür finans action'larını alamaz.
5. Personel permission seti koordinatör setinin güvenli alt kümesidir.
6. Çoklu üyelikte yalnız header ile seçilen aktif üyeliğin action ve proje scope'u kullanılır; diğer üyelikten permission sızmaz.
7. Üyelik-scoped deny override yalnız hedef üyeliğin allow sonucunu bastırır; eski global deny bütün üyelikleri güvenli biçimde daraltmaya devam eder.
8. Pasif veya süresi bitmiş üyelik permission üretmez.
9. Scope `none` sidebar modülü açmaz.
10. Sorunda yalnız env kipi değiştirilerek legacy sonuca dönülebilir.
11. Frontend seçili birim ile backend `active_unit_id` aynı değilse manifest kullanılmaz.
