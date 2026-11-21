<?php

namespace ApiEmpresas\Tests;

use ApiEmpresas\ApiEmpresas;
use ApiEmpresas\Exceptions\ApiException;
use ApiEmpresas\Resources\Webhooks;
use PHPUnit\Framework\TestCase;

/** Cliente de prueba: guarda las peticiones y devuelve respuestas preparadas. */
class FakeClient extends ApiEmpresas
{
    public array $calls = [];
    public array $queue = [];

    public function request(string $method, string $endpoint, ?array $body = null): array
    {
        $this->calls[] = [$method, $endpoint, $body];
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next ?? ['success' => true, 'data' => []];
    }
}

class ResourcesTest extends TestCase
{
    private FakeClient $api;

    protected function setUp(): void
    {
        $this->api = new FakeClient('k');
    }

    public function testVersion()
    {
        $this->assertSame('1.6.1', ApiEmpresas::VERSION);
    }

    public function testVerifyBatch()
    {
        $this->api->queue[] = ['success' => true,
            'data' => [['input' => 'A46103834', 'cif' => 'A46103834', 'invoice_hint' => 'ok'], ['input' => 'A15075062', 'cif' => 'A15075062', 'ref' => 'c-2', 'invoice_hint' => 'revisar']],
            'meta' => ['requested' => 2, 'found' => 2, 'cost' => 2, 'truncated' => false, 'summary' => ['ok' => 1, 'revisar' => 1, 'no_facturar' => 0, 'not_processed' => 0]]];
        $r = $this->api->companies->verifyBatch(['A46103834', ['cif' => 'A15075062', 'name' => 'Inditex', 'ref' => 'c-2']]);
        $this->assertSame(['POST', '/companies/verify/batch', ['items' => [['cif' => 'A46103834'], ['cif' => 'A15075062', 'name' => 'Inditex', 'ref' => 'c-2']]]], $this->api->calls[0]);
        $this->assertSame('revisar', $r['data'][1]['invoice_hint']);
        $this->assertSame(2, $r['meta']['cost']);

        $this->api->companies->verifyBatch(['A46103834'], ['vat' => true]);
        $this->assertSame(['items' => [['cif' => 'A46103834']], 'vat' => true], $this->api->calls[1][2]);
    }

    public function testVerifyIban()
    {
        $this->api->queue[] = ['success' => true, 'data' => ['checks' => ['iban' => ['valid' => false, 'reason' => 'CHECKSUM']]]];
        $r = $this->api->companies->verify('A46103834', ['iban' => 'ES9121000418450200051333']);
        $this->assertSame(['GET', '/companies/verify?cif=A46103834&iban=ES9121000418450200051333', null], $this->api->calls[0]);
        $this->assertSame('CHECKSUM', $r['checks']['iban']['reason']);
    }

    public function testSubsidies()
    {
        $this->api->queue[] = ['success' => true, 'data' => ['summary' => ['total_grants' => 2]]];
        $r = $this->api->companies->subsidies('B1', ['year' => 2024, 'limit' => 5, 'otro' => 'x']);
        $this->assertSame(['GET', '/companies/subsidies?cif=B1&year=2024&limit=5', null], $this->api->calls[0]);
        $this->assertSame(2, $r['summary']['total_grants']);
    }

    public function testChangesAllFollowsCursor()
    {
        $this->api->queue[] = ['success' => true, 'data' => [['id' => '1'], ['id' => '2']], 'meta' => ['has_more' => true, 'next_cursor' => 'abc']];
        $this->api->queue[] = ['success' => true, 'data' => [['id' => '3']], 'meta' => ['has_more' => false, 'next_cursor' => null]];
        $ids = [];
        foreach ($this->api->companies->changesAll(['since' => '2026-10-19', 'types' => ['address', 'officers']]) as $c) {
            $ids[] = $c['id'];
        }
        $this->assertSame(['1', '2', '3'], $ids);
        $this->assertSame('/companies/changes?since=2026-10-19&types=address%2Cofficers', $this->api->calls[0][1]);
        $this->assertSame('/companies/changes?since=2026-10-19&types=address%2Cofficers&cursor=abc', $this->api->calls[1][1]);
    }

