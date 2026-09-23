# YF-7 Endpoint, Kayıt Politikası ve Legacy Yol Denetimi

> Tarih: 2 Eylül 2026
> Kapsam: authority panel endpoint'leri, aktif koordinasyon birimi, kayıt kapsamı, route alias'ları ve geriye dönük uyumluluk
> İlke: Eski route veya veri silinmez; aynı controller/policy adapter'ına bağlanır. Normalize edilmiş yeni kayıtta legacy alan yetki kaynağı olamaz.

## 1. Son güvenlik modeli

Bir authority isteğinde karar sırası şöyledir:

1. `X-Coordination-Unit-Id`, oturum kullanıcısının aktif üyeliği olarak doğrulanır.
2. `PermissionResolver`, yalnız bu üyeliğin pozisyonunu, aktif permission rule'larını, proje sorumluluklarını ve üyelik-bazlı override'larını çözer.
3. Endpoint action izni kontrol edilir.
4. Liste/export sorgusu aynı permission'ın proje, birim, kullanıcı veya kendi-kayıt kapsamıyla daraltılır.
5. Detay, indirme ve mutation işlemi aynı kayıt politikasıyla tekrar kontrol edilir.
6. 403 ve başarılı değişiklik audit kaydı aktif birim ve üyelik kimliğiyle yazılır.

Rol adı (`coordinator`/`staff`) bir modülü veya kaydı tek başına açmaz. Rol yalnız kullanıcının sistem türünü ve birim üyeliğindeki izin şablonunun hangi pozisyon kolundan başlayacağını belirtir.

## 2. Normalize kayıt ile legacy kayıt ayrımı

| Alan | Normalize kayıt | Legacy kayıt fallback'i |
|---|---|---|
| Talep | `target_unit_id` ve mümkünse `target_membership_id` | Yalnız `target_unit_id IS NULL` ise eski `target_unit` metni/atanan kişi |
| Destek | `assigned_unit_id` | Yalnız `assigned_unit_id IS NULL` ise eski proje/atanan kişi yolu |
| İzin | `unit_id`, `membership_id`, `position_snapshot` | Yalnız yeni snapshot yoksa `staff_profiles.unit` |
| Mali işlem | `processing_unit_id` | Yalnız alan boşsa mevcut güvenli proje fallback'i |
| Duyuru hedefi | Alıcının aktif koordinasyon üyelikleri | Kullanıcının hiç aktif üyeliği yoksa `staff_profiles.unit` |
| Personel kapsamı | Aktif koordinasyon üyeliği ve seçili birim | Hedef kullanıcının hiç aktif üyeliği yoksa `staff_profiles.unit` |

Temel kural: Yeni FK/snapshot doluysa eski metin veya pivot ikinci bir erişim kapısı oluşturmaz. Legacy fallback yalnız gerçekten normalize edilmemiş satırda çalışır.

## 3. Endpoint aileleri ve alias kararı

`/api/admin` ve `/api/panel` grupları artık route başındaki sabit coordinator/staff/super-admin rol filtresine değil aynı controller permission ve record policy zincirine dayanır. Aynı işleme giden alias'larda aktif birim header'ı, permission scope'u ve kayıt kontrolü aynıdır.

| İş ailesi | Alias/uyumluluk yolları | Ortak karar |
|---|---|---|
| Talepler | `/api/admin/requests`, `/api/panel/requests` | `requests.*` + seçili hedef/aktif birim kayıt politikası |
| Destek | `/api/admin/support/*`, `/api/panel/support/*` | `support.*` + `assigned_unit_id` kayıt politikası |
| Personel | `/api/admin/staff/*`, `/api/panel/staff/*`, dar `/api/staff/*` | `staff.*` + aktif birim kullanıcı scope'u |
| İzin inceleme | `/api/panel/leave-requests/*`; self-service kök yollar korunur | `staff.leave.*` + izin snapshot birimi |
| Katılımcı | `/api/panel/participants/*`, `/api/coordinator/participants/*` | `projects.participants.*` + proje scope'u |
| Mali işlem | `/api/admin/financials/*`, `/api/panel/financials/*`, legacy `/api/coordinator/financials/*` | `financial.*` + işleyen birim/proje kayıt politikası |
| Dönem/program/takvim | admin/panel ve korunmuş ortak yollar | İlgili granular action + resolver proje scope'u |

Legacy `/coordinator` ve `/staff` yolları henüz kaldırılmadı. Bunlar yeni bir yetki modeli değildir; eski istemcilerin aynı controller kararına ulaşmasını sağlayan uyumluluk alias'larıdır.

## 4. Kapatılan bypass sınıfları

