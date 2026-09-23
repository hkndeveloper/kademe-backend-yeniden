# Birim Yetkilendirmesi Faz 1 — Mevcut Davranış ve Endpoint Envanteri

> Tarih: 27 Ağustos 2026
> Amaç: Birim V2 dönüşümünden önce mevcut davranışı dondurmak ve her alanın hedef guard türünü belirlemek.
> Bu belge yetki vermek için kullanılmaz; Faz 2–4 uygulamasına girdi sağlar.

## 1. Sayısal başlangıç görünümü

| Ölçüm | Sonuç |
|---|---:|
| Toplam Laravel route | 559 |
| API route | 550 |
| Birleşik `/api/panel/*` route | 255 |
| Legacy `/api/admin/*` route | 181 |
| Legacy `/api/staff/*` route | 9 |
| Legacy `/api/coordinator/*` route | 10 |
| `AuthorizesGranularPermissions` kullanan API controller | 29 |
| Yalnız action kontrolü yapan `abortUnlessAllowed` çağrısı | 136 |
| Trait üzerinden proje guard çağrısı | 50 |
| Trait üzerinden birim guard çağrısı | 5 |
| Controller/policy/service doğrudan proje scope kontrolü | 57 |
| Controller/policy/service doğrudan permission kontrolü | 39 |

Sayılar statik kod taramasıdır. Bir action-only kontrolün güvensiz olduğu anlamına tek başına gelmez; aynı metodun devamında query scope veya record policy bulunabilir. Her iş alanı enforce öncesinde kayıt seviyesinde doğrulanmalıdır.

## 2. Mevcut merkezi bileşenler

| Bileşen | Bugünkü görev | V2'deki yön |
|---|---|---|
| `PermissionResolver` | Rol, legacy alias, user override ve scope birleştirir | Birim üyeliği ve sorumluluk projelerini shadow olarak ekleyecek |
| `PanelModuleCatalog` | Action + kullanılabilir scope ile sidebar manifesti üretir | Aynı kalacak; V2 sonucu beslenecek |
| `AuthorizesGranularPermissions` | Action, proje ve string birim guard'ları | `hasUsablePermission`, unit ID ve record guard ayrımına taşınacak |
| `role_permission_scopes` | Rol/action için tek scope + JSON payload | Legacy/rollback kaynağı olarak korunacak |
| `user_permission_overrides` | Kullanıcı allow/deny istisnası | Explicit deny en son uygulanmaya devam edecek |
| `project_coordinators` | Koordinatörün projeleri | Proje birimi backfill kaynağı ve geçici dual-write |
| `project_staff_assignments` | Personelin projeleri | Proje birimi backfill kaynağı ve geçici dual-write |
| `staff_profiles.unit` | Serbest metin birim | Geçici uyumluluk; yetki kaynağı olmaktan çıkarılacak |

## 3. Karakterizasyonla sabitlenen legacy davranışlar

`tests/Feature/UnitAuthorizationCharacterizationTest.php` aşağıdaki mevcut davranışları kayda alır:

1. `selected_projects` kapsamı action bazında ayrışabilir; finans projesi katılımcı action'ına sızmaz.
2. Permission mevcut olsa bile scope `none` ise proje/global erişimi ve proje listesi oluşmaz.
3. `calendar.view` coordinator/staff için şu anda organizasyon genelinde `all` kapsamına yükselir.
4. `staff_profiles.unit` içinde medya işaretçisi bulunan staff kullanıcısına tüm aktif projeler genel manageable liste olarak verilir.

Son iki davranış hedef mimari değildir. Shadow resolver devreye girdiğinde bunların yerini action bazlı `responsibility_projects` alacaktır. Testler o fazda yeni beklenen davranışa kontrollü olarak güncellenecektir.

## 4. İş alanı ve hedef guard matrisi

