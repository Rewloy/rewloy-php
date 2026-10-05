# Rewloy PHP

**Rewloy API'nin resmî PHP kütüphanesi.**

> **Durum: önizleme (0.x), Packagist'te yayımlandı. API kararlı; kütüphane arayüzü 1.0'a kadar değişebilir.**

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını
müşterinin telefonuna koyar. Kart türleri damga, puan, VIP, cashback, hediye
kartı, kupon ve indirimdir:
- iPhone'da Apple Cüzdan;
- Android'de Rewloy Cüzdan ve Google Cüzdan;
- her yerde web kartı.

Kasada QR okutulur; bakiye, ödül ve kampanyalar kartın kendisinde güncellenir.
Panelde yapılabilen her şey [Rewloy API v1](https://rewloy.com/gelistiriciler)
ile de yapılabilir; bu kütüphane onu PHP'den kullanır. Geliştirici belgeleri:
**https://rewloy.com/gelistiriciler**.

- **Tipli.** API'nin her işlemi, `operationId` adıyla bir metottur.
  Argümanlar ve yanıtlar, OpenAPI belgesinden
  ([`openapi.json`](https://app.rewloy.com/v1/openapi.json)) üretilen PHPDoc
  dizi şekilleriyle (array shape) gelir. PhpStorm, Intelephense ve PHPStan
  anahtarları tanır. CI belgeyi her gün okur ve değişince yeniden üretir.
- **Bağımlılıksız.** PHP 8.2 ve üstü; `curl`, `json` ve `hash` eklentileri
  yeter.
- **Güvenli tekrar.** Geçici hatalarda ölçülü yeniden deneme; satışta, kasa
  işleminde ve kampanyada `Idempotency-Key`.
- **Ötesi:** sayfalama, canlı akış (SSE), webhook imzası doğrulama,
  kullanımdan kalkma uyarıları.

## Kurulum

PHP 8.2 ya da üstü ve `curl` eklentisi gerekir:

```sh
composer require rewloy/rewloy-php
```

## Başlarken

```php
use Rewloy\Client;

$rewloy = new Client(apiKey: (string) getenv('REWLOY_API_KEY'));

$kart = $rewloy->getPass(['params' => ['serial' => 'ABCD-EFGH-JKLM']]);
// "Şimdi ne yapılabilir?" için `actions[].ready` okunur; `rewardReady` yalnız damga ve puanda "ödül hazır"dır.
$odul = array_filter($kart['actions'], static fn (array $a): bool => in_array($a['action'], ['redeem-stamps', 'redeem-reward'], true) && $a['ready']);
echo $kart['type'], ' ', $kart['balance'] ?? '-', $odul !== [] ? ' (ödül hazır)' : '', "\n";
```

Her işlem, adı `operationId` olan bir metottur
([API referansı](https://rewloy.com/gelistiriciler/api)). Tek bir dizi alır,
işlemin gerektirdikleriyle:
- `params`: adresteki parametreler (`{serial}`, `{id}`…);
- `query`: sorgu parametreleri;
- `body`: JSON gövde;
- `merchant`: `Rewloy-Merchant` başlığı;
- `idempotencyKey`: `Idempotency-Key` başlığı (satış, kasa işlemi, kampanya ve mağaza iadesinde zorunlu);
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
- `baseUrl` (varsayılan `https://app.rewloy.com`; sonuna `/v1` eklemeniz ya da eklememeniz fark etmez: `https://app.rewloy.com/v1` de olur, kütüphane `/v1`i kendisi ekler);
- `timeout`: bir denemeye verilen süre, saniye (60);
- `maxRetries` (2);
- `transport`: kendi HTTP katmanınız ([aşağıda](#http-katmanı));
- `userAgent`: gönderilen `User-Agent`a eklenir, örneğin `'KasaPOS/4.2'`.

### Başka bir adres (staging)

API'nin başka bir kopyasına (kendi staging ortamınız ya da bir vekil sunucu)
`baseUrl` ile bağlanılır:

```php
$rewloy = new Client(
    apiKey: (string) getenv('REWLOY_API_KEY'),
    baseUrl: 'https://rewloy-staging.ornek.com',   // sonuna /v1 yazsanız da olur
);
```

Gerçek müşterilere dokunmadan denemek için adres değiştirmeniz gerekmez:
[test modu](#test-modu) aynı adreste, ayrı bir test ortamıyla çalışır.

## Kart vermek ve kasada işlem

```php
$kart = $rewloy->issuePass([
    'body' => ['programId' => $programId, 'email' => 'ayse@ornek.com', 'firstName' => 'Ayşe', 'kvkkConsent' => true],
]);

$sonuc = $rewloy->passAction([
    'params' => ['serial' => $kart['serial']],
    'body' => ['action' => 'earn-stamps', 'locationId' => $subeId, 'count' => 1],
    'idempotencyKey' => 'kasa3-z0187-fis' . $fisNo,   // aşağıya bakın
]);
if ($sonuc['duplicate']) {
    echo "Bu işlem zaten yazılmış\n";
}
```

### Satış: `recordSale`

Kasa ya da kendi yazılımınız için en kolay yol `recordSale`dir: "bu satış
oldu, sen yaz". Ödenen toplamı (kartın para biriminde, kuruş) gönderirsiniz;
ne yazılacağına kartın türü ve programın kendi kuralı karar verir. Kartın
türünü bilmeniz gerekmez.

```php
$kart = $rewloy->getPass(['params' => ['serial' => $seri]]);
// Kartın türüne özgü alanlar; `balance` yerine bunları okuyun.
if (isset($kart['stamps'])) {
    echo $kart['stamps']['count'], ' / ', $kart['stamps']['max'], " damga\n";
}
if (isset($kart['points'])) {
    echo $kart['points'], " puan\n";
}
if (isset($kart['money'])) {
    echo $kart['money']['amountMinor'] / 100, ' ', $kart['money']['currency'], "\n";
}
echo $kart['programName'], ' ', $kart['customer']['name'] ?? '', "\n";   // customer: yalnız customers.read yetkisiyle

// Fiş numarası anahtar olamaz: kasa + Z no + fiş no, ya da satışla saklanan bir UUID.
$anahtar = 'kasa3-z0187-fis' . $fisNo;
$satis = $rewloy->recordSale([
    'params' => ['serial' => $seri],
    'body' => [
        'locationId' => $subeId,
        'amountMinor' => 4550,            // 45,50: kartın para biriminde ($kart['currency']), kuruş
        'currency' => $kart['currency'],  // isteğe bağlı güvence: uyuşmazsa 422 CURRENCY_MISMATCH
        'reference' => 'fis-' . $fisNo,   // fiş numarası buraya yazılır
    ],
    'idempotencyKey' => $anahtar,
]);
if ($satis['applied'] === 'none') {
    echo 'Yazılan bir şey yok: ', $satis['reason'] ?? '', "\n";
} else {
    echo $satis['credited'], ' ', $satis['applied'], ' yazıldı, bakiye ', $satis['balance'], "\n";
}
// Fişi çizmek için ayrıca okumanız gerekmez: yazımdan sonraki kart `$satis['card']`'dadır (yetki yoksa null).
foreach ($satis['card']['actions'] ?? [] as $a) {
    if (in_array($a['action'], ['redeem-stamps', 'redeem-reward'], true) && $a['ready']) {
        echo "Ödül hazır\n";
    }
}
```

`actions[].ready`, kartın kendi durumuna göre işlemin şimdi yapılıp
yapılamayacağıdır (damga ödülü hazır mı, puan bir ödüle yetiyor mu, bakiye var
mı, kupon kullanılmamış mı, VIP ziyareti bu pencerede sayılmış mı). `rewardReady`
aynen kalır ama türe göre anlam değiştirir: damga ve puanda "ödül hazır";
cashback ve hediye kartında bakiye sıfırdan büyükse; **VIP'te her zaman
`true`**. Kasa ekranında "Ödül hazır" yazısını yalnız damga ve puanda gösterin.

`GET /v1/passes/{serial}` ayrıca `actions` (kartın aldığı kasa işlemleri ve
şimdi yapılıp yapılamayacakları) ve `sale` (bir satışın bu kartta ne
yazacağı) alanlarını verir.

**İade.** `reverseSale` bir satışın karta yazdığını geri alır; satışı
yazarken gönderdiğiniz anahtarla (`saleKey`) ya da `reference`la bulur:

```php
$geri = $rewloy->reverseSale([
    'params' => ['serial' => $seri],
    'body' => ['saleKey' => $anahtar, 'locationId' => $subeId],
]);
echo $geri['reversed'], ' ', $geri['applied'], ' geri alındı, bakiye ', $geri['balance'], "\n";
```

Bir satış bir kez geri alınır (tekrar `duplicate: true` döner). Kazanılan
kullanılmışsa (ödüle ya da harcamaya gitmişse) `409 SALE_ALREADY_SPENT` gelir ve
hiçbir şey yazılmaz.

**Çevrimdışı kasa kuyruğu: `occurredAt`.** Bağlantı koptuğunda satışı sonra
yazıyorsanız `occurredAt` ile satışın gerçekten olduğu anı (ISO 8601, saat
dilimiyle) gönderin; kartın geçmişinde o anla görünür. Gelecekte olamaz (2
dakikalık saat farkı kabul edilir). `idempotencyKey` kuyruktaki kayıtla birlikte
saklanır, tekrar gönderilince satış ikinci kez yazılmaz.

```php
$rewloy->recordSale([
    'params' => ['serial' => $seri],
    'body' => ['locationId' => $subeId, 'amountMinor' => 4550, 'reference' => 'fis-' . $fisNo, 'occurredAt' => '2026-10-05T14:32:10+03:00'],
    'idempotencyKey' => $anahtar,
]);
```

**Kasa işlemini iptal etmek: `reverseAction`.** `passAction` ile yapılan bir
harcama, ödül ya da kullanım yanlışlıkla yapıldıysa (`spend`, `spend-points`,
`redeem-stamps`, `redeem-reward`, `use`) `reverseAction` tamamını geri verir.
İşlemi, yaparken gönderdiğiniz `Idempotency-Key` (`actionKey`) ya da işlemin
`reference` değeriyle bulur (`passAction` artık isteğe bağlı bir `reference`
alır). `reverseAction` bir `Idempotency-Key` **istemez**: bir işlem bir kez geri
alınır, tekrar `duplicate: true` döner.

```php
$rewloy->passAction([
    'params' => ['serial' => $seri],
    'body' => ['action' => 'spend', 'locationId' => $subeId, 'amountMinor' => 2500],
    'idempotencyKey' => 'kasa3-z0187-iptal' . $fisNo,
]);
$iptal = $rewloy->reverseAction([
    'params' => ['serial' => $seri],
    'body' => ['actionKey' => 'kasa3-z0187-iptal' . $fisNo, 'locationId' => $subeId],   // ya da ['reference' => 'fis-' . $fisNo]
]);
echo $iptal['undone'], ' ', $iptal['restored'], ' geri verildi, bakiye ', $iptal['balance'], "\n";
```

`passAction`ın yanıtı kart türüne göre iki biçimdedir (PHPDoc'ta iki dizi
şeklinin birleşimi): bakiyeli kartlarda `balance` (damga, puan, VIP, cashback,
hediye kartı), kupon ve indirim kartında `status`, `uses` ve `usesLeft`
(`isset($sonuc['uses'])` ile ayırın; PHPStan bunu daraltır).
Kazanımlar (`earn-stamps`, `earn-points`, `visit`) `reverseAction`la değil
`reverseSale`la geri alınır.

**Yazımın yanıtında kartın durumu: `card`.** `recordSale`, `passAction`,
`reverseSale` ve `reverseAction` yanıtları `card` taşır: yazımdan sonraki kart,
`getPass`'in `customer` hariç aynı alanlarıyla (`programName`, `currency`,
`stamps`/`points`/`money`, `actions`…). Yazımla aynı işlemde okunur, yanıtın
`balance`'ıyla aynı anı söyler. **Tekrarda** (`duplicate: true`) kartın
**şimdiki** durumudur. Kimliğin kartın programında `passes.read` yetkisi yoksa
(yalnız kasa yetkisi olan bir eklenti anahtarı) `card` `null`dır. `recordSale`
yanıtındaki `reversed: true`, bu anahtarla yazılan satışın sonradan geri
alındığını söyler (yalnız bir tekrarda olabilir; `credited` ilk isteğin
yazdığıdır, kart onu artık taşımaz): fişi yeniden yazmak için yeni bir anahtar
gönderin.

**Kartın işlemleri: `listPassOperations`.** Kartın defterindeki işlemler,
yeniden eskiye, sayfalı (`$rewloy->paginate('listPassOperations', ['params' => ['serial' => $seri]])`):
bir kasa ekranındaki "son işlemler" listesi ve her birinin İade düğmesi için;
kasanın kendi anahtar günlüğünü tutması gerekmez. Her işlemde `undoWith` hangi
uç noktanın geri aldığını (`'sale/reverse'` ya da `'actions/reverse'`),
`reversible` bu kimliğin şimdi geri alıp alamayacağını söyler; bu kimliğin kendi
işlemlerinde `saleKey` ya da `actionKey` de gelir.

```php
foreach ($rewloy->paginate('listPassOperations', ['params' => ['serial' => $seri]]) as $islem) {
    if (!$islem['reversible']) {
        continue;
    }
    if ($islem['undoWith'] === 'sale/reverse') {
        $rewloy->reverseSale(['params' => ['serial' => $seri], 'body' => ['saleKey' => $islem['saleKey']]]);
    } else {
        $rewloy->reverseAction(['params' => ['serial' => $seri], 'body' => ['actionKey' => $islem['actionKey']]]);
    }
}
```

**`occurredAt` reddedilirse** `400 VALIDATION` gelir ve
`$e->details[0]['reason']` nedeni söyler: `in_future`, `too_old` (72 saatten
eski), `before_issue` (kart o anda yoktu: `occurredAt` olmadan yeniden
gönderin), `invalid`. Tanımadığınız bir `reason`'ı `invalid` gibi ele alın.

### `Idempotency-Key`

`recordSale`, `passAction`, `sendCampaign` ve `refundShopRedemption` bir
`Idempotency-Key` **ister**: API'nin tanımında (OpenAPI) bu başlık bu işlemlerde
zorunludur, bu yüzden `idempotencyKey` bu metotlarda zorunlu bir argümandır.
Verilmezse (ya da `null` ise) kütüphane istek göndermeden
`InvalidArgumentException` atar; **sizin yerinize anahtar üretmez**. Üretilmiş
rastgele bir anahtar yalnızca tek çağrının yeniden denemelerini korurdu:
uygulama çöküp yeniden başlarsa yeni bir anahtar çıkar ve satış ikinci kez
yazılabilirdi. Anahtarı kendiniz üretip satışla birlikte saklayın. Anahtar
8–64 karakterlik görünür ASCII olmalıdır (0x21–0x7E: harf, rakam ve noktalama;
boşluk, Türkçe harf ya da `fiş` gibi ASCII dışı karakter olmaz); aksi halde
kütüphane yine istek göndermeden `InvalidArgumentException` atar. Başlığın
isteğe bağlı olduğu işlemlerde (örneğin `issuePass`) anahtar verilmezse
kütüphane bir UUID üretir ve aynı çağrının her denemesinde aynısını gönderir.

- **Anahtar bir kimlik için kalıcı olarak tekildir** (8–64 karakter; defterden
  hiç silinmez). Aynı anahtarla aynı isteğin tekrarı ikinci kez yazmaz ve
  ilk sonucu `duplicate: true` ile döndürür. Aynı anahtar başka bir gövdeyle
  `422 IDEMPOTENCY_KEY_REUSED` alır.
- **Fiş numarası tek başına anahtar olamaz:** yazarkasa fiş numaraları Z
  raporundan sonra yeniden başlar. Kasa + Z no + fiş no birleşimi
  (`kasa3-z0187-fis0042`) ya da satışla birlikte saklanıp tekrarda yeniden
  gönderilen bir UUID kullanın.
- **Fiş numarası `reference` alanına** yazılır; müşterinin geçmişinde ve işlem
  dökümünde görünür.

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

Webhook'u panelden ya da API'den ekleyebilirsiniz. `webhooks.manage` yetkili
bir API anahtarı `createWebhook`, `listWebhooks`, `getWebhook`,
`setWebhookStatus`, `testWebhook` ve `listWebhookDeliveries`yi çağırabilir;
`webhookEvents` abone olunabilecek olayları söyler. Sır (`secret`) yalnız
`createWebhook` yanıtında gelir, saklayın:

```php
$yeni = $rewloy->createWebhook([
    'body' => ['url' => 'https://ornek.com/rewloy/webhook', 'events' => ['pass.activity', 'pass.voided']],
]);
$sir = $yeni['secret'];
$rewloy->testWebhook(['params' => ['id' => $yeni['webhook']['id']]]);   // webhook.test olayı gönderir
```

Adres herkese açık bir `https` adresi olmalıdır (test ortamında da);
yerelde bir tünel kullanın.

**Sırrı yenilemek.** Kaybolan ya da sızan bir sır için `rotateWebhookSecret`
webhook'a yeni bir sır verir (yeni `secret` yalnız o yanıtta döner); webhook'u
silip yeniden eklemek gerekmez. Eski sır 24 saat daha yeninin yanında imzalar:
o sürede `Rewloy-Signature` iki `v1` taşır ve teslimler
`Rewloy-Signature-Rotating: 1` başlığıyla gelir. `Webhook::verify()` her `v1`'i ve
`$secret` olarak verilen birden çok sırrı dener; yenilemeden önce alıcınızı
`[$yeni, $eski]` ile güncelleyin. `deleteWebhook` webhook'u teslim geçmişiyle
birlikte kalıcı siler (`204`).

```php
$r = $rewloy->rotateWebhookSecret(['params' => ['id' => $webhookId]]);
// yeni sırrı alıcınıza ekleyin, 24 saat sonra eskisini bırakın
$olay = Rewloy\Webhook::verify($hamGovde, $imzaBasligi, [$r['secret'], $eskiSir]);
```

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
        'idempotencyKey' => 'kasa3-z0187-fis' . $fisNo,
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
- `RateLimitException`: `429`; `retryAfter` saniye. Her istisna (bu dahil)
  yanıtın `RateLimit-*` başlıklarını `$e->rateLimit()` ile verir
  (`Rewloy\RateLimit`: `limit`, `remaining`, `reset`; başlık yoksa `null`);
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
$yanit->rateLimit(); // RateLimit-* başlıkları: limit, remaining, reset (yoksa null)
$yanit->mode;        // Rewloy-Mode
$yanit->data;        // kampanya
```

`request($islem, $argumanlar)` her işlemi çağırır ve yanıtın tamamını
(`Rewloy\Response`) döndürür: `data`, sayfalı listede `meta`, `status`,
`headers`, `requestId`, `mode` ve `replayed` (`rateLimit()` istek sınırı
başlıklarını okur).

`mode`, yanıtın `Rewloy-Mode` başlığıdır: `live` ya da `test`. Başlık yoksa
`null`.

## Test modu

Gerçek müşterilere dokunmadan denemek için işletmenizin bir **test ortamı**
vardır: ona bağlı ayrı bir işletme (adı "· Test" ile biter); kendi
programları, müşterileri, kartları, anahtarları ve webhook'ları. Panel →
Geliştirici → "Test ortamını aç" ya da `POST /v1/test/environment`. Orada
oluşturulan anahtar `rwk_test_` ile başlar ve aynı adreste, aynı yollarla
çalışır:

```php
$rewloy = new Client(apiKey: (string) getenv('REWLOY_TEST_KEY'));   // rwk_test_…
$yanit = $rewloy->request('getPass', ['params' => ['serial' => $seri]]);
$yanit->mode;   // 'test'
```

- Test ortamı hiçbir şey göndermez (e-posta, bildirim, SMS); kartlar
  cüzdanlara eklenmez. Gönderilmeyenler `GET /v1/test/messages` ile okunur.
- Webhook'lar teslim edilir ve `Rewloy-Test: 1` başlığıyla `"test": true`
  taşır.
- Gerçek müşteri verisini test ortamına girmeyin.
- `resetTestEnvironment` (1.2.0'dan beri) müşterileri, kartları, kodları ve
  kayıtları siler; ortamın kimliği, programları, şubeleri, anahtarları ve
  webhook'ları kalır, entegrasyonunuz aynı anahtarla sürer. Bir anahtar
  sızdıysa `'body' => ['revokeKeys' => true]` anahtarları da geçersiz kılar ve
  webhook'ları kapatır. Yanıt `deleted` ve `kept` sayılarını verir; `closed`
  artık hep `null`dır.
- POS için anahtar: `createApiKey(['body' => ['kind' => 'pos', 'locationId' => $subeId, 'register' => 'Kasa 1', 'password' => $sifre]])`
  hazır Kasa rolüyle yalnız o şubede çalışan bir anahtar oluşturur; yanıttaki
  `baseUrl` POS'a yazılacak adrestir.
- `listAllBatches` işletmenin bütün hediye kartı, kupon ve indirim kodlarını
  sayfalar (`status`: `open`, `full`, `expired`, `closed` ya da `archived`: kodun
  programı arşivde, bağlantısı kart vermez). Arşivdeki bir programa kod
  oluşturmak `409 PROGRAM_ARCHIVED` verir.

Ayrıntı: https://rewloy.com/gelistiriciler#test-ortamı

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

Developer docs (in Turkish): **https://rewloy.com/gelistiriciler**.

**The official PHP library for the Rewloy API.**

> **Status: preview (0.x), published on Packagist. The API is stable; the
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

PHP 8.2 or later with `curl`:

```sh
composer require rewloy/rewloy-php
```

### Use

```php
use Rewloy\Client;

$rewloy = new Client(apiKey: (string) getenv('REWLOY_API_KEY'));   // or staffSession: + merchant:, or holderSession:

$card = $rewloy->issuePass(['body' => ['programId' => $programId, 'email' => $email, 'kvkkConsent' => true]]);
$sale = $rewloy->recordSale([
    'params' => ['serial' => $card['serial']],
    'body' => ['locationId' => $locationId, 'amountMinor' => 4550, 'reference' => 'receipt-' . $receiptNo],  // amount in the card's currency, minor units
    'idempotencyKey' => 'till3-z0187-r' . $receiptNo,
]);

// A gift-card spend rung up by mistake? Void it by the key it was sent with:
$rewloy->passAction([
    'params' => ['serial' => $card['serial']],
    'body' => ['action' => 'spend', 'locationId' => $locationId, 'amountMinor' => 2500],
    'idempotencyKey' => 'till3-z0187-s' . $receiptNo,
]);
$voided = $rewloy->reverseAction([
    'params' => ['serial' => $card['serial']],
    'body' => ['actionKey' => 'till3-z0187-s' . $receiptNo],
]);
echo $voided['undone'], ' ', $voided['restored'], ' ', $voided['balance'], "\n";   // spend 2500 and the balance again
```

- **Till.** `recordSale` writes a completed sale to a card (the card type and
  the programme's own rule decide what is written); `getPass` returns the
  card's structured fields (`programName`, `currency`, `stamps`, `points`,
  `money`, `customer`); `reverseSale` takes a refunded sale back:
  `$rewloy->reverseSale(['params' => ['serial' => $serial], 'body' => ['saleKey' => $key]])`.
  A void is `reverseAction`: it takes back a `passAction` that was a mistake
  (`spend`, `spend-points`, `redeem-stamps`, `redeem-reward`, `use`), found by
  the `Idempotency-Key` you sent with it (`actionKey`) or its `reference`; it
  needs no `Idempotency-Key` of its own, and a repeat answers `duplicate: true`:
  `$rewloy->reverseAction(['params' => ['serial' => $serial], 'body' => ['actionKey' => $key]])`.
  A till that queues sales while offline sends `occurredAt` (ISO 8601 with the
  UTC offset, not in the future) with `recordSale`, so the card's history shows
  when the sale really happened; the queued `idempotencyKey` makes the resend
  safe. `passAction` takes an optional `reference` too, and its answer is the
  union of two array shapes: the balance-card answer (`balance`) or the coupon /
  discount-card answer (`status`, `uses`, `usesLeft`; `isset($answer['uses'])`
  narrows it).
- **`card` on write answers.** `recordSale`, `passAction`, `reverseSale` and
  `reverseAction` answer with `card`: the card after the write, the fields of
  `getPass` except `customer`, read in the same transaction (on a replay,
  `duplicate: true`, it is the card's **current** state). A key without
  `passes.read` in the card's programme gets `card: null`. `recordSale`'s
  `reversed: true` (replays only) says the sale written under that key was
  taken back since: send a new key to write the receipt again. For "can I act
  now" read `card['actions'][]['ready']`; `rewardReady` means "reward ready" only
  for stamp and points cards (always `true` on VIP, any balance on cashback and
  gift cards).
- **Recent operations.** `listPassOperations` lists a card's ledger operations,
  newest first and paged, for a till's "last operations" screen: `undoWith`
  (`'sale/reverse'` or `'actions/reverse'`), `reversible` and, for this
  credential's own operations, `saleKey` / `actionKey` to pass straight to
  `reverseSale` / `reverseAction`.
- **Rejected `occurredAt`** is a `400 VALIDATION` whose `$e->details[0]['reason']`
  is `in_future`, `too_old`, `before_issue` or `invalid` (treat an unknown
  reason as `invalid`).
- **Idempotency keys.** `recordSale`, `passAction`, `sendCampaign` and
  `refundShopRedemption` need an `Idempotency-Key`: the API's OpenAPI document
  marks the header required for them, so `idempotencyKey` is a required
  argument and the client throws an `InvalidArgumentException` before sending
  if it is missing or `null`. It never makes one up for you (a generated key
  would not survive a restart of your app). The key must be 8–64 printable
  ASCII characters (0x21–0x7E); a non-ASCII key such as `fiş-0042` is refused
  client-side, with an `InvalidArgumentException`, before anything is sent.
  Where the header is optional (for example `issuePass`) the client still
  generates a UUID and reuses it on every retry of the call. A key is unique **for good per credential**: do not use the
  receipt number alone (fiscal receipt numbers restart after the Z report) but
  register + Z number + receipt number, or a UUID stored with the sale. The
  receipt number goes in `reference`.
- **Base URL.** `new Client(apiKey: …, baseUrl: 'https://staging.example.com')`
  or `baseUrl: 'https://staging.example.com/v1'`: with or without a trailing
  `/v1` (and trailing slashes), the client appends `/v1/...` itself. Default
  `https://app.rewloy.com`.
- **Test mode.** Open the test environment (panel → Developer, or
  `POST /v1/test/environment`) and use its `rwk_test_` key at the same address:
  a separate test business that sends nothing and never reaches real
  customers. Webhooks are delivered with `Rewloy-Test: 1`.
- **Arguments.** Each method takes one array: `params`, `query` and `body` as
  the operation needs, plus `merchant`, `idempotencyKey`, `timeout` (seconds)
  and `maxRetries`. A key the operation does not take throws
  `InvalidArgumentException`.
- **Results.** It returns the answer's `data` as an array:
  `['data' => …, 'meta' => …]` for paged lists, nothing for 204, a string of
  bytes for files.
- **The whole answer.** `$rewloy->request($id, $args)` returns a
  `Rewloy\Response` with `status`, `headers`, `requestId`, `mode` (the
  `Rewloy-Mode` header: `live` or `test`), `replayed`
  (`Idempotent-Replayed`) and `rateLimit()` (`Rewloy\RateLimit` with `limit`,
  `remaining`, `reset` from the `RateLimit-*` headers; null when absent).
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

`rotateWebhookSecret` gives a webhook a new secret (returned only in that
answer); the old one keeps signing for 24 hours, so `Rewloy-Signature` carries
two `v1` values and the delivery has `Rewloy-Signature-Rotating: 1`.
`Webhook::verify()` tries every `v1` and every secret you pass:
`[$newSecret, $oldSecret]`. `deleteWebhook` removes a webhook and its delivery
history for good.

Also in Rewloy 1.2.0 (library 0.2.4): `createApiKey(['body' => ['kind' => 'pos', 'locationId' => …, 'register' => …, 'password' => …]])`
(a till key bound to one branch); `resetTestEnvironment(['body' => ['revokeKeys' => true]])`
(keeps the test business, programmes and keys; revokes keys only when asked);
`listAllBatches` (every gift-card, coupon and discount code of the business,
with the `archived` state); `409 PROGRAM_ARCHIVED` when creating a code for an
archived programme.

### Errors, retries, deprecations

- **Errors.** Failures throw `RewloyException` with `status`, `errorCode`
  (the API's stable code; constants in `Rewloy\Generated\ErrorCode`),
  `title`, `detail`, `details`, `requestId`, `rateLimit()` and `body`. Subclasses:
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

## Yeni sürüm yayımlamak / Releasing

`Client::VERSION`'ı ve CHANGELOG'u güncelleyin, commit'leyin, `v<sürüm>` etiketini gönderin. Packagist etiketi kendiliğinden alır.

Bump `Client::VERSION` and the changelog, commit, and push a `v<version>` tag. Packagist picks the tag up by itself.
