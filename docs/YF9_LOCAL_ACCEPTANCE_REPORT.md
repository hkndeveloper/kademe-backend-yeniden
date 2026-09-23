# YF-9 — 18 Hesaplı Yerel Kabul Raporu

> Tarih: 3 Eylül 2026
> Ortam: Yerel PostgreSQL + Laravel feature test ortamı + Next.js production build
> Sonuç: Başarılı; YF-10 staging/shadow/pilot hazırlığına geçilebilir
> Production etkisi: Yok; demo seeder yalnız `local/testing` ortamında çalışır

## 1. Kabul hesapları

Tüm hesapların ortak yerel parolası: `Demo1234!`

| Birim | Coordinator hesabı | Staff hesabı |
|---|---|---|
| Diplomasi360 | `demo.coordinator.p01@kademe.org` | `demo.staff.p01@kademe.org` |
| KADEME+ | `demo.coordinator.p02@kademe.org` | `demo.staff.p02@kademe.org` |
| Eurodesk | `demo.coordinator.p03@kademe.org` | `demo.staff.p03@kademe.org` |
| Pergel Fellowship | `demo.coordinator.p04@kademe.org` | `demo.staff.p04@kademe.org` |
| Kariyer Psikolojik Danışmanlık (KPD) | `demo.coordinator.p05@kademe.org` | `demo.staff.p05@kademe.org` |
| Zirve KADEME | `demo.coordinator.p06@kademe.org` | `demo.staff.p06@kademe.org` |
| Medya Koordinatörlüğü | `demo.coordinator.media@kademe.org` | `demo.staff.media@kademe.org` |
| Satın Alma ve Organizasyon Koordinatörlüğü | `demo.coordinator.purchase.organization@kademe.org` | `demo.staff.purchase.organization@kademe.org` |
| Topluluk ve Kültür Koordinatörlüğü | `demo.coordinator.community.culture@kademe.org` | `demo.staff.community.culture@kademe.org` |

Hesap adlarındaki `pNN` yerel proje ID'sidir. Demo veride P01–P06 sırasıyla yukarıdaki altı projeye karşılık gelir. Bu hesaplar production kullanıcılarına dönüştürülmez. On sekiz hesabın tamamında yukarıdaki ortak parola ile gerçek `/api/auth/login` isteği, Bearer token üretimi ve doğru aktif birim bağlamı otomatik olarak doğrulanmıştır.

## 2. Çoklu üyelik kabul hesapları

Hesap sayısı 18 olarak korunurken iki hesaba ikincil üyelik eklenmiştir:

| Hesap | Birincil üyelik | İkincil üyelik | Kabul amacı |
|---|---|---|---|
| `demo.coordinator.media@kademe.org` | Medya / coordinator | Satın Alma ve Organizasyon / staff | Hizmetten hizmete geçişte içerik ve finans yetkilerinin birleşmediğini doğrulamak |
| `demo.staff.p01@kademe.org` | Diplomasi360 / staff | Topluluk ve Kültür / staff | Projeden hizmete geçişte proje ve gönüllülük yetkilerinin birleşmediğini doğrulamak |

Her iki hesapta da yalnız bir aktif ana üyelik vardır. İkincil üyelik seçimi `X-Coordination-Unit-Id` ile yapılır. Seçilmeyen üyeliğin action'ları, sidebar'ı ve kayıt kapsamı etkili sonuca eklenmez. Kullanıcının sahip olmadığı üçüncü birim ID'si `403 invalid_coordination_unit_context` üretir.

## 3. Sidebar ve çalışma kipleri

Proje birimlerinde ortak çekirdek sidebar coordinator ve staff pozisyonuna göre ayrılır; yalnız bağlı projenin özel ailesi eklenir:

- Diplomasi360: `diplomasi360`
- KADEME+: `kademe_plus`
- Eurodesk: `eurodesk`
- Pergel Fellowship: `pergel` ve `assignments`
- KPD: `kpd`
- Zirve KADEME: `zirve_kademe`

Exclusive hizmet görünümü:

| Hizmet birimi | İzin verilen özel modüller | Özellikle görünmeyen modüller |
|---|---|---|
| Medya | İçerik, duyurular, proje kamusal içeriği/galeri, program medyası, kariyer/fırsat içeriği | Mali işlemler, gönüllülük, motivasyon |
| Satın Alma ve Organizasyon | Mali işlemler, program lojistiği, takvim, organizasyon talepleri/destek | İçerik, duyurular, gönüllülük, motivasyon |
| Topluluk ve Kültür | Topluluk etkinlikleri, ilgili yoklama, gönüllülük, motivasyon, takvim | Mali işlemler, içerik, duyurular |

Program endpoint'i aynı kalır fakat dört ayrı `work_mode` üretir: proje birimleri `core`, Medya `media`, Satın Alma ve Organizasyon `logistics`, Topluluk ve Kültür `community_event`.

## 4. Planın 12 kabul maddesi ve kanıtı

