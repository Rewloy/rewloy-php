# Rewloy PHP

**Rewloy API'nin resmî PHP kütüphanesi.**

> **Durum: hazırlanıyor.** Henüz yayımlanmış bir sürüm yok. O zamana kadar API'yi
> doğrudan kullanabilirsiniz; aşağıdaki bağlantılar her şeyi anlatır.

[Rewloy](https://rewloy.com), işletmelerin dijital sadakat kartlarını
(damga, puan, VIP, cashback, hediye kartı, kupon, indirim) müşterinin
telefonuna koyar: iPhone'da Apple Cüzdan, Android'de Rewloy Cüzdan ve Google
Cüzdan, her yerde web kartı. Kasada QR okutulur; bakiye, ödül ve kampanyalar
kartın kendisinde güncellenir.

Panelde yapılabilen her şey [Rewloy API v1](https://rewloy.com/gelistiriciler)
ile de yapılabilir. Bu kütüphane o API'yi PHP'den kullanmayı kolaylaştıracak.

## Neler olacak

- **Kimlik:** API anahtarı (`rwk_…`), ekip oturumu (`rws_…`) ve kart sahibi
  oturumu (`rwh_…`).
- **Bütün uç noktalar:**
  - programlar ve kartlar;
  - kasa işlemleri: damga, puan, ödül, bakiye;
  - müşteriler ve segmentler;
  - kampanyalar ve otomasyonlar;
  - analiz;
  - şubeler ve ekip;
  - entegrasyonlar.
- **Tipler OpenAPI belgesinden üretilir.** Belge
  [`openapi.json`](https://app.rewloy.com/v1/openapi.json) adresinde. Her API
  değişikliğinde kütüphane otomatik olarak güncellenir.
- **Elle yazılan güvenlik parçaları:**
  - webhook imzalarının doğrulanması;
  - aynı işlemin iki kez yapılmasını önleyen `Idempotency-Key`;
  - geçici hatalarda ölçülü yeniden deneme.
- **Hatalar:** her hata, makine için sabit koduyla birlikte gelir
  ([hata kodları](https://rewloy.com/gelistiriciler/hatalar)).

## Kurulum

Yayımlandığında Composer ile kurulacak:

```sh
composer require rewloy/rewloy-php
```

Paket adı henüz kesin değildir.

## Belgeler

| | |
|---|---|
| Başlarken | https://rewloy.com/gelistiriciler |
| API referansı | https://rewloy.com/gelistiriciler/api |
| OpenAPI 3.1 | https://app.rewloy.com/v1/openapi.json |
| Hata kodları | https://rewloy.com/gelistiriciler/hatalar |
| Değişiklik günlüğü | https://rewloy.com/gelistiriciler/degisiklikler |

**Sürümler:**
- Kütüphane anlamsal sürümleme ([SemVer](https://semver.org)) kullanacak.
- API'ye alan eklemek geriye uyumludur.
- Kalkacak bir uç nokta en az 180 gün önce duyurulur. Duyuru süresi boyunca
  yanıtlarında `Deprecation` ve `Sunset` başlıklarını taşır.

## Güvenlik

Bir güvenlik açığı bulursanız [SECURITY.md](SECURITY.md) dosyasındaki yoldan
özel olarak bildirin. Lütfen herkese açık issue açmayın.

## Lisans

[MIT](LICENSE)

---

## English

**The official PHP library for the Rewloy API.**

**Status: in development.** There is no release yet. Until there is, use the
API directly ([getting started](https://rewloy.com/gelistiriciler),
[reference](https://rewloy.com/gelistiriciler/api),
[OpenAPI](https://app.rewloy.com/v1/openapi.json)). The documentation is in
Turkish.

**What it will provide:**
- types generated from the OpenAPI document and kept current in CI;
- webhook signature verification;
- `Idempotency-Key` handling and retries.

It will be published on Packagist. MIT licensed.