    public function testStats()
    {
        $this->api->queue[] = ['success' => true, 'data' => ['totals' => ['incorporations' => 10]], 'meta' => ['source' => 'borme']];
        $r = $this->api->stats(['metrics' => ['incorporations', 'dissolutions'], 'province' => 'MADRID', 'group' => 'year']);
        $this->assertSame('/stats?metrics=incorporations%2Cdissolutions&province=MADRID&group=year', $this->api->calls[0][1]);
        $this->assertSame(10, $r['totals']['incorporations']);
        $this->assertSame('borme', $r['meta']['source']);
    }

    public function testWebhookCompanyCreatedFilters()
    {
        $this->api->queue[] = ['success' => true, 'id' => 7, 'event' => 'company.created', 'secret' => 's', 'filters' => ['province' => ['MADRID']]];
        $opts = ['url' => 'https://x.example/h', 'event' => 'company.created', 'filters' => ['province' => ['Madrid']]];
        $r = $this->api->webhooks->create($opts);
        $this->assertSame(['POST', '/webhooks', $opts], $this->api->calls[0]);
        $this->assertSame(['MADRID'], $r['filters']['province']);
    }

    public function testVerifyBuildsQuery()
    {
        $this->api->queue[] = ['success' => true, 'data' => ['decision_hint' => 'pass']];
        $r = $this->api->companies->verify('A46103834', ['name' => 'Mercadona SA', 'person' => 'Juan Roig', 'vat' => true]);
        $this->assertSame(['GET', '/companies/verify?cif=A46103834&name=Mercadona+SA&person=Juan+Roig&vat=true', null], $this->api->calls[0]);
        $this->assertSame('pass', $r['decision_hint']);
    }

    public function testReconcile()
    {
        $this->api->queue[] = ['success' => true, 'data' => [['status' => 'match']], 'meta' => ['cost' => 1]];
        $r = $this->api->companies->reconcile(['Mercadona', ['name' => 'Seur', 'province' => 'Madrid']]);
        $this->assertSame(['POST', '/companies/reconcile', ['items' => [['name' => 'Mercadona'], ['name' => 'Seur', 'province' => 'Madrid']]]], $this->api->calls[0]);
        $this->assertSame(1, $r['meta']['cost']);
    }

    public function testFilterAndCount()
    {
        $this->api->queue = [
            ['success' => true, 'data' => ['total' => 3412], 'meta' => ['cost' => 0]],
            ['success' => true, 'data' => [['cif' => 'B1']], 'meta' => ['next_cursor' => 'abc']],
        ];
        $n = $this->api->companies->count(['cnae' => ['62', '4711'], 'province' => 'MADRID', 'has_phone' => true]);
        $r = $this->api->companies->filter(['cnae' => '62', 'size_band' => ['GT_1M'], 'limit' => 1]);
        $this->assertSame(3412, $n);
        $this->assertSame('/companies/filter?cnae=62%2C4711&province=MADRID&has_phone=true&count_only=true', $this->api->calls[0][1]);
        $this->assertSame('/companies/filter?cnae=62&size_band=GT_1M&limit=1', $this->api->calls[1][1]);
        $this->assertSame('abc', $r['meta']['next_cursor']);
    }

    public function testRadarNewFilters()
    {
        $this->api->companies->radar(['cnae' => '62', 'min_score' => 70, 'has_phone' => true]);
        $this->assertSame('/companies/radar?cnae=62&min_score=70&has_phone=true', $this->api->calls[0][1]);
    }