| # | Kabul maddesi | Otomatik kanıt | Sonuç |
|---:|---|---|---|
| 1 | Sidebar snapshot | 18 hesabın manifest module ID'leri işveren/baseline matrisiyle birebir karşılaştırıldı | Geçti |
| 2 | Root route erişimi | Manifestteki 28 authority kök yolunun gerçek Next.js App Router sayfası olduğu ve manifest guard tarafından kabul edildiği doğrulandı | Geçti |
| 3 | Modül liste verisi | Program, dashboard, talep, destek, inbox ve exclusive hizmet liste endpoint'leri gerçek HTTP istekleriyle çalıştırıldı | Geçti |
| 4 | Proje dropdown kapsamı | Her hesabın dropdown proje ID'leri ilgili action'ın resolver scope sonucu ile birebir karşılaştırıldı | Geçti |
| 5 | Görünür butonun uygun senaryosu | Manifest `enabled_actions ⊆ actions` kontrolü ve her hesap için proje yoklama, medya kamusal içerik veya hizmet lojistik action'ı başarıyla çalıştırıldı | Geçti |
| 6 | Gizli action doğrudan API | İçerik, duyuru, fırsat, finans, gönüllülük ve motivasyon endpoint'lerinde yetkisiz hesapların tamamı 403 aldı | Geçti |
| 7 | Başka proje/birim kaydı | On iki proje hesabı kendi programını açtı, başka proje programında 403 aldı; hizmet work-mode kayıt sınırları ayrıca doğrulandı | Geçti |
| 8 | Export ve dosya indirme | Talep, destek, program ve yoklama export'ları permission sonucuna göre 200/403; fatura indirme guard'ı permission varsa 404, yoksa 403 verdi | Geçti |
| 9 | Dashboard kartları | 18 hesabın `/api/panel/dashboard/stats` isteği başarılı | Geçti |
| 10 | Profil ve izin | 18 profil isteği başarılı; 18 izin kaydı doğru `unit_id`, `membership_id` ve coordinator/staff reviewer scope'u ile oluştu | Geçti |
| 11 | Talep/destek hedefleme | 18 talep aynı birimdeki karşı pozisyona gönderildi; oluşturan 403 aldı, yalnız hedef üye durumu güncelledi; 18 destek kaydı başarıyla oluştu | Geçti |
| 12 | Çoklu üyelik context | Yukarıdaki iki gerçek demo hesapta hizmet→hizmet ve proje→hizmet sidebar/API geçişi, birleşmeme ve geçersiz birim 403'ü doğrulandı | Geçti |

## 5. Test ve build sonuçları

- Yeni backend YF-9 kabul paketi: **6 test, 1462 assertion geçti**; bunun içinde 18 hesabın gerçek parola ile giriş ve doğru aktif birim bağlamı kontrolü de vardır.
- Seeder, eski sidebar baseline'i, aktif context, exclusive hizmet, ortak modül, workflow, topluluk programı, proje-family, readiness ve Yetki Matrisi regresyonu: **39 test, 754 assertion geçti**.
- Frontend yetkilendirme/manifest/program/context sözleşmeleri: **22 test geçti**; bunun 3'ü yeni YF-9 route/context kabul testidir.
- Yeni frontend YF-9 test dosyası ESLint kontrolünden geçti.
- Next.js 16.2.4 production build **101 route ile geçti**.
- Yerel enforce readiness: **19 authority kullanıcı, 21 aktif üyelik, 0 teknik engel, 0 veri engeli**.
- İki kez arka arkaya demo seed işlemi kayıt çoğaltmadı.

Genel `npm run lint` YF-9'dan önce var olan farklı ekranlardaki 9 hata nedeniyle hâlâ başarısızdır. YF-9'un eklediği dosyada lint hatası yoktur ve production build/TypeScript başarılıdır. Bu mevcut frontend lint borcu YF-10 öncesi ayrı bir kalite işi olarak izlenmelidir; birim yetkilendirme kabulini yanlış olumlu göstermemek için rapordan gizlenmemiştir.

## 6. Yerel tekrar çalıştırma

```bash
cd backend
php artisan db:seed --class=DemoProjectRoleDataSeeder
php artisan coordination-units:cutover-readiness --target=enforce --strict
php artisan test tests/Feature/Yf9LocalAcceptanceMatrixTest.php

cd ../frontend
npm run test:yf9-acceptance
npm run build
```

## 7. Faz sonucu

YF-9 kabul ölçütleri otomatik olarak karşılandı. Beklenmeyen sidebar, beklenmeyen doğrudan API 200 sonucu, çapraz proje sızıntısı veya çoklu üyelik permission birleşimi bulunmadı. Eski veriler silinmedi; yalnız yerel demo fixture'a iki aktif fakat ikincil üyelik eklendi.

Sıradaki faz YF-10'dur. Bu faz staging/canlı ortam erişimi, gerçek kullanıcı-birim üyelikleri ve işveren onayı gerektirir; demo readiness tek başına canlı enforce izni değildir.
