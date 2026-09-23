# YF-10A — Yerel Entegre Pilot Raporu

> Tarih: 3 Eylül 2026
> Ortam: Yerel PostgreSQL + Laravel API + Next.js tarayıcı oturumu
> Sonuç: Başarılı; yerel elle kabul ve ardından dağıtım pilotuna hazır
> Production/staging etkisi: Yok

## 1. Bu alt fazın sınırı

YF-10A, yeni birim yetkilendirmesinin yalnız otomatik test içinde değil, yerel frontend, backend ve PostgreSQL birlikte çalışırken doğrulanmasıdır. Bu çalışmada canlı veya staging ortama bağlanılmadı; gerçek kullanıcı kaydı girilmedi; eski rol, pivot, kolon veya tablo kaldırılmadı.

Yerel çalışma düzeni:

- frontend: `http://localhost:3000`
- backend API: `http://127.0.0.1:8000/api`
- veritabanı: yerel PostgreSQL, `kademe_yeniden`
- yetkilendirme kipi: `COORDINATION_AUTHORIZATION_MODE=enforce`

## 2. Tarayıcıda doğrulanan ana senaryolar

### Çoklu üyelik: Medya coordinator → Satın Alma staff

`demo.coordinator.media@kademe.org` hesabında:

1. Medya ana üyeliğinde Mali İşlemler görünmedi; İçerik ve Duyurular gibi Medya modülleri göründü.
2. İkincil Satın Alma ve Organizasyon staff üyeliğine geçişte önce eski Medya menüsünün gösterilmediği güvenli yenileme ekranı açıldı.
3. Yenileme tamamlandığında rol etiketi global hesap rolü yerine aktif üyelik pozisyonu olan **Personel** oldu.
4. Satın Alma bağlamında Mali İşlemler göründü; Medya yönetim modülleri kayboldu.
5. Mali İşlemler ekranındaki yeni fatura proje seçimi sorumluluk kapsamındaki altı projeyi gösterdi.
6. Mali İşlemler açıkken Medya üyeliğine dönüldüğünde artık yasak olan sayfada kalınmadı; kullanıcı güvenli ana sayfasına yönlendirildi.

### Çoklu üyelik: Diplomasi360 staff → Topluluk staff

`demo.staff.p01@kademe.org` hesabında:

1. Diplomasi360 ana üyeliğinde yalnız Diplomasi proje ailesi ve ortak proje personeli modülleri göründü.
2. Topluluk ve Kültür ikincil üyeliğine geçildiğinde Diplomasi proje-family modülleri menüden tamamen çıktı.
3. Topluluk bağlamında Gönüllülük ve Motivasyon göründü; Mali İşlemler ve Medya yönetim modülleri görünmedi.
4. Programlar sayfası `community_event` çalışma kipinde yalnız ortak etkinlik kayıtlarını getirdi.

### Hizmet ekranları

- Medya İçerik ekranı açıldı ve içerik sahipliği için sorumluluk kapsamındaki altı proje listelendi.
- Satın Alma Mali İşlemler ekranı açıldı ve proje kapsamı doğru döndü.
- Topluluk Programlar ekranı altı demo ortak etkinliği gösterdi; Yoklama, Galeri ve Lojistik capability'leri aktif kurala göre göründü.
- Liste sekmesine dönülerek formdan kayıt oluşturmadan çıkılabildi; tarayıcı provasında yeni iş kaydı oluşturulmadı.

## 3. Bulunan ve giderilen entegrasyon problemleri

### Bağlam değişiminde eski sidebar'ın kısa süre görünmesi

Aktif birim seçimi yerelde değişmiş fakat yeni panel manifesti henüz gelmemişken frontend eski manifesti veya legacy menüyü kısa süre gösterebiliyordu. Bu, backend erişim kapısını aşmıyordu ancak kullanıcıya yanlış modül görünümü veriyordu.

Çözüm:

- birim değişiminde mevcut modül manifesti hemen temizleniyor,
- yetkili birim bağlamında manifest gelene kadar legacy sidebar fallback'i kullanılmıyor,
- seçimler yenileme süresince kilitleniyor ve güvenli yükleme içeriği gösteriliyor,
- profil ile manifest birlikte yenileniyor,
- yenileme sonunda açık sayfa yeni bağlamda yasaksa aktif üyeliğin güvenli ana yoluna yönlendiriliyor.

### Aktif pozisyon yerine global rol etiketinin gösterilmesi

Bir hesabın global rolü `coordinator` olsa da seçili ikincil üyeliği `staff` olabiliyor. Sidebar ve dashboard etiketi artık authority kullanıcılarında global rol yerine aktif üyelik pozisyonunu gösteriyor.

### Program listesindeki tekrar eden yetki sorguları

Program listesindeki her kayıt ve capability aynı yetki grafiğini tekrar çözüyordu. On iki demo kayıtlı istek yaklaşık **17.020 ms** sürüyordu.

`PermissionResolver` sonucu istek/job scope'unda, kullanıcı + aktif üyelik + kip anahtarıyla bir kez hesaplanacak biçimde önbelleklendi. Uzun ömürlü worker ve test süreçlerinde eski sonucun taşınmaması için her HTTP isteğinin başında cache temizleniyor.

Yerel doğrudan istek ölçümleri:

| İstek | Önce | Sonra |
|---|---:|---:|
| Topluluk Programlar | 17.020 ms | 590 ms |
| Medya İçerik | 3.767 ms | 605 ms |
| Satın Alma Dashboard | 7.361 ms | 683 ms |

Bu değerler geliştirme sunucusu ölçümüdür; üretim performans garantisi değildir. Asıl kabul, davranış matrisi değişmeden sorgu tekrarının kaldırılmasıdır.

## 4. Otomatik doğrulama sonuçları

- YF-10 yerel entegrasyon testi: **2 test, 6 assertion geçti**.
- Etkilenen backend yetkilendirme paketi: **21 test, 419 assertion geçti**.
- 18 hesaplı geniş YF-9 kabul matrisi yeniden: **6 test, 1462 assertion geçti**.
- Geniş kabul süresi istek-içi cache sonrasında **115,03 saniye** oldu; bütün yetki beklentileri aynı kaldı.
- Frontend sözleşme paketleri: **23 test geçti**.
- Frontend hedefli ESLint: geçti.
- Next.js production build: **101 route ile geçti**.
- Değişen PHP dosyalarında syntax ve Pint: geçti.
- İlk YF-10A readiness: **19 authority kullanıcı, 21 aktif üyelik, 0 teknik engel, 0 veri engeli**. 23 Eylül son kapısında iki ek deneme hesabı normalize edildikten sonra güncel sonuç **21 authority kullanıcı, 23 aktif üyelik, 0 teknik engel, 0 veri engeli** oldu.

## 5. Yerelde yeniden çalıştırma

Ön koşul: Yerel PostgreSQL çalışıyor olmalı ve backend `.env` bağlantısı yalnız yerel geliştirme veritabanını göstermelidir.

Backend:

```bash
cd backend
php artisan migrate --force
php artisan db:seed --class=DemoProjectRoleDataSeeder
php artisan coordination-units:cutover-readiness --target=enforce --strict
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend `.env.local` içinde:

```dotenv
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api
```

Frontend:

```bash
cd frontend
npm run dev
```

Tarayıcıda `http://localhost:3000/login` açılır. Önerilen çoklu üyelik hesapları:

- `demo.coordinator.media@kademe.org`
- `demo.staff.p01@kademe.org`
- ortak parola: `Demo1234!`

Demo seeder yalnız `local/testing` ortamında çalışır; staging veya production üzerinde kullanılmaz. Laravel'in `artisan serve` sunucusu tek süreçli geliştirme düzeninde eşzamanlı istekleri sıraya koyabilir; bu nedenle yerel tarayıcı zamanları production kapasite testi sayılmaz.

## 6. Dağıtım öncesi iş kararı — sonuçlandı

23 Eylül 2026'da Topluluk ve Kültür **staff** için şu ayrım onaylandı:

- ortak etkinliği ve galerisini görüntüler,
- manuel yoklama ve lojistik işlemlerini yapar,
- yeni ortak etkinlik oluşturamaz,
- mevcut ortak etkinliği güncelleyemez.

Varsayılan birim şablonundan `programs.community_event.create/update` staff kuralları çıkarıldı. Mevcut iki kural tek seferlik migration ile silinmeden `passive` yapıldı; coordinator kuralları aktif kaldı. Frontend capability tabanlı olduğu için oluşturma/düzenleme kontrolleri staff ekranından kendiliğinden kalktı.

Güncel doğrulama: hedef backend paketi **16 test/395 assertion**, YF-0 **8 test/127 assertion**, YF-9 geniş kabul **6 test/1462 assertion** ve ilgili frontend contract'ları **7 test** ile geçti. Permission dry-run **743 hedef aktif kural ve 0 değişiklik** verdi.

Canlı kalite kapısında daha önce kayıtlı dokuz frontend lint hatası davranış koruyan düzenlemelerle kapatıldı. Tam lint **0 hata, 29 uyarı** ile başarılı oldu ve production build yeniden **101 route** üretti.

Demo matrisinin dışında 23 Eylül'de oluşturulan iki aktif coordinator hesabının yerel deneme hesabı olduğu kullanıcı tarafından doğrulandı. Legacy proje ilişkileri korunarak ID 35 Eurodesk Koordinatörlüğüne, ID 36 Diplomasi360 Koordinatörlüğüne coordinator ve ana üyelik olarak bağlandı. Sonrasında strict enforce readiness **21 authority kullanıcı, 23 aktif üyelik, 0 teknik engel ve 0 veri engeli** ile geçti.

Yerel pilot denetimi yalnız 18 `demo.*@kademe.org` coordinator/staff hesabıyla çalıştırıldı: **18 kullanıcı değerlendirildi, 20 aktif üyelik, 9 aktif birim, 0 teknik engel ve 0 veri engeli**. YF-9 kabul matrisi de yalnız demo fixture hesaplarıyla yeniden **6 test, 1462 assertion** geçti. Demo dışı iki hesap tam veri bütünlüğü denetiminde yer almakla birlikte test aktörü olarak kullanılmadı.

## 7. Sonraki kapı

YF-10A teknik olarak tamamlanmıştır. Sıra:

1. geliştiricinin yerelde son elle arayüz kabulü,
2. staging migration ve dry-run,
3. gerçek üyelik verisi,
4. shadow ve kullanıcı/birim bazlı YF-10B pilotudur.

YF-10B gerçek ortam erişimi ve işveren onayı olmadan tamamlanmış sayılmaz. YF-11 legacy temizliği bundan önce başlamaz.