    public function testWatchlist()
    {
        $this->api->queue = [
            ['success' => true, 'data' => ['added' => ['A1']], 'meta' => ['watch_limit' => 100]],
            ['success' => true, 'data' => [], 'meta' => ['total' => 1]],
            ['success' => true, 'data' => ['cif' => 'A1', 'removed' => true]],
            ['success' => true, 'data' => [['type' => 'borme_act']], 'meta' => ['total' => 1]],
        ];
        $add = $this->api->watchlist->add('A1');
        $this->api->watchlist->list(['page' => 2]);
        $rm = $this->api->watchlist->remove('A1');
        $ev = $this->api->watchlist->events(['since' => '2026-09-01', 'types' => ['borme_act', 'status_change']]);

        $this->assertSame(['POST', '/watchlist', ['cifs' => ['A1']]], $this->api->calls[0]);
        $this->assertSame(100, $add['meta']['watch_limit']);
        $this->assertSame('/watchlist?page=2', $this->api->calls[1][1]);
        $this->assertSame(['DELETE', '/watchlist/A1', null], $this->api->calls[2]);
        $this->assertTrue($rm['removed']);
        $this->assertSame('/watchlist/events?since=2026-09-01&types=borme_act%2Cstatus_change', $this->api->calls[3][1]);
        $this->assertSame('borme_act', $ev['data'][0]['type']);
    }

    public function testWebhooks()
    {
        $this->api->queue = [
            ['success' => true, 'id' => 7, 'event' => 'watchlist.*', 'secret' => 's3'],
            ['success' => true, 'data' => [['id' => 7]]],
            ['success' => true, 'message' => 'Webhook eliminado'],
            new ApiException('x', 502, null, ['success' => false, 'data' => ['delivered' => false, 'http_status' => 500]]),
        ];
        $c = $this->api->webhooks->create(['url' => 'https://x.example/h', 'event' => 'watchlist']);
        $this->assertSame(7, $c['id']);
        $this->assertSame('s3', $c['secret']);
        $this->assertCount(1, $this->api->webhooks->list());
        $this->assertTrue($this->api->webhooks->remove(7));
        $t = $this->api->webhooks->test(7);
        $this->assertSame(['POST', '/webhooks/7/test', null], $this->api->calls[3]);
        $this->assertFalse($t['delivered']);
    }

    public function testSignature()
    {
        $secret = 'whsec_test';
        $body = '{"id":"u1","event":"company.borme_act","data":{"cif":"A1"}}';
        $t = 1790000000;
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        $this->assertTrue(Webhooks::verifySignature($body, $sig, $secret, 300, $t + 10));
        $this->assertFalse(Webhooks::verifySignature($body . ' ', $sig, $secret, 300, $t));
        $this->assertFalse(Webhooks::verifySignature($body, $sig, 'otro', 300, $t));
        $this->assertFalse(Webhooks::verifySignature($body, $sig, $secret, 300, $t + 301));
        $this->assertTrue(Webhooks::verifySignature($body, $sig, $secret, 0, $t + 99999));
        $this->assertFalse(Webhooks::verifySignature($body, 'basura', $secret));
        $this->assertFalse(Webhooks::verifySignature($body, null, $secret));

        $now = time();
        $ok = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret);
        $this->assertSame('company.borme_act', Webhooks::constructEvent($body, $ok, $secret)['event']);
        $this->expectException(ApiException::class);
        Webhooks::constructEvent($body, $sig, $secret);
    }

    public function testCatalog()
    {
        $this->api->queue[] = ['success' => true, 'data' => ['province' => ['Madrid']], 'meta' => ['cost' => 0]];
        $c = $this->api->catalog();
        $this->assertSame(['GET', '/catalog', null], $this->api->calls[0]);
        $this->assertSame(['Madrid'], $c['province']);
    }

    public function testWebhookDeliveries()
    {
        $this->api->queue[] = ['success' => true, 'data' => [['delivery_id' => 'd1']], 'meta' => ['total' => 1, 'has_more' => false]];
        $r = $this->api->webhooks->deliveries(7, 'dead', null, 10);
        $this->assertSame('/webhooks/7/deliveries?status=dead&limit=10', $this->api->calls[0][1]);
        $this->assertSame('d1', $r['data'][0]['delivery_id']);
        $this->assertFalse($r['meta']['has_more']);
        $this->api->webhooks->deliveries(7);
        $this->assertSame('/webhooks/7/deliveries', $this->api->calls[1][1]);
    }
}
