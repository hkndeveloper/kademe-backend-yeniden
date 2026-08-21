# Dönem Yaşam Döngüsü Cutover Runbook'u

Bu runbook üretim verisini hedef dönem modeline geçirirken izlenecek sıralı ve geri alınabilir operasyon akışıdır. Komutların varsayılanı salt okunurdur; `--apply` yalnız onaylı rapor ve mapping sonrasında kullanılmalıdır. Runtime sözleşmesi, tablo semantiği ve legacy kaldırma kapıları için [Dönem Yaşam Döngüsü Mimarisi ve Veri Sözlüğü](PERIOD_LIFECYCLE_ARCHITECTURE.md) belgesini kullanın.

## 0. Sürüm ve test kapısı

Dağıtılacak backend/frontend commit kimliklerini, migration listesini ve işlemi yürüten kişiyi değişiklik kaydına yazın. Kod paketi için en az şu kapılar yeşil olmadan üretim adımlarına geçmeyin:

- SQLite backend regresyon paketi;
- adı `_test` ile biten ayrı PostgreSQL veritabanında migration ve lifecycle concurrency testi;
- frontend typecheck, lint ve production build;
- staging üzerinde dönem oluşturma, activate, closing, blocker çözümü, complete, archive verify ve gerekçeli reopen provası.

Concurrency testi veya `migrate:fresh` doğrulaması üretim ya da geliştirme veritabanına karşı çalıştırılmaz. Normal test ortamı repository'deki `.env.testing` ile bellek içi SQLite'a sabitlenmiştir. Gerçek PostgreSQL testi yalnız `PERIOD_LIFECYCLE_CONCURRENCY_TESTS=1` ve adı `_test` ile biten açıkça seçilmiş ayrı veritabanıyla açılır; komuttan önce resolved connection/database adı ayrıca doğrulanır.

## 1. Ön koşullar ve yedek

1. Uygulamayı bakım/yazma durdurma penceresine alın.
2. Veritabanının tam, geri yüklenebilir yedeğini alın. PostgreSQL için kurumun standart `pg_dump --format=custom` prosedürünü kullanın.
3. Yedeği ayrı bir doğrulama veritabanına geri yükleyip tablo ve kayıt sayılarını karşılaştırın.
4. Uygulama kodunu önce `PERIOD_ENFORCE_CURRENT_POINTER=false` ile dağıtın.

Yedek geri yükleme testi doğrulanmadan migration veya `--apply` çalıştırılmaz.

## 2. Başlangıç audit'i

```bash
php artisan periods:audit --report-only --format=json --output=storage/app/period-audit-before.json --strict
```

Kritik anomali varsa durun. Özellikle şu gruplar otomatik düzeltilmez:

- bir projede birden fazla `active/closing` dönem;
- proje–dönem uyuşmazlığı veya yetim `period_id`;
- zorunlu tablolarda açıklanamayan null dönem;
- geçersiz `current_period_id`.

## 3. Migration ve dry-run

```bash
php artisan migrate --force
php artisan periods:backfill-lifecycle --format=json --output=storage/app/period-backfill-dry-run.json --strict
```

`2026_08_21_000001_strengthen_period_project_integrity` migration'ı kritik tablolarda null veya proje–dönem uyuşmazlığı görürse kısıt eklemeden hata verir. `2026_08_21_000002_enforce_single_current_period_per_project` ise aynı projede birden fazla `active/closing` dönem görürse benzersiz indeksi eklemeden durur. Önce veriyi kanıta dayalı olarak düzeltip audit'i tekrarlayın.

## 4. Manuel mapping gereken kayıtlar

Tarihi geçmiş `passive` dönemler ve arşivsiz `completed` dönemler otomatik yorumlanmaz. `docs/period-lifecycle-backfill.mapping.example.json` dosyasını kopyalayıp yalnız veri sahibi/koordinatör tarafından onaylanan dönem ID'lerini ekleyin.

