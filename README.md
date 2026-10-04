# Rewloy PHP

**Rewloy API'nin resmî PHP kütüphanesi.**

> **Durum: önizleme (0.x): yayımlanmadı; API kararlı, kütüphane arayüzü 1.0'a kadar değişebilir.**

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını
müşterinin telefonuna koyar. Kart türleri damga, puan, VIP, cashback, hediye
kartı, kupon ve indirimdir:
- iPhone'da Apple Cüzdan;
- Android'de Rewloy Cüzdan ve Google Cüzdan;
- her yerde web kartı.

Kasada QR okutulur; bakiye, ödül ve kampanyalar kartın kendisinde güncellenir.
Panelde yapılabilen her şey [Rewloy API v1](https://rewloy.com/gelistiriciler)
ile de yapılabilir; bu kütüphane onu PHP'den kullanır:

- **Tipli.** API'nin her işlemi, `operationId` adıyla bir metottur.
  Argümanlar ve yanıtlar, OpenAPI belgesinden
  ([`openapi.json`](https://app.rewloy.com/v1/openapi.json)) üretilen PHPDoc
  dizi şekilleriyle (array shape) gelir. PhpStorm, Intelephense ve PHPStan
  anahtarları tanır. CI belgeyi her gün okur ve değişince yeniden üretir.
- **Bağımlılıksız.** PHP 8.2 ve üstü; `curl`, `json` ve `hash` eklentileri
  yeter.
- **Güvenli tekrar.** Geçici hatalarda ölçülü yeniden deneme; kasa işleminde
  ve kampanyada `Idempotency-Key`.
- **Ötesi:** sayfalama, canlı akış (SSE), webhook imzası doğrulama,
  kullanımdan kalkma uyarıları.

## Kurulum

Packagist'te yayımlanana kadar GitHub'dan, Composer'ın VCS deposu olarak
kurun. PHP 8.2 ya da üstü ve `curl` eklentisi gerekir:

```sh
composer config repositories.rewloy vcs https://github.com/Rewloy/rewloy-php
composer require rewloy/rewloy-php:dev-main
```

Bu iki komut `composer.json` dosyanıza şunu yazar:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Rewloy/rewloy-php" }
    ],
    "require": {
        "rewloy/rewloy-php": "dev-main"
    }
}
```

Bir sürüme bağlı kalmak için sona bir commit ekleyin:
`dev-main#<commit>`. Yayımlandığında: `composer require rewloy/rewloy-php`.

## Başlarken

```php
use Rewloy\Client;

$rewloy = new Client(apiKey: (string) getenv('REWLOY_API_KEY'));

$kart = $rewloy->getPass(['params' => ['serial' => 'ABCD-EFGH-JKLM']]);
echo $kart['type'], ' ', $kart['balance'] ?? '-', $kart['rewardReady'] ? ' (ödül hazır)' : '', "\n";
```

Her işlem, adı `operationId` olan bir metottur
([API referansı](https://rewloy.com/gelistiriciler/api)). Tek bir dizi alır,
işlemin gerektirdikleriyle:
- `params`: adresteki parametreler (`{serial}`, `{id}`…);
- `query`: sorgu parametreleri;
- `body`: JSON gövde;
- `merchant`: `Rewloy-Merchant` başlığı;
- `idempotencyKey`: `Idempotency-Key` başlığı (kasa işlemi ve kampanya);
- `timeout` (saniye) ve `maxRetries`.

Metot yanıttaki `data`yı dizi olarak döndürür. Sayfalı listelerde
`['data' => …, 'meta' => …]` döner. Gövdesiz yanıtta (`204`) dönüş değeri
yoktur. Dosyada (QR, harita, CSV, `.pkpass`) baytları taşıyan bir `string`
döner.

Her metodun PHPDoc'u, argümanların ve yanıtın şeklini, API'nin açıklamasını
ve referanstaki yerini taşır. İşlemin almadığı bir anahtar (örneğin yanlış
yazılmış `idempotency_key`) `InvalidArgumentException` atar. İşlem tablosu da
açıktır: `Rewloy\Generated\Operations::get('passAction')` →
`['method' => 'POST', 'path' => …, 'auth' => …, 'idempotency' => 'required', …]`.

### Kimlik

| İstemci | Ne için |
|---|---|
| `new Client(apiKey: 'rwk_…')` | API anahtarı: kasa, e-ticaret, kendi sisteminiz |
| `new Client(staffSession: 'rws_…', merchant: $isletmeId)` | ekip oturumu: bir kişinin işletme uygulaması |
| `new Client(holderSession: 'rwh_…')` | kart sahibi oturumu: Rewloy Cüzdan gibi müşteri uygulamaları |
| `new Client()` | kimlik istemeyen uç noktalar: giriş, katılım, kod |

`merchant`, ekip oturumu birden fazla işletmede koltuk taşıyorsa hangi işletme
için çalıştığını söyler (`Rewloy-Merchant`). Her çağrıda `merchant` ile
değiştirilebilir. Oturumlar kimliksiz bir istemciyle açılır:

```php
$giris = (new Client())->login(['body' => ['email' => $eposta, 'password' => $sifre]]);
$ekip = new Client(staffSession: $giris['token'], merchant: $isletmeId);
if ($giris['mfaRequired']) {
    $ekip->proveMfa(['body' => ['code' => '123456']]);
}
```

Bir işlem istemcinin kimlik türünü kabul etmiyor ama kimliksiz de çalışıyorsa
(örneğin `login`), istemci onu kimliksiz çağırır. API, işlemin kabul etmediği
bir kimliği reddeder (`CREDENTIAL_NOT_ALLOWED`).

Diğer seçenekler:
- `baseUrl` (varsayılan `https://app.rewloy.com`);
- `timeout`: bir denemeye verilen süre, saniye (60);
- `maxRetries` (2);
- `transport`: kendi HTTP katmanınız ([aşağıda](#http-katmanı));
- `userAgent`: gönderilen `User-Agent`a eklenir, örneğin `'KasaPOS/4.2'`.

## Kart vermek ve kasada işlem

```php
$kart = $rewloy->issuePass([
    'body' => ['programId' => $programId, 'email' => 'ayse@ornek.com', 'firstName' => 'Ayşe', 'kvkkConsent' => true],
]);

$sonuc = $rewloy->passAction([
    'params' => ['serial' => $kart['serial']],
    'body' => ['action' => 'earn-stamps', 'locationId' => $subeId, 'count' => 1],
    'idempotencyKey' => 'fis-' . $fisNo,
]);
if ($sonuc['duplicate']) {
    echo "Bu fiş zaten işlenmiş\n";
}
```

`passAction` ve `sendCampaign` bir `Idempotency-Key` ister. Verilmezse
kütüphane bir UUID üretir ve aynı çağrının her denemesinde aynısını gönderir.
Kasada fiş numarası gibi kendi anahtarınızı vermek daha iyidir: uygulama
çöküp yeniden başlasa bile aynı fiş ikinci kez işlenmez, aynı anahtarla tekrar
ilk sonucu `duplicate: true` ile döndürür.

## Sayfalama

```php
foreach ($rewloy->paginate('listCustomers', ['query' => ['consent' => 'yes', 'limit' => 200]]) as $musteri) {
    echo $musteri['displayName'], ': ', $musteri['passCount'], " kart\n";
}
```

`paginate` sayfalı her listeyi (`page`/`limit` ve `meta`) öğe öğe dolaşır ve
son sayfada durur. Döngüden çıkınca sonraki sayfayı istemez. Tek bir sayfa
için metodun kendisi yeter:

```php
['data' => $musteriler, 'meta' => $meta] = $rewloy->listCustomers(['query' => ['page' => 2]]);
```

## Canlı akış

```php
foreach ($rewloy->liveFeed() as $olay) {
    if ($olay->event === 'event') {
        $hareket = $olay->json();   // at, kind, location, program, delta, unit, name, currency
        echo $hareket['kind'], ' ', $hareket['location'], "\n";
    }
}
```

`liveFeed` (işletmenin tezgâh akışı) ve `holderCardEvents` (kart sahibinin
kartındaki değişiklik) sunucu olayları (`text/event-stream`) yayınlar.
`$rewloy->stream('liveFeed', $argumanlar)` aynı işi görür. Her olay
(`Rewloy\ServerSentEvent`) `event`, `data` ve `id` taşır; `json()` veriyi
çözer.

- **Yeniden bağlanma.** Bağlantı koparsa akış kendiliğinden yeniden bağlanır:
  sunucunun `retry:` süresi kadar bekler, bir olay `id` taşıdıysa
  `Last-Event-ID` gönderir. `'reconnect' => false` bunu kapatır.
- **Sessiz bağlantı.** API 25 saniyede bir `: hb` gönderir; 60 saniye hiç veri
  gelmezse bağlantı kopmuş sayılır (`idleTimeout`).
- **Durdurmak:** döngüden `break` ile çıkın; bağlantı hemen kapanır. Akışı bir
  değişkende tutuyorsanız `unset()` ile bırakın.
- **Bitiren hatalar.** Yeniden bağlanmanın düzeltemeyeceği bir hata (`401`,
  `403`, `404`) akışı bir `RewloyException` ile bitirir.
- **Nerede çalışır.** Akış uzun sürer. Onu bir web isteğinde değil, komut
  satırında sürekli çalışan bir işçide okuyun (supervisor, systemd). PHP-FPM
  isteklerinin süre sınırı vardır.

## Webhook doğrulama

Rewloy her teslimi imzalar:

```
Rewloy-Signature: t=<unix saniye>,v1=<hex HMAC-SHA256(sır, "<t>.<ham gövde>")>
```

`Rewloy\Webhook::verify()` imzayı **ham gövdeyle** ve webhook oluşturulurken
bir kez gösterilen sırla (`whsec_…`) doğrular:
- karşılaştırmayı sabit sürede yapar (`hash_equals`);
- `t` şimdiden 300 saniyeden (`$tolerance`) uzaksa reddeder;
- gövdeyi çözülmüş bir dizi olarak döndürür.

Tutmazsa `WebhookSignatureException` atar: 400 ile yanıtlayın ve hiçbir işlem
yapmayın. Gövde mutlaka ham olmalıdır. JSON olarak çözülüp yeniden yazılan bir
gövde imzayı tutturmaz.

Düz PHP:

```php
use Rewloy\Exception\WebhookSignatureException;
use Rewloy\Webhook;

$govde = (string) file_get_contents('php://input');
try {
    $olay = Webhook::verify($govde, $_SERVER['HTTP_REWLOY_SIGNATURE'] ?? null, (string) getenv('REWLOY_WEBHOOK_SECRET'));
} catch (WebhookSignatureException) {
    http_response_code(400);
    exit;
}
// Rewloy-Delivery bir teslimin her denemesinde aynıdır: işlediyseniz atlayın.
if (dahaOnceIslendi($_SERVER['HTTP_REWLOY_DELIVERY'] ?? '')) {
    exit;
}
if ($olay['type'] === 'pass.activity') {
    $veri = $olay['data'];   // kind, card, program_id, location_id, customer_id, unit, delta…
}
```

Laravel (`routes/api.php`; buradaki yollar CSRF jetonu istemez):

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Rewloy\Exception\WebhookSignatureException;
use Rewloy\Webhook;

Route::post('/rewloy/webhook', function (Request $request) {
    try {
        $olay = Webhook::verify(
            $request->getContent(),
            $request->headers->get('Rewloy-Signature'),
            config('services.rewloy.webhook_secret'),
        );
    } catch (WebhookSignatureException) {
        return response()->noContent(400);
    }
    // Aynı teslim yeniden gelirse atlayın.
    if (! Cache::add('rewloy:' . $request->headers->get('Rewloy-Delivery'), true, now()->addDays(3))) {
        return response()->noContent();
    }
    // … $olay['type'], $olay['data']
    return response()->noContent();
});
```

Laravel 11 ve üstünde `routes/api.php` dosyasını `php artisan install:api`
ekler. Yolu `routes/web.php`'ye koyarsanız `bootstrap/app.php` içinde CSRF
denetiminden çıkarın: `$middleware->validateCsrfTokens(except: ['rewloy/webhook'])`.

Symfony:

```php
use Rewloy\Exception\WebhookSignatureException;
use Rewloy\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RewloyWebhookController
{
    public function __construct(
        #[Autowire(env: 'REWLOY_WEBHOOK_SECRET')] private string $secret,
    ) {
    }

    #[Route('/rewloy/webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $olay = Webhook::verify($request->getContent(), $request->headers->get('Rewloy-Signature'), $this->secret);
        } catch (WebhookSignatureException) {
            return new Response(status: 400);
        }
        // … $request->headers->get('Rewloy-Delivery') ile tekrarları ayıklayın.
        return new Response(status: 200);
    }
}
```

İki çerçevede de `getContent()` ham gövdeyi verir. `$request->all()`,
`$request->toArray()` ya da `json_decode` sonrası yeniden yazılan bir gövde
imzayı tutturmaz.

Başlıklar:
- `Rewloy-Event`: olay türü (`pass.issued`, `pass.activity`, `pass.voided`,
  `webhook.test`); gövdedeki `type` ile aynı.
- `Rewloy-Delivery`: teslimin kimliği. Teslim "en az bir kez"dir: çift gelen
  teslimi bununla ayıklayın.

Gövde kişinin iletişim bilgisini taşımaz; kişiyi `customer_id` ile API'den
okuyun. 2xx ile yanıtlanmayan bir teslim yaklaşık 45 saat içinde toplam 8 kez
denenir ve her deneme yeni bir `t` ile imzalanır. Yeni olay türleri gelebilir:
`type`'a göre seçerken bir varsayılan dal bırakın. Kendi işleyicinizi test
etmek için `Webhook::sign($govde, $sir)` aynı başlığı üretir.

## Hatalar ve yeniden deneme

```php
use Rewloy\Exception\RateLimitException;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;

try {
    $rewloy->passAction([
        'params' => ['serial' => $seri],
        'body' => ['action' => 'spend', 'locationId' => $subeId, 'amountMinor' => 5000],
        'idempotencyKey' => 'fis-' . $fisNo,
    ]);
} catch (RateLimitException $e) {
    echo $e->retryAfter, " saniye sonra yeniden deneyin\n";
} catch (RewloyException $e) {
    if ($e->errorCode !== ErrorCode::INSUFFICIENT_BALANCE) {
        throw $e;
    }
    echo $e->detail, "\n";
}
```

`RewloyException` şunları taşır:
- `status`: HTTP durumu (yanıt gelmediyse 0); `getCode()` de bunu verir;
- `errorCode`: API'nin sabit kodu
  ([hata kodları](https://rewloy.com/gelistiriciler/hatalar)); kodunuz buna
  göre davranmalı. Hepsi `Rewloy\Generated\ErrorCode` sabitleridir. PHP'nin
  `getCode()`'u bir tam sayı döndürdüğü için adı `code` değil `errorCode`'dur;
- `title`: kodun katalogdaki başlığı;
- `detail`: API'nin açıklaması (Türkçe, değişebilir);
- `details`: varsa ayrıntı; doğrulama hatasında
  `[['field' => …, 'rule' => …, 'message' => …]]`;
- `requestId`: `x-request-id`; destek talebinde bunu verin;
- `body`, `headers`, `docs` ve `operation`.

Alt sınıflar:
- `RateLimitException`: `429`; `retryAfter` saniye;
- `ConnectionException`: yanıt gelmedi (`status` 0, `errorCode`
  `CONNECTION_ERROR`);
- `TimeoutException`: zaman aşımı (`TIMEOUT`); bir `ConnectionException`'dır.

Rewloy'un olmayan bir hata gövdesi (örneğin bir vekil sunucunun 502 sayfası)
`HTTP_502` gibi bir kodla gelir; JSON olmayan bir başarılı yanıt
`INVALID_RESPONSE` ile.

**Yeniden deneme.** Şunlar en çok `maxRetries` kez (varsayılan 2) yeniden
denenir: bağlantı hatası, zaman aşımı, `429`, `502`, `503`, `504` ve
Cloudflare'in `520`–`524` hataları.
- **Bekleme:** üstel ve rastgele (0,5 sn, 1 sn, 2 sn… en çok 8 sn); yanıt
  `Retry-After` taşıyorsa o kadar. `Retry-After` 60 saniyeden uzunsa
  beklenmez, hata size gelir.
- **Yalnız tekrarı güvenli istekler:** `GET`, `PUT`, `DELETE` ve
  `Idempotency-Key` taşıyan `POST`. İlk istek hâlâ işlenirken gelen
  `409 IDEMPOTENCY_IN_PROGRESS` de beklenip yeniden denenir. Diğer `POST` ve
  `PATCH` istekleri hiç tekrar edilmez.
- **Süre:** her deneme `timeout` (varsayılan 60 sn) içinde bitmelidir.

## Kullanımdan kalkma

Kalkacak bir uç nokta en az 180 gün önceden duyurulur. O süre boyunca her
yanıtı `Deprecation`, `Sunset` ve `Link` başlıklarını taşır.

- **Uyarı.** Kütüphane her işlem için bir kez `trigger_error()` ile bir
  `E_USER_DEPRECATED` bildirimi verir. Bildirim işlemi, `Sunset` tarihini ve
  değişiklik günlüğündeki kaydı söyler.
- **Nereye gider.** PHP onu hata günlüğüne yazar. Laravel,
  `LOG_DEPRECATIONS_CHANNEL` ile seçilen kanala yazar (seçilmezse atar);
  Symfony kendi günlüğüne alır.
- **Kapatmak:** `error_reporting(E_ALL & ~E_USER_DEPRECATED)`.
- **Bildirim çağrıyı bozmaz.** Hata işleyiciniz bildirimleri istisnaya
  çevirse de çağrı yanıtını döndürür; bildirim o zaman `error_log()`a gider.
- **PHPDoc.** Kalkacak metot `@deprecated` olarak işaretlenir; editörünüz
  üstünü çizer. Kalkacak yanıt alanları da metodun açıklamasında listelenir.

## Yanıtın tamamı ve test modu

```php
$yanit = $rewloy->request('sendCampaign', [
    'body' => ['body' => 'Bu hafta kahveler 2 damga!'],
    'idempotencyKey' => 'kampanya-2026-10-03',
]);
$yanit->status;      // 201
$yanit->replayed;    // true: aynı anahtarın ilk yanıtı yeniden döndü (Idempotent-Replayed)
$yanit->requestId;   // x-request-id
$yanit->mode;        // Rewloy-Mode
$yanit->data;        // kampanya
```

`request($islem, $argumanlar)` her işlemi çağırır ve yanıtın tamamını
(`Rewloy\Response`) döndürür: `data`, sayfalı listede `meta`, `status`,
`headers`, `requestId`, `mode` ve `replayed`.

`mode`, yanıtın `Rewloy-Mode` başlığıdır. Platformda test modu hazırlanıyor:
gerçek mesaj göndermeyen, gerçek kart vermeyen test anahtarları. Geldiğinde
test yanıtları bunu bu başlıkla söyleyecek. Başlık yoksa `null`.

## HTTP katmanı

Varsayılan katman `Rewloy\Http\CurlTransport`'tur: bağlantıları çağrılar
arasında açık tutar, yönlendirme izlemez. Vekil sunucu ya da kendi sertifika
deponuz için ona curl seçenekleri verin:

```php
use Rewloy\Http\CurlTransport;

$rewloy = new Client(
    apiKey: (string) getenv('REWLOY_API_KEY'),
    transport: new CurlTransport([CURLOPT_PROXY => 'http://vekil:3128', CURLOPT_CAINFO => '/etc/ssl/ca.pem']),
);
```

Kendi katmanınız için `Rewloy\Http\Transport` arayüzünü (`send()` ve
`stream()`) uygulayın. Kendi testlerinizde ağa çıkmadan yanıt vermenin yolu
da budur.

## Geliştirme

```sh
composer install
composer generate                                   # canlı belgeden: openapi/openapi.json ve src/Generated/
composer generate -- --file openapi/openapi.json    # kayıtlı belgeden
composer check                                      # PHPStan (max), sonra PHPUnit
```

- `src/Generated/` elle düzenlenmez; üreteç `scripts/Generator.php`'dir.
- Testler ağa çıkmaz. API sahte bir katmandır; curl katmanı 127.0.0.1'deki
  PHP sunucusuyla sınanır.
- CI her gün canlı belgeyi okur ve bir değişiklik varsa bir pull request açar.
- Kararlar: [docs/DECISIONS.md](docs/DECISIONS.md).

## Belgeler

| | |
|---|---|
| Başlarken | https://rewloy.com/gelistiriciler |
| API referansı | https://rewloy.com/gelistiriciler/api |
| OpenAPI 3.1 | https://app.rewloy.com/v1/openapi.json |
| Hata kodları | https://rewloy.com/gelistiriciler/hatalar |
| API'nin değişiklik günlüğü | https://rewloy.com/gelistiriciler/degisiklikler |
| Bu kütüphanenin değişiklikleri | [CHANGELOG.md](CHANGELOG.md) |

**Sürümler:**
- Kütüphane anlamsal sürümleme ([SemVer](https://semver.org)) kullanır. 1.0'a
  kadar arayüzü değişebilir.
- API'ye alan eklemek geriye uyumludur; kütüphanenin tipleri her gün
  güncellenir.
- Kalkacak bir uç nokta en az 180 gün önce duyurulur ve bu süre boyunca
  `Deprecation` ve `Sunset` başlıklarını taşır.

## Güvenlik

Bir güvenlik açığı bulursanız [SECURITY.md](SECURITY.md) dosyasındaki yoldan
özel olarak bildirin. Lütfen herkese açık issue açmayın.

## Lisans

[MIT](LICENSE)

---

## English

**The official PHP library for the Rewloy API.**

> **Status: preview (0.x), not published yet. The API is stable; the
> library's interface may change until 1.0.**

The documentation of the API itself is in Turkish (links above). In short:

- Every operation of the API is a method named by its `operationId`. Its
  arguments and answer are typed with PHPDoc array shapes generated from the
  OpenAPI document, which CI reads daily and regenerates from.
- No dependencies: PHP 8.2 or later with the `curl`, `json` and `hash`
  extensions.
- Safe retries, `Idempotency-Key` handling, pagination, server-sent events,
  webhook signature verification and deprecation notices.

### Install

Until it is on Packagist, install it from GitHub as a Composer VCS repository
(PHP 8.2 or later with `curl`). Pin a commit with `dev-main#<commit>`.

```sh
composer config repositories.rewloy vcs https://github.com/Rewloy/rewloy-php
composer require rewloy/rewloy-php:dev-main
```

### Use

```php
use Rewloy\Client;

$rewloy = new Client(apiKey: (string) getenv('REWLOY_API_KEY'));   // or staffSession: + merchant:, or holderSession:

$card = $rewloy->issuePass(['body' => ['programId' => $programId, 'email' => $email, 'kvkkConsent' => true]]);
$result = $rewloy->passAction([
    'params' => ['serial' => $card['serial']],
    'body' => ['action' => 'earn-stamps', 'locationId' => $locationId],
    'idempotencyKey' => 'receipt-' . $receiptNo,   // generated when omitted, reused across retries
]);
```

- **Arguments.** Each method takes one array: `params`, `query` and `body` as
  the operation needs, plus `merchant`, `idempotencyKey`, `timeout` (seconds)
  and `maxRetries`. A key the operation does not take throws
  `InvalidArgumentException`.
- **Results.** It returns the answer's `data` as an array:
  `['data' => …, 'meta' => …]` for paged lists, nothing for 204, a string of
  bytes for files.
- **The whole answer.** `$rewloy->request($id, $args)` returns a
  `Rewloy\Response` with `status`, `headers`, `requestId`, `mode` (the
  `Rewloy-Mode` header, for the coming test mode) and `replayed`
  (`Idempotent-Replayed`).
- **Pagination.** `$rewloy->paginate('listCustomers', $args)` is a generator
  over the items of every page.
- **Streams.** `$rewloy->liveFeed()` (or `$rewloy->stream('liveFeed', $args)`)
  is a generator of server-sent events (`event`, `data`, `id`, `json()`). It
  reconnects with `Last-Event-ID` unless `'reconnect' => false`; `break` ends
  it and closes the connection.

### Webhooks

Verify the **raw** body (`file_get_contents('php://input')`, or
`$request->getContent()` in Laravel and Symfony) with the secret shown when
the webhook was created:

```php
$event = Rewloy\Webhook::verify($rawBody, $signatureHeader, $secret);
```

- **Check.** `Rewloy-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret,
  "<t>.<raw body>")>` is compared in constant time, and `t` must be within
  300 seconds.
- **Refusal.** On failure it throws `WebhookSignatureException`: answer 400.
- **Headers.** `Rewloy-Event` is the event type. `Rewloy-Delivery` is the
  same on every retry of a delivery: deduplicate on it. Delivery is at least
  once.
- **Tests.** `Webhook::sign($body, $secret)` makes the header the platform
  would send.

### Errors, retries, deprecations

- **Errors.** Failures throw `RewloyException` with `status`, `errorCode`
  (the API's stable code; constants in `Rewloy\Generated\ErrorCode`),
  `title`, `detail`, `details`, `requestId` and `body`. Subclasses:
  `RateLimitException` (`retryAfter`), `ConnectionException` and
  `TimeoutException`.
- **What is retried.** Network errors, timeouts, 429, 502–504 and
  Cloudflare's 520–524, up to `maxRetries` (default 2), with exponential
  backoff and jitter, honouring `Retry-After` up to 60 seconds.
- **Only when safe.** Only GET, PUT, DELETE, and POST with an
  `Idempotency-Key`, are retried.
- **Deprecations.** A deprecated operation's answers carry `Deprecation`,
  `Sunset` and `Link`. The client raises one `E_USER_DEPRECATED` per
  operation per process, and the generated method is marked `@deprecated`.

### Security and licence

Report vulnerabilities privately, as [SECURITY.md](SECURITY.md) says.
[MIT](LICENSE) licensed.
