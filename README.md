# Spanish company data API – PHP SDK

Official PHP SDK for [SpainCompanyAPI](https://spaincompanyapi.com) (APIEmpresas.es): Spanish company data by tax ID (CIF/NIF). Use it to verify Spanish companies (KYB), validate customer tax IDs before invoicing, and get directors, registry filings (BORME), sanctions screening, public contracts and grants.

- **Docs in English:** [spaincompanyapi.com/docs](https://spaincompanyapi.com/docs)
- **Free API key:** 100 requests, no card required. [Sign up](https://spaincompanyapi.com/register?intent=api&plan=free&source=packagist_sdk)
- **Coverage:** over 4.7 million Spanish companies, updated every working day from the Commercial Registry gazette (BORME) and other public registers.

> Versión en español al final de este documento.

## Installation

```bash
composer require apiempresas/apiempresas-php
```

PHP 7.4 or later, with the `curl` and `json` extensions.

## Quick start

```php
require_once 'vendor/autoload.php';

use ApiEmpresas\ApiEmpresas;
use ApiEmpresas\Exceptions\ApiException;

// Second argument optional: same API, error messages in English
$api = new ApiEmpresas(getenv('APIEMPRESAS_API_KEY'), 'https://spaincompanyapi.com/api/v1');

$company = $api->companies->get('A46103834');
echo $company['name'], ' ', $company['status_code']; // MERCADONA SA ACTIVE
```

The default base URL is `https://apiempresas.es/api/v1`. Both hosts serve the same API and the same key; `spaincompanyapi.com` returns error messages in English.

## Verify a Spanish company (KYB) – `/companies/verify`

One call checks that the company exists and is operating, that the name you were given matches the legal name, that the signer is a current director, and optionally the EU VAT number (VIES). Pro plan, 2 requests.

```php
$v = $api->companies->verify('A46103834', [
    'name'   => 'Mercadona SA',               // compared with the registered legal name
    'person' => 'Juan Roig Alfonso',          // is this person a current director (or sole shareholder)?
    'vat'    => true,                         // EU VAT number registered in VIES?
    'iban'   => 'ES9121000418450200051332',   // well formed? (it does not tell you who owns the account)
]);

echo $v['decision_hint'];   // pass | review | fail
print_r($v['flags']);       // red flags with code, severity and message
print_r($v['checks']);      // name, person, vat, iban (and sanctions on Business)
```

A company whose tax ID has been revoked by the Spanish Tax Agency returns `decision_hint: fail` with the flag `TAX_ID_REVOKED`. Every flag explains its reason, so your code can decide what to block and what to send to manual review.

## Validate customer tax IDs before invoicing – `/companies/verify/batch`

Up to 1,000 tax IDs per call. Each row comes back in the same order with an `invoice_hint`. Pro, Business or credits; 1 request per company found (malformed, personal and not-found tax IDs are free).

```php
$r = $api->companies->verifyBatch([
    ['cif' => 'A46103834', 'name' => 'Mercadona', 'ref' => 'customer-0001'], // name: the legal name you hold
    ['cif' => 'A15075062', 'name' => 'Inditex',   'ref' => 'customer-0002'], // ref: your own id, returned as is
    'B12345674',                                                             // or just the tax ID
]);

foreach ($r['data'] as $row) {
    echo "{$row['input']}: {$row['invoice_hint']}\n"; // ok | revisar | no_facturar
}
echo $r['meta']['found'], ' found, cost ', $r['meta']['cost'];

// Also check the EU VAT number in VIES (batches of up to 20)
$api->companies->verifyBatch(['A46103834'], ['vat' => true]);
```

`invoice_hint` values are Spanish strings: `ok`, `revisar` (review) and `no_facturar` (do not invoice). Each row has `valid_format`, `exists`, `legal_name`, `address` (registered office), `status_code`, `name_check` (if you send `name`) and `flags`. If your balance runs out, rows that do not fit come back with `processed: false`. This is not a check against the Tax Agency census.

## Company data

```php
// Directors and positions (Pro/Business)
$company = $api->companies->get('A15075062', ['admin' => true]);

// Several companies by name, with pagination
$page = $api->companies->searchMultiple('software', ['limit' => 20]);
if (!empty($page['meta']['next_cursor'])) {
    $page = $api->companies->searchMultiple('software', ['cursor' => $page['meta']['next_cursor']]);
}

// Several tax IDs in one call
$batch = $api->companies->batch(['A15075062', 'A46103834']);

// Name to tax ID (Pro): only matches are charged
$r = $api->companies->reconcile(['Mercadona', ['name' => 'Talleres Pérez', 'province' => 'Madrid']]);
// $r['data'][i]['status']: match (with company.cif) | ambiguous (candidates) | no_match
```

Each company includes `status_code` (ACTIVE, INSOLVENCY, IN_LIQUIDATION, DISSOLVED, MERGED, EXTINCT…), `status_source`, `status_date`, `status_confidence`, `tax_id_revocation`, `identifiers` (LEI), `legal_form` (with its ISO 20275 code) and `nace` (NACE Rev. 2 next to the Spanish CNAE). On Pro/Business it also includes `financials` (size band), `group` (parent companies per GLEIF), `ownership` (sole shareholder per the BORME), `size` (headcount band with its source) and `address_density`.

## Segments and new companies

```php
$total = $api->companies->count(['cnae' => ['62'], 'province' => 'MADRID', 'has_phone' => true]); // free
$page  = $api->companies->filter(['cnae' => ['62'], 'province' => 'MADRID', 'limit' => 100]);    // Business: 5 requests per row
// Next page: 'cursor' => $page['meta']['next_cursor'] with the same filters

// Newly incorporated companies (all plans: 10 results on Free with name and tax ID hidden, 100 on Pro, 1,000 on Business)
$recent = $api->companies->radar(['province' => 'Madrid', 'range' => '7', 'cnae' => '62', 'min_score' => 70]);

// Match (Business): the seller's sector is required
$match = $api->companies->match('A15075062', 'software');
```

## Monitoring and webhooks

```php
// Watchlist (Pro: 100 companies, Business: 1,000), free of charge
$api->watchlist->add(['A46103834', 'A28015865']);
$changes = $api->watchlist->events(['since' => '2026-09-01', 'types' => ['borme_act', 'status_change']]);

// Webhooks (Business)
$hook = $api->webhooks->create(['url' => 'https://your-server.com/apiempresas', 'event' => 'watchlist.*']);
$api->webhooks->create([
    'url'     => 'https://your-server.com/new',
    'event'   => 'company.created',
    'filters' => ['province' => ['MADRID'], 'min_capital' => 3000],
]);
$api->webhooks->test($hook['id']);
$deliveries = $api->webhooks->deliveries($hook['id'], 'dead', null, 20);
```

Verify the signature on your server with the raw body:

```php
use ApiEmpresas\Resources\Webhooks;

$raw = file_get_contents('php://input');
try {
    $event = Webhooks::constructEvent($raw, $_SERVER['HTTP_X_APIEMPRESAS_SIGNATURE'] ?? null, getenv('APIEMPRESAS_WEBHOOK_SECRET'));
    // $event['event']: company.created | company.borme_act | company.status_changed | company.risk_level_changed | test.ping
    http_response_code(200);
} catch (\ApiEmpresas\Exceptions\ApiException $e) {
    http_response_code(400);
}
```

Failed deliveries are retried after 1 min, 5 min, 30 min, 2 h and 6 h.

## Sanctions, tenders, grants, changes feed and statistics

```php
// Sanctions lists (EU, UN, OFAC) for a company and its current directors (Business)
$r = $api->sanctions->screen(['cif' => 'A46103834']);
echo $r['summary']['result']; // clear | possible_match | match

// Open public tenders (Business); tender texts are in Spanish
$r = $api->tenders->list(['q' => 'limpieza', 'province' => 'Madrid', 'type' => 'services']);
$r = $api->tenders->forCompany('A82018474');

// Public grants since 2022 (Business)
$s = $api->companies->subsidies('B12345678', ['year' => 2025]);

// Changes feed: every company with a BORME event since a date, up to 30 days back (Business)
foreach ($api->companies->changesAll(['since' => '2026-10-19']) as $c) {
    echo $c['cif'], ' ', implode(',', $c['change_types']), PHP_EOL;
}

// BORME statistics (all plans)
$st = $api->stats(['metrics' => ['incorporations', 'dissolutions'], 'province' => 'MADRID', 'from' => '2025-01', 'to' => '2025-12']);
```

## Sandbox, usage and filter values

```php
// Sandbox: same key, mock data, no requests used (A15075062 found, B00000000 not found)
$sandbox = new ApiEmpresas('YOUR_API_KEY', 'https://spaincompanyapi.com/api/sandbox/v1');

$u   = $api->usage();   // free; not available in the sandbox
$cat = $api->catalog(); // free; valid values for provinces, size bands, webhook events, change types…
```

## Errors

```php
try {
    $api->companies->get('B00000000');
} catch (ApiException $e) {
    echo $e->getStatusCode(), ' ', $e->getApiCode(), ' ', $e->getMessage(); // e.g. 429 TOO_MANY_REQUESTS
    echo $e->getRequestId();                                  // give it to support
    echo $e->getRetryAfter();                                 // seconds, on rate-limit 429s
    echo $e->getRequiredPlan() . ' ' . $e->getCheckoutUrl();  // on plan 403s
}
```

A `429` with `TOO_MANY_REQUESTS` is solved by waiting and retrying; with `QUOTA_EXCEEDED` you need more credits or a higher plan. Errors and companies not found (404) do not use requests.

## Links

- Website and pricing: [spaincompanyapi.com](https://spaincompanyapi.com)
- API documentation: [spaincompanyapi.com/docs](https://spaincompanyapi.com/docs)
- How to verify a Spanish company: [guide](https://spaincompanyapi.com/how-to-verify-a-spanish-company)
- Postman collection: [Spain Company API](https://www.postman.com/papelo-amh-3796893/apiempresas/collection/maf0il5/spain-company-api)
- Support: soporte@apiempresas.es

## License

MIT

---

## En español

SDK oficial de [APIEmpresas.es](https://apiempresas.es) para PHP: datos de empresas españolas por CIF, verificación KYB (`companies->verify`), validación del NIF de clientes antes de facturar (`companies->verifyBatch`, hasta 1.000 por llamada), administradores, BORME, listas de sanciones, licitaciones, subvenciones, vigilancia y webhooks. Los nombres de los métodos y los campos son los mismos que en los ejemplos de arriba.

```bash
composer require apiempresas/apiempresas-php
```

```php
$api = new \ApiEmpresas\ApiEmpresas('TU_API_KEY'); // por defecto https://apiempresas.es/api/v1
$v = $api->companies->verify('A46103834', ['name' => 'Mercadona SA', 'vat' => true]);
echo $v['decision_hint']; // pass | review | fail
```

Documentación en español: [apiempresas.es/documentation](https://apiempresas.es/documentation). Plan gratis de 100 consultas sin tarjeta en [apiempresas.es](https://apiempresas.es/register?intent=api&plan=free&source=packagist_sdk).