İzinli legacy `passive` hedefleri:

- `planned`: henüz başlamamış dönem;
- `completed`: geçmişte tamamlanmış dönem; aynı ID `legacy_archive_period_ids` içinde de olmalıdır;
- `cancelled`: başlamadan iptal edilmiş dönem.

Önce mapping ile yeniden dry-run alın:

```bash
php artisan periods:backfill-lifecycle --mapping=storage/app/approved-period-mapping.json --format=json --output=storage/app/period-backfill-approved-plan.json --strict
```

## 5. Transaction'lı uygulama ve idempotency

```bash
php artisan periods:backfill-lifecycle --apply --mapping=storage/app/approved-period-mapping.json --format=json --output=storage/app/period-backfill-applied.json --strict
php artisan periods:backfill-lifecycle --apply --mapping=storage/app/approved-period-mapping.json --format=json --output=storage/app/period-backfill-second-run.json --strict
```

İkinci raporda `proposed_change_count=0`, `applied_change_count=0`, `blocker_count=0` olmalıdır. Legacy arşivler `override_reason=legacy_backfill` taşır ve normal v2 hash/zincir doğrulamasına tabidir.

## 6. Doğrulama ve pointer cutover

```bash
php artisan periods:audit --report-only --format=json --output=storage/app/period-audit-after.json --strict
php artisan periods:verify-archives
```

Audit temizse ortam değerini değiştirip uygulama süreçlerini yeniden başlatın:

```dotenv
PERIOD_ENFORCE_CURRENT_POINTER=true
PERIOD_LOG_LEGACY_POINTER_FALLBACK=true
PERIOD_LIFECYCLE_MONITORING_ENABLED=true
PERIOD_ARCHIVE_VERIFY_SCHEDULE_ENABLED=true
PERIOD_ARCHIVE_VERIFY_TIME=03:15
PERIOD_ARCHIVE_VERIFY_TIMEZONE=Europe/Istanbul
PERIOD_ARCHIVE_VERIFY_LOCK_MINUTES=120
```

Cutover öncesi loglarda `period_lifecycle.legacy_current_period_fallback_used` olaylarını izleyin. Pointer backfill sonrasında bu olayın sıfırlandığı doğrulanmadan strict bayrak açılmaz.

Uygulama scheduler'ı her dakika çalıştırılmalıdır (`php artisan schedule:run`). Arşiv doğrulama görevi yapılandırılan yerel saatte günlük çalışır; `withoutOverlapping` ve `onOneServer` kilitleri aynı doğrulamanın birden fazla instance tarafından eşzamanlı yürütülmesini engeller. Scheduler altyapısı doğrulanmadan canary açılmaz.

## 7. Geri dönüş

Uygulama davranışında sorun görülürse ilk ve en hızlı geri dönüş `PERIOD_ENFORCE_CURRENT_POINTER=false` yapıp süreçleri yeniden başlatmaktır. Bu, veri değişikliklerini geri almaz ancak pointer-first okumalara kontrollü legacy fallback'i yeniden açar.

Composite FK/NOT NULL migration'ını geri almak gerekiyorsa önce yeni yazmaları durdurun, yedek alın ve `php artisan migrate:rollback --step=1` çalıştırın. Backfill ile üretilen lifecycle event ve immutable arşiv kayıtlarını elle silmeyin. Veri geri dönüşü gerekiyorsa yalnız doğrulanmış tam yedekten restore prosedürünü kullanın.

Rollback kararı verilirse sıralama şöyledir:

1. Yazmaları durdurun ve olay saatini kaydedin.
2. `PERIOD_ENFORCE_CURRENT_POINTER=false` ile uyumlu önceki backend/frontend sürümüne dönün.
3. Salt-okunur `periods:audit --strict` ve `periods:verify-archives` çalıştırın.
4. Şema rollback'i gerçekten gerekliyse yeni yedek aldıktan sonra yalnız hedef migration adımını geri alın; geniş veya sayısı belirsiz rollback çalıştırmayın.
5. Veri restore'u gerekiyorsa yeni üretim kayıtlarının kayıp etkisini onaylatın ve yalnız önceden prova edilmiş yedeği ayrı doğrulama veritabanında kontrol ettikten sonra kullanın.

