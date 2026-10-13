# خطوات رفع ميزة B2B (زاتكا) — Staging ثم Production

هتعمل الخطوات دي **مرتين**: مرة على Staging دلوقتي، ومرة على Production بعد يومين. الخطوات في القسم A و B و C و D و E و F **واحدة بالظبط** في الحالتين، إلا الأماكن المكتوب فيها "Production بس" أو "Staging بس" صريح.

---

## A. قبل أي رفع

- [ ] باك أب كامل للداتابيز (قبل Staging وقبل Production، كل مرة).
- [ ] تأكد إن فرع الكود اللي هترفعه هو نفسه اللي التستات شغالة عليه (`packages/zatca` full suite + `phpunit.b2b.xml`) — لو مش متأكد شغّلهم تاني قبل الرفع.
- [ ] اتأكد إن حد عنده صلاحية "Super Admin" جاهز يراقب أول ساعة بعد كل رفع.

---

## B. رفع الكود

- [ ] Pull / Deploy آخر نسخة من:
  - الـ Laravel backend (`coursefer`)
  - `lms-admin-frontend`
  - الـ Student SPA (`resources/app`)
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Build الفرونت إند:
  - `lms-admin-frontend`: `npm ci && npm run build`
  - `resources/app`: `npm ci && npm run build`

---

## C. الـ Migrations

### C.1 قبل ما تشغّل الميجريشن — تأكد إن مفيش تكرار مصادر
```bash
php artisan zatca:check-source-uniqueness
```
لازم يطبع `[OK]`. لو طبع أي صف مكرر (نفس `source_reference` على أكتر من EGS)، **قف هنا ومتكملش** لحد ما تتصلح المشكلة يدويًا في الداتابيز.

### C.2 شغّل الميجريشن
```bash
php artisan migrate --force
```

هيعمل الجداول/الأعمدة دي (لو لسه مطبقتش):
| الجدول/العمود | الوظيفة |
|---|---|
| `sale_buyers` | بيانات الشركة المجمّدة لكل عملية بيع (B2B) |
| `payments.zatca_hold_reason`, `transactions.zatca_hold_reason` | البيع "المعلّق" لو فشل تسجيل بيانات الشركة |
| `zatca_invoices.submission_model/clearance_status/cleared_at/...` | أعمدة الاعتماد (Clearance) للفواتير القياسية |
| `zatca_invoices_env_src_uq` (unique index) | يمنع فاتورتين لنفس البيع على EGS مختلف |
| `zatca_credit_notes` + `zatca_debit_notes` + جداولهم الفرعية | الإشعارات الدائنة والمدينة (B2C و B2B) |

### C.3 لو حصل خطأ في الميجريشن
- `zatca_invoices_env_src_uq` بترفض تتعمل لو فيه صفوف مكررة أصلاً — ده معناه C.1 كان لازم يوقفك قبل كده.
- أي ميجريشن تاني فشل → ارجع للباك أب، متكملش الرفع.

---

## D. إعدادات `.env`

### D.1 القيم اللي **لازم تتظبط قبل أي حاجة**
```env
ZATCA_ENABLED=true          # الـ B2C شغال دايمًا، ده الكيل سويتش العام
ZATCA_B2B_ENABLED=false     # مطفي لحد ما تخلص Onboarding (قسم G)
WATHQ_ENABLED=false         # قرار حالي: التحقق يدوي بدل WATHQ (شوف قسم E)
WATHQ_SIMULATE=false        # لازم false في Staging و Production — التمثيل (simulation) بتاع WATHQ للتطوير بس
```

### D.2 القيم اللي هتتحط وقت الـ Onboarding (قسم G) — سيباها فاضية دلوقتي
```env
ZATCA_B2B_EGS_IDENTIFIER=
ZATCA_B2B_ENVIRONMENT=
ZATCA_B2B_CSR_COMMON_NAME=
ZATCA_B2B_CSR_SERIAL_NUMBER=
ZATCA_B2B_INVOICE_TYPE=
ZATCA_B2B_ORG_UNIT_NAME=
```

⚠️ **الفرق بين Staging و Production هنا:**
- **Staging**: القيم دي هتتحط بقيم *sandbox* (تجربة).
- **Production**: القيم دي هتتحط بقيم *حقيقية* اتجابت من onboarding حقيقي مع زاتكا (قسم G.2). متنقلش نفس قيم الـ sandbox للـ production أبدًا.

---

## E. قرار WATHQ (قبل ما تكمل)