- Çoklu üyelikli kullanıcının seçilmeyen diğer üyeliğini talep veya destek kaydı için kullanması engellendi.
- Talep hedefi ve hedef kullanıcı listesi seçili/izinli koordinasyon birimine bağlandı.
- Talep durumunu normalize kayıtta yalnız hedef kişi; destek kaydını yalnız atanmış birimin uygun coordinator'ı yönetir.
- Personel listesi, detay, belge ve izin inceleme kapsamı eski `staff_profiles.unit` metni yerine aktif üyelik üzerinden çözülür.
- `ProjectPolicy`, `ParticipantPolicy`, `ApplicationPolicy` ve `CertificatePolicy` eski proje pivotu yerine `PermissionResolver` proje scope'unu kullanır.
- Takvimde actor yetkisi için doğrudan rol/proje pivotu kaldırıldı; proje listesi ve kayıt erişimi resolver sonucundan gelir.
- Feedback form template proje görünürlüğü resolver tabanlıdır.
- Projesiz (`project_id = null`) bir kayıt, sadece action izni var diye global sayılmaz. Global işlem açıkça `scope_type=all` gerektirir.
- Duyuru alıcısında aktif üyeliği bulunan kullanıcı eski profil metni nedeniyle yanlış hizmet biriminin alıcısı olamaz.
- `/api/admin` grup başındaki rol filtresi kaldırıldı; custom authority rolü de permission varsa admin/panel alias'larında aynı sonucu alır.

## 5. Kalan legacy pivot ve alan envanteri

Bu kullanımlar YF-7 sonunda bilinçli olarak korunmuştur; normal enforce kayıt yetkilendirmesinde bağımsız kapı değildir:

| Kullanım | Sınıf | Neden korunuyor |
|---|---|---|
| `project_coordinators`, `project_staff_assignments` yazımı | Global admin dual-write/uyumluluk | Eski yönetim ekranı ve rollback; yalnız `staff.update` global scope ile değiştirilebilir |
| Personel proje özeti ve proje filtresi | Görüntüleme/filtre metadata'sı | Önce aktif birim kullanıcı scope'u uygulanır; pivot sonucu kapsamı genişletmez |
| `staff_profiles.unit` | Legacy profil, dışa aktarım ve fallback | Aktif üyelik varsa yetki kararına katılmaz; normalize olmayan kullanıcıyı geçişte kaybetmemek için tutulur |
| Calendar adayın katılımcı/proje ilişkisi | İş akışı hedef uygunluğu | Actor yetkisi değildir; adayın ilgili projeye atanabilir olup olmadığını daraltır |
| Request/Support hedef üyelik sorguları | İş akışı yönlendirme | Hedef kullanıcının seçilen birime gerçekten aktif üye olduğunu doğrular |
| Auth rol senkronizasyonu | Kimlik uyumluluğu | Spatie rol kaydı ile mevcut `users.role` alanını tutarlı tutar; kayıt scope'u üretmez |
| Participant/student/alumni rol kontrolleri | Katılımcı domain durumu | Authority birim yetkisi değil, öğrenci/mezun iş akışı ayrımıdır |

YF-10 pilotu tamamlanmadan bu alanlar veya pivot tablolar silinmeyecektir. YF-11'de her kullanım yeniden ölçülüp yalnız artık okunmayan yapılar pasifleştirme/temizleme planına alınacaktır.

## 6. Audit ve telemetry

`AuditAdminActions` hem başarılı controller sonucunu hem controller içinde oluşan 403/diğer HTTP hatalarını kaydeder. Kayıtta en az şu sinyaller bulunur:

- `acting_unit_id`
- `acting_membership_id`
- route adı/URI ve HTTP status
- `authorization_signal`
- exception sınıfı (hata halinde)

`authorization_signal` değerleri:

- `checked`: controller permission kontrolü yapıldı ve işlem tamamlandı.
- `checked_and_denied`: permission/record kontrolü yapıldı ve reddedildi.
- `denied_without_recorded_permission_check`: controller seviyesinde kaydedilmiş action kontrolü olmadan red oluştu; inceleme sinyalidir.
- `no_controller_permission_check_recorded`: başarılı istekte controller kontrol sinyali yoktur; middleware/self-service gibi açıklanabilir yollar dışında inceleme gerekir.

Bu telemetry otomatik izin vermez veya engellemez; beklenmeyen 200/403 olayını pilotta bulunabilir kılar.

## 7. Test kapsamı

YF-7 hedef testi aşağıdaki negatif/pozitif çiftleri kapsar:

- Talep: admin/panel liste ve status mutation alias eşitliği; seçilmeyen üyelikte görünmeme/403.
- Destek: admin/panel liste, update ve reopen; seçilmeyen atanmış birimde görünmeme/403.
- Personel/izin: çelişkili eski profil metnine rağmen aktif üyelikle liste, detay ve approve kararı.
- Eski policy'ler: legacy proje coordinator pivotu bulunsa bile seçili başka proje kaydına erişememe.
- Duyuru hedefi: aktif üyeliğin legacy profil metnine üstün gelmesi; üyeliği olmayan eski kullanıcıda kontrollü fallback.
- Audit: reddedilen kayıtta seçili birim/üyelik ve `checked_and_denied` sinyali.

Geniş regresyonda aktif birim context, iş akışı yönlendirme, mali işleyen birim, hizmet modülü ayrımı, paylaşılan modül kipleri, dönem context'i, dosya indirme ve büyük panel regresyon paketi birlikte çalıştırılır.

## 8. Rollback

Kod ve veri yapıları additive bırakılmıştır. Sorunda ilk geri dönüş:

```dotenv
COORDINATION_AUTHORIZATION_MODE=legacy
COORDINATION_AUTHORIZATION_PILOT_USER_IDS=
```

Route alias'ları, eski pivotlar, profil birim metni ve normalize kayıt FK'ları silinmez. Legacy kip yeni veriyi yok etmez; inceleme ve yeniden shadow/pilot için bütün kayıtları korur.