Arşiv/lifecycle event satırlarının korunması nedeniyle kod rollback'i veri silme anlamına gelmez. Daraltıcı eski enum şemasına, yeni statüler varken doğrudan geri dönülmez.

## 8. Saklanacak kanıtlar

- backup ve restore doğrulama kaydı;
- before/after audit JSON dosyaları;
- onaylı mapping dosyası ve onaylayan kişi/tarih;
- iki backfill raporu;
- archive verification çıktısı;
- günlük archive verification başarı olayı ve son çalışma zamanı;
- cutover sonrası fallback telemetry sonucu.

## 9. Staging prova kabul kriterleri

Gerçekçi ve anonimleştirilmiş veri kopyasında aşağıdakilerin tamamı doğrulanır:

- audit blocker sayısı sıfırdır;
- ikinci backfill koşusunda değişiklik sayısı sıfırdır;
- aynı projede iki güncel dönem oluşmaz;
- closing yeni operasyonu engeller, mevcut çözümleme aksiyonuna izin verir;
- blocker varken complete geri alınır ve arşiv oluşmaz;
- başarılı complete tek, doğrulanabilir arşiv üretir;
- reopen yalnız yetkili kullanıcı ve zorunlu gerekçeyle çalışır;
- prova rollback'inden sonra uygulama açılır, audit ve archive verify tekrar temiz geçer.

Bu kanıtlar olmadan canary aşamasına geçilmez. Canary önce tek bir düşük riskli proje/koordinatör grubunda açılır; hata oranı, reddedilen dönem yazmaları, lifecycle transition ve archive verify logları izlendikten sonra kapsam genişletilir.

## 10. Gözlemlenebilirlik ve alarm kapıları

Lifecycle operasyonları PII ve serbest metin gerekçeleri loglamadan aşağıdaki yapılandırılmış olayları üretir:

| Olay | Seviye | Anlamı / canary aksiyonu |
|---|---|---|
| `period_lifecycle.transition_succeeded` | info | Commit edilmiş lifecycle olayı; event/period/project/status/version ile sayılır. |
| `period_lifecycle.transition_rejected` | warning | Geçersiz veya çakışan lifecycle geçişi; operasyon ve HTTP durum koduyla incelenir. |
| `period_lifecycle.write_rejected` | warning | Dönem write-policy kilidi bir modül yazmasını reddetti; status/action kırılımı izlenir. |
| `period_lifecycle.closure_blocked` | warning | Complete isteği readiness blocker'ları nedeniyle transaction içinde geri alındı. |
| `period_lifecycle.archive_verification_failed` | error | Tek arşivin hash veya zincir doğrulaması başarısız; dağıtım genişletmesi hemen durdurulur. |
| `period_lifecycle.archive_verification_run_succeeded` | info | Günlük doğrulama koşusunun başarı özeti ve kontrol edilen arşiv sayısı. |
| `period_lifecycle.archive_verification_run_failed` | error | Günlük koşuda en az bir geçersiz arşiv; canary/production mutation kapsamı genişletilmez. |

Canary kabul eşikleri:

- archive verification error sayısı kesinlikle `0` olmalıdır;
- son başarılı günlük doğrulama olayı 26 saatten eski olmamalıdır;
- legacy pointer fallback olayı backfill/cutover sonrasında `0` olmalıdır;
- transition/write rejection sayıları proje, status ve action bazında başlangıç seviyesine göre izlenir; beklenmeyen artışta kapsam genişletilmez;
- closure blocker olayı tek başına sistem hatası değildir, ancak aynı dönem/blocker kodunun tekrarlı artışı operasyon sahibine yönlendirilir.