القرار الحالي (زي ما اتفقنا): **WATHQ مطفي** (`WATHQ_ENABLED=false`) — يعني بيانات الشركة بتتكتب يدويًا وبتتفحص شكليًا بس (VAT format, CR format, العنوان), وزاتكا نفسها هي اللي بتتحقق من صحة المستند وقت الاعتماد. ده شغال دلوقتي في الكود بدون أي تعديل إضافي.

لو غيرتوا رأيكم وعايزين WATHQ حقيقي:
- ده مرحلة منفصلة كاملة (مش جزء من الرفع ده) — محتاجة عقد/مفتاح API حقيقي من واثق، وتأكيد شكل الـ response الحقيقي (الكود دلوقتي مبني على تخمين لشكل الـ response، مش مؤكد من واثق نفسها).

---

## F. تنضيف الكاش والـ Queue

```bash
php artisan config:clear
php artisan optimize:clear
php artisan queue:restart
```

**`queue:restart` إجباري** — فيه Jobs وConfig اتغيرت، والـ workers الشغالة لسه شايلة نسخة قديمة في الميموري لحد ما تعمل restart.

---

## G. Onboarding زاتكا للـ EGS التاني (B2B) — الخطوات كاملة

ده أهم جزء ومختلف بين Staging (sandbox) و Production (حقيقي). **كرر الخطوات دي بالكامل في الحالتين، كل بيئة لوحدها بمعرّف EGS مختلف عن التاني.**

### G.1 قبل ما تبدأ
- [ ] قرر اسم الـ identifier بتاع الجهاز التاني (مثال: `b2b`) وحطه في `ZATCA_B2B_EGS_IDENTIFIER`.
- [ ] حط `ZATCA_B2B_ENVIRONMENT=sandbox` (Staging) أو `ZATCA_B2B_ENVIRONMENT=production` (Production).
- [ ] حط `ZATCA_B2B_ORG_UNIT_NAME` (اسم الفرع/الجهاز، أي اسم مميز).
- [ ] حط `ZATCA_B2B_INVOICE_TYPE=1100` (القيمة دي متأكدة ومطابقة لعينة SDK زاتكا الرسمية — متغيرهاش).
- [ ] `php artisan config:clear`

⚠️ **ملاحظة مهمة وصريحة:** مفيش عندنا تأكيد رسمي 100% من "دليل مطوّري زاتكا" (Developer Portal User Manual §4.3) عن الطريقة الرسمية لتسجيل جهاز/EGS تاني لنفس المنشأة. الخطوات اللي جاية هي نفس خطوات الـ EGS الأول (المعمول بيه فعلاً ومظبوط)، مطبّقة تاني بمعرّف مختلف. لو زاتكا رفضت الـ CSR أو طلبت خطوة إضافية، كلم الدعم الفني بتاعهم أو ارجع لدليل المطورين قبل ما تكمل.

### G.2 توليد المفتاح الخاص والـ CSR
```bash
php artisan zatca:generate-credentials --egs=b2b
```
الأمر ده هيطبعلك:
1. **SDK fingerprint** — انسخه وحطه في `.env`:
   ```env
   ZATCA_SDK_FINGERPRINT=<القيمة اللي طبعها>
   ```
   (لو فيه قيمة موجودة أصلاً من الـ EGS الأول، لازم تتأكد إنها **نفس القيمة** — نفس نسخة الـ SDK بتخدم كل الأجهزة.)
2. **CSR** (نص طويل) — هتحتاجه في الخطوة الجاية.