| Alan | Ana controller/service | Sahiplik | Hedef V2 guard | Birincil birim |
|---|---|---|---|---|
| Proje temel bilgi | `ProjectContentController` | proje | action + project | Proje koordinatörlüğü |
| Proje halka açık içerik | `ProjectContentController` | proje | ayrı public-content action + project | Medya |
| Proje galerisi | `ProjectContentController` | proje | gallery action + project | Medya |
| Dönem | `PeriodController`, period servisleri | proje + dönem | action + project + lifecycle | Proje koordinatörlüğü |
| Program | `AdminProgramController` | proje + dönem | action + project + lifecycle | Proje koordinatörlüğü |
| Program medyası | `AdminProgramController` | proje/program | `programs.media.upload` + project | Medya |
| Yoklama | `AdminProgramController` | proje/program | attendance action + project + assignment | Proje koordinatörlüğü |
| Takvim | `CalendarController` | proje/dönem | action + responsibility project | Proje / Topluluk / Organizasyon |
| Başvurular | `AdminApplicationController`, `ApplicationIntakeController` | proje + dönem | action + project + lifecycle | Proje koordinatörlüğü |
| Katılımcılar | `CoordinatorParticipantController` | proje | action + project | Proje koordinatörlüğü |
| Projeye özel modüller | `ProjectSpecialModuleController`, `AdminKpdController` | proje tipi | action + project family | Proje koordinatörlüğü |
| Görev/atama | `AssignmentController` | proje/dönem | action + project + assignee | Proje koordinatörlüğü |
| Sertifika | `AdminCertificateController` | proje/dönem | action + project | Proje koordinatörlüğü |
| Finans | `FinancialTransactionController` | proje + oluşturan/onaylayan | action + responsibility project + record relation | Satın Alma ve Organizasyon |
| Talepler | `RequestController` | proje + requester + hedef | action + project + target unit/user | Hedef hizmet birimi |
| Destek | `SupportTicketController` | nullable proje + sahip + atanan | action + project + assigned unit/user | Atanan hizmet birimi |
| İzin | `StaffController` | kullanıcı + birim snapshot | action + own unit + approval relation | Kullanıcının birimi |
| Duyuru | `AnnouncementController` | nullable proje + hedefler | action + project/unit audience | Medya / yetkili proje birimi |
| Blog/içerik | `ContentManagementController` | nullable proje | action + project veya explicit global | Medya |
| FAQ/site ayarı | `ContentManagementController` | global | explicit `all` | Super admin / işveren kararı |
| Gönüllülük | `VolunteerController` | proje | action + responsibility project | Topluluk ve Kültür |
| Motivasyon/topluluk | `MotivationController`, `ForumController` | proje/global ayrımı | action + project/explicit global | Topluluk ve Kültür |
| Personel/kullanıcı | `StaffController`, `UserController` | birim/kullanıcı | action + unit + record | Birim koordinatörü / super admin |
| Yetki matrisi | `PermissionMatrixController` | global | explicit `all` | Super admin |
| Loglar/ayarlar | dashboard/settings controller'ları | global | explicit `all` | Super admin |

## 5. Enforce öncesi yüksek riskli kontrol noktaları

### R1 — Action-only guard ile scope ayrımı

`abortUnlessAllowed` sadece permission adını kontrol eder. Controller içinde ardından proje/query filtresi uygulanmayan bir endpoint scope `none` veya yanlış kapsamla veri açabilir. Faz 4 öncesinde bu yardımcı `hasUsablePermission` sözleşmesine alınmalı; global işlemler explicit `all` istemelidir.

### R2 — Null proje ile global varsayımı

`abortUnlessAllowedForProject` proje verilmediğinde bugün yalnız permission varlığına bakar. Null proje, global scope anlamına gelmemelidir. Liste/export endpoint'leri query scope kullanmalı; gerçek global işlem `hasGlobalScope` istemelidir.

### R3 — Medya birimi string istisnası

Medya işaretçisi tüm aktif projeleri genel context'e ekler. V2'de bu istisna enforce modunda kapatılmalı; yalnız medya action'ları `responsibility_projects(media)` üzerinden proje listesi üretmelidir.

### R4 — Takvim global görünümü

`calendar.view` coordinator/staff için `all` olmaktadır. Çakışma kontrolü ihtiyacı, bütün takvim detaylarını görme yetkisiyle aynı değildir. İleride uygun veri minimizasyonu veya ayrı `calendar.conflicts.view` action'ı değerlendirilmelidir.

### R5 — Serbest metin hedef birim

Talep ve destek erişimi `staff_profiles.unit`, hedef string ve alias eşleşmesine dayanır. Normalize unit ID eklenmeden benzer isimli birimlerde yanlış eşleşme mümkündür.

### R6 — Proje içeriği action'ının genişliği

`projects.content.update` halka açık içerikle yapısal proje alanlarını aynı işlemde kapsayabilir. Medya sorumluluğu verilmeden önce action ve validation alanları ayrılmalıdır.

### R7 — Kayıt sahipliği ile liste yetkisinin birleşmesi

Proje biriminin kendi oluşturduğu finans/talep kaydını görmesi ile hizmet biriminin tüm iş kuyruğunu görmesi farklı record relation gerektirir. Tek `view` action'ı geniş listeye dönüşmemelidir.

## 6. Korunacak dış sözleşmeler

- `/api/panel/*` birleşik panel route'ları ana sözleşmedir.
- Legacy `/api/admin`, `/api/staff`, `/api/coordinator` alias'ları geçiş boyunca kaldırılmaz.
- Frontend'in permission parametreli manageable-project çağrıları korunur.
- Sidebar modül kimlikleri ve mevcut sayfa URL'leri mümkün olduğunca değişmez.
- Permission adları bölünürse eski adlar geçici alias/adapter olarak çalışır.
- Öğrenci ve mezun `self` kapsamları birim dönüşümünden etkilenmez.
- Period lifecycle guard'ları birim scope sonucundan sonra ayrıca uygulanmaya devam eder.

## 7. Faz 1 çıkış ölçütleri

- [x] Ana route ve guard sayıları kaydedildi.
- [x] Modül → sahiplik → hedef guard matrisi çıkarıldı.
- [x] Action bazlı proje scope ayrışması karakterizasyon testiyle doğrulandı.
- [x] Legacy medya ve takvim genişletmeleri testle kayıt altına alındı.
- [x] Mevcut permission/scope/panel testleri yeşil.
- [x] Yeni karakterizasyon testiyle birlikte ilgili backend regresyon paketi çalıştırıldı: 42 test, 143 assertion geçti; PostgreSQL'e özel 1 mevcut test SQLite'da atlandı.
- [x] Ana V2 planındaki ilerleme tablosu Faz 1 tamamlandı olarak güncellendi.
