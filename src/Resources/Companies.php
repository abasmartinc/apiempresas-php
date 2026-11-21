<?php

namespace ApiEmpresas\Resources;

use ApiEmpresas\ApiEmpresas;

class Companies
{
    private ApiEmpresas $client;

    public function __construct(ApiEmpresas $client)
    {
        $this->client = $client;
    }

    /**
     * Obtiene los datos básicos de una empresa por su CIF.
     */
    public function get(string $cif, array $options = []): array
    {
        $query = ['cif' => $cif];
        if (!empty($options['admin'])) {
            $query['admin'] = 'true';
        }
        $params = http_build_query($query);
        $response = $this->client->request('GET', "/companies?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Pro) Verificación KYB en una llamada: estado, nombre, administrador, VIES y alertas,
     * con decision_hint pass / review / fail. Coste: 2 consultas.
     *
     * @param array $options name (razón social dada), person (quien firma), vat (bool, VIES),
     *                       iban (desde 1.5.0: comprueba que está bien formado; no dice de quién es la cuenta)
     */
    public function verify(string $cif, array $options = []): array
    {
        $query = ['cif' => $cif];
        if (!empty($options['name'])) {
            $query['name'] = (string) $options['name'];
        }
        if (!empty($options['person'])) {
            $query['person'] = (string) $options['person'];
        }
        if (!empty($options['vat'])) {
            $query['vat'] = 'true';
        }
        if (!empty($options['iban'])) {
            $query['iban'] = (string) $options['iban'];
        }
        $params = http_build_query($query);
        $response = $this->client->request('GET', "/companies/verify?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Pro, Business o saldo; desde 1.6.0) Valida en lote el NIF de los receptores de tus facturas
     * (Verifactu, factura electrónica): hasta 1.000 por petición. Por cada NIF, en el mismo orden:
     * valid_format, exists, legal_name, address, status_code, name_check (si das 'name') e
     * invoice_hint ('ok', 'revisar' o 'no_facturar') con sus motivos en flags.
     * Devuelve ['data' => [...], 'meta' => [...]] (meta: requested, found, cost, truncated, summary).
     * Cobro: 1 consulta por sociedad encontrada; NIF inválidos, de personas o que no constan, gratis.
     * No sustituye la comprobación contra el censo de la AEAT.
     *
     * @param array $items Cada elemento: un NIF ('A46103834') o ['cif' => ..., 'name' => ..., 'ref' => ...].
     *                     'name' es la razón social que tienes guardada; 'ref', tu identificador (vuelve tal cual).
     * @param array $options vat (bool): comprobar también el NIF-IVA en VIES (solo lotes de hasta 20).
     */
    public function verifyBatch(array $items, array $options = []): array
    {
        $norm = array_map(static fn ($i) => is_array($i) ? $i : ['cif' => (string) $i], array_values($items));
        $body = ['items' => $norm];
        if (!empty($options['vat'])) {
            $body['vat'] = true;
        }
        $response = $this->client->request('POST', '/companies/verify/batch', $body);
        return ['data' => $response['data'] ?? [], 'meta' => $response['meta'] ?? []];
    }

    /**
     * (Pro) Nombre a CIF, hasta 100 por petición: ['data' => [...], 'meta' => [...]].
     * Cada elemento puede ser un texto o ['name' => ..., 'province' => ...].
     * 1 consulta por cada "match"; ambiguous y no_match no se cobran.
     */
    public function reconcile(array $items): array
    {
        $norm = array_map(static fn ($i) => is_string($i) ? ['name' => $i] : $i, array_values($items));
        $response = $this->client->request('POST', '/companies/reconcile', ['items' => $norm]);
        return ['data' => $response['data'] ?? [], 'meta' => $response['meta'] ?? []];
    }

    /**
     * Cuántas empresas encajan con los filtros (cnae, province, municipality, status,
     * founded_from, founded_to, has_phone, size_band, min_accounts_year). Gratis en todos los planes.
     */
    public function count(array $filters): int
    {
        $query = self::filterQuery($filters);
        $query['count_only'] = 'true';
        $response = $this->client->request('GET', '/companies/filter?' . http_build_query($query));
        return (int) ($response['data']['total'] ?? 0);
    }

    /**
     * (Business) Empresas de un segmento: ['data' => [...], 'meta' => [...]]. 5 consultas por
     * fila devuelta. Para la página siguiente, pasa meta['next_cursor'] como 'cursor'.
     */
    public function filter(array $filters): array
    {
        $response = $this->client->request('GET', '/companies/filter?' . http_build_query(self::filterQuery($filters)));
        return ['data' => $response['data'] ?? [], 'meta' => $response['meta'] ?? []];
    }

    private static function filterQuery(array $filters): array
    {
        $q = [];
        foreach ($filters as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = implode(',', $v);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $q[$k] = $v;
        }
        return $q;
    }

    /**
     * Busca empresas por nombre o razón social.
     */
    public function search(string $q): array
    {
        $params = http_build_query(['q' => $q]);
        $response = $this->client->request('GET', "/companies/search?$params");
        return $response['data'] ?? [];
    }

    /**
     * Búsqueda con varios resultados (multiple=true). Devuelve ['data' => [...], 'meta' => [...]];
     * para la página siguiente, pasa meta['next_cursor'] como 'cursor'.
     * Opciones: limit, page, cursor.
     */
    public function searchMultiple(string $q, array $options = []): array
    {
        $query = ['q' => $q, 'multiple' => 'true'];
        foreach (['limit', 'page', 'cursor'] as $k) {
            if (isset($options[$k])) {
                $query[$k] = $options[$k];
            }
        }
        $params = http_build_query($query);
        $response = $this->client->request('GET', "/companies/search?$params");
        return [
            'data' => $response['data'] ?? [],
            'meta' => $response['meta'] ?? [],
        ];
    }

    /**
     * (Pro y Business) Hasta 100 CIFs en una sola petición. Con $admin = true incluye
     * los administradores vigentes.
     */
    public function batch(array $cifs, bool $admin = false): array
    {
        $body = ['cifs' => $cifs];
        if ($admin) {
            $body['admin'] = true;
        }
        $response = $this->client->request('POST', '/companies/batch', $body);
        return [
            'meta' => $response['meta'] ?? [],
            'data' => $response['data'] ?? []
        ];
    }

    /**
     * (Pro) Obtiene el Scoring Comercial de una empresa.
     */
    public function score(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/score?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Pro) Obtiene el historial de actos del BORME de una empresa.
     */
    public function borme(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/borme?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Pro) Obtiene las señales societarias recientes de una empresa.
     */
    public function signals(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/signals?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Business; en Pro, vista previa con profile y conversion_probability) Insights IA de una empresa.
     */
    public function insights(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/insights?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Business) Preparación del contacto comercial generada por IA.
     */
    public function contactPrep(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/contact-prep?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Todos los planes: Free 10 resultados con nombre y CIF ocultos, Pro 100, Business 1.000)
     * Empresas recién constituidas. Devuelve la lista (data); el resumen (meta) no se incluye.
     */
    public function radar($cifOrOptions = []): array
    {
        // Compatibilidad: antes se pasaba un CIF, que el Radar no usa. Ahora se pasan
        // los filtros: province, priority, range, cnae, min_score, main_act_type, has_phone.
        $query = is_array($cifOrOptions)
            ? array_filter($cifOrOptions, static fn ($v) => $v !== null && $v !== '')
            : ['cif' => (string) $cifOrOptions];
        foreach ($query as $k => $v) {
            if (is_bool($v)) {
                $query[$k] = $v ? 'true' : 'false';
            }
        }
        $params = http_build_query($query);
        $response = $this->client->request('GET', "/companies/radar?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Business) Realiza un match avanzado con los datos de una empresa.
     */
    public function match(string $cif, ?string $sellerSector = null): array
    {
        // seller_sector es obligatorio en la API: sin él responde 400.
        $query = ['cif' => $cif];
        if ($sellerSector !== null && $sellerSector !== '') {
            $query['seller_sector'] = $sellerSector;
        }
        $params = http_build_query($query);
        $response = $this->client->request('GET', "/companies/match?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Pro y Business) Red societaria de una empresa a través de sus administradores.
     */
    public function network(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/network?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Business) Obtiene los contratos públicos y licitaciones adjudicadas a una empresa.
     */
    public function contracts(string $cif, int $page = 1, int $limit = 20): array
    {
        $params = http_build_query([
            'cif' => $cif,
            'page' => $page,
            'limit' => $limit,
        ]);
        $response = $this->client->request('GET', "/companies/contracts?$params");
        return $response['data'] ?? [];
    }

    /**
     * (Business, desde 1.5.0) Subvenciones y ayudas públicas de la BDNS desde 2022 (Kit Digital incluido):
     * cif, company_name, summary (by_year, by_instrument, top_calls), grants y pagination. 1 consulta.
     *
     * @param array $options year, page, limit (1-100)
     */
    public function subsidies(string $cif, array $options = []): array
    {
        $query = self::filterQuery(['cif' => $cif] + array_intersect_key($options, array_flip(['year', 'page', 'limit'])));
        $response = $this->client->request('GET', '/companies/subsidies?' . http_build_query($query));
        return $response['data'] ?? [];
    }

    /**
     * (Business, desde 1.5.0) Feed de cambios: sociedades con un acto en el BORME desde una fecha
     * (hasta 30 días atrás): ['data' => [...], 'meta' => [...]]. 1 consulta por página; para la
     * siguiente, pasa meta['next_cursor'] como 'cursor'. Solo sociedades con CIF: las recién
     * constituidas llegan por el webhook company.created.
     *
     * @param array $options since, until (AAAA-MM-DD), types, province, cnae, limit (1-1000), cursor
     */
    public function changes(array $options = []): array
    {
        $q = http_build_query(self::filterQuery($options));
        $response = $this->client->request('GET', '/companies/changes' . ($q !== '' ? "?$q" : ''));
        return ['data' => $response['data'] ?? [], 'meta' => $response['meta'] ?? []];
    }

    /**
     * (Business, desde 1.5.0) Recorre todas las páginas del feed de cambios, cambio a cambio
     * (1 consulta por página): foreach ($api->companies->changesAll(['since' => '2026-10-19']) as $c) { ... }
     */
    public function changesAll(array $options = []): \Generator
    {
        do {
            $page = $this->changes($options);
            foreach ($page['data'] as $c) {
                yield $c;
            }
            $options['cursor'] = $page['meta']['next_cursor'] ?? null;
        } while (!empty($options['cursor']));
    }

    /**
     * (Business) Obtiene el perfil de riesgo corporativo y solvencia de una empresa.
     */
    public function riskProfile(string $cif): array
    {
        $params = http_build_query(['cif' => $cif]);
        $response = $this->client->request('GET', "/companies/risk-profile?$params");
        return $response['data'] ?? [];
    }
}