### G.3 استلام OTP من بوابة فاتورة (Fatoora Portal)
- [ ] ادخل على [بوابة فاتورة](https://fatoora.zatca.gov.sa) بحساب المنشأة.
- [ ] اطلب OTP لجهاز/EGS جديد (Onboard New Solution Unit)، وحط الـ CSR اللي طلعته في الخطوة اللي فاتت.
- [ ] هتوصلك OTP (كود لمرة واحدة) على الرقم/الإيميل المسجل.

⚠️ الخطوة دي **يدوية بالكامل خارج الكود** — محدش من الأكواد بتاعتنا بيعملها.

### G.4 خطوة الـ Compliance CSID (أول اتصال فعلي بزاتكا)
```bash
php artisan zatca:onboard compliance --egs=b2b --otp=XXXXXX
```
(حط الـ OTP اللي وصلك في G.3 بدل `XXXXXX`.)

لو نجحت، هيطبعلك Request ID ويحفظ شهادة الـ Compliance مشفّرة.

### G.5 المستندات التجريبية الثلاثة (إجباري، بالترتيب ده)
```bash
php artisan zatca:onboard test-invoice --egs=b2b
php artisan zatca:onboard test-credit-note --egs=b2b
php artisan zatca:onboard test-debit-note --egs=b2b
```
كل أمر لازم يطبع `Status: PASS`. لو طلع `Status: ERROR` — **متكملش على production CSID**، اقرا رسالة الخطأ وحلها الأول (غالبًا مشكلة في بيانات المنشأة أو شكل الفاتورة).

### G.6 طلب شهادة الإنتاج (Production CSID)
```bash
php artisan zatca:onboard production --egs=b2b
```
دي آخر خطوة — لما تنجح، الجهاز بقى جاهز يبعت فواتير حقيقية لزاتكا (لسه مش هيبعت لحد ما تفعّل `ZATCA_B2B_ENABLED=true` في قسم H).

### G.7 تأكيد الصحة
```bash
php artisan zatca:health-check
```
لازم يطلع `[OK]` لكل بند خاص بـ `b2b` (SDK fingerprint, الشهادة، الاعتماد). لو طلع `[FAIL]` لأي بند، **مترفعش على العميل الحقيقي** لحد ما تتصلح.

---

## H. تفعيل B2B فعليًا

بعد ما تخلّص G بالكامل (Staging أو Production) وتتأكد من `zatca:health-check`:

```env
ZATCA_B2B_ENABLED=true
```
```bash
php artisan config:clear
```

### على Staging
- [ ] جرّب شراء شركة واحد كامل (checkout → SaleBuyer → فاتورة قياسية → Clearance) وتأكد الفاتورة طلعت "قياسية" مش "مبسطة".
- [ ] جرّب إشعار دائن/مدين على نفس الفاتورة.
- [ ] جرّب شراء فردي عادي وتأكد إنه لسه "مبسط" زي الأول بالظبط.

### على Production
- [ ] فعّلها لشركة واحدة حقيقية بس الأول (pilot) قبل ما تفتحها لكل العملاء.
- [ ] راقب `zatca:health-check` و`zatca:status` يوميًا في أول أسبوع.
- [ ] لو حصلت أي مشكلة، ارجع فورًا لـ `ZATCA_B2B_ENABLED=false` (الفواتير القديمة (B2C) هتفضل شغالة عادي، مش متأثرة).

---

## I. الصلاحيات (Roles/Permissions)

**مفيش صلاحية جديدة محتاجة تتضاف في الداتابيز.** شاشة "البيوعات المعلّقة" (Held Sales) بتستخدم صلاحيتين موجودين أصلاً:
- عرض القائمة → `list_zatca_invoices`
- حل البيع المعلّق → `add_transactions`

أي أدمن عنده الصلاحيتين دول هيشوف الميزة تلقائي بدون أي إعداد إضافي.

---

## J. التنبيهات (اختياري، مش شرط للرفع)

لو عايز تفعّل تنبيهات الأدمن على مشاكل زاتكا (فاتورة اترفضت، شهادة قربت تخلص):
```env
ZATCA_ALERTS_ENABLED=true
ZATCA_ALERTS_ROLE="Super Admin"
```
```bash
php artisan config:clear
php artisan queue:restart
```

---

## K. التحقق النهائي بعد كل رفع

```bash
php artisan zatca:health-check
php artisan zatca:status
```
- [ ] افتح "إعدادات الفوترة الإلكترونية" في الأدمن، تأكد B2C شغالة وحالتها سليمة.
- [ ] اعمل عملية شراء فردية عادية (B2C) وتأكد الفاتورة والإيميل طلعوا زي ما كانوا بالظبط — **ده أهم تأكيد، لازم يحصل في كل رفع** سواء فعّلت B2B ولا لأ.

---

## L. خطة التراجع (Rollback)

- **لو الميجريشن فشل**: ارجع للباك أب (قسم A). أعمدة `zatca_hold_reason` والجداول الجديدة إضافية بس (additive) — رجوعها آمن طالما مفيش صف حقيقي اتكتب فيها.
- **لو B2B فيه مشكلة بعد التفعيل**: `ZATCA_B2B_ENABLED=false` + `config:clear` — بيوقف بيوعات الشركات الجديدة بس، والفواتير اللي اتعتمدت قبل كده بتكمل اعتمادها عادي (قرار متعمد، مش خطأ).
- **الكود عمومًا**: رجّع النسخة السابقة من الديبلوي + `composer install` + `config:clear` + `queue:restart`.
