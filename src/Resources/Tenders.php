<?php

namespace ApiEmpresas\Resources;

use ApiEmpresas\ApiEmpresas;

/**
 * (Business) Licitaciones públicas abiertas: Plataforma de Contratación del Sector Público y
 * plataformas autonómicas agregadas. 1 consulta por llamada. En Free y Pro, ApiException 403
 * PLAN_RESTRICTION con el número de licitaciones y 3 de muestra.
 */
class Tenders
{
    private ApiEmpresas $client;

    public function __construct(ApiEmpresas $client)
    {
        $this->client = $client;
    }

    /**
     * Busca licitaciones. Opciones: q, cpv (texto o array), province, type (supplies, services,
     * works), procedure, min_amount, max_amount, deadline_from, deadline_to, published_from, cif,
     * status (open, prior_notice, all), sort (deadline, published, amount, relevance), page, limit.
     * Devuelve ['data' => ['tenders' => [...], 'pagination' => [...], 'company' => ...], 'meta' => [...]].
     */
    public function list(array $options = []): array
    {
        $query = [];
        foreach ($options as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $query[$k] = is_array($v) ? implode(',', $v) : $v;
        }
        $qs = $query ? '?' . http_build_query($query) : '';
        $response = $this->client->request('GET', '/tenders' . $qs);
        return ['data' => $response['data'] ?? [], 'meta' => $response['meta'] ?? []];
    }

    /** Atajo: licitaciones abiertas parecidas a lo que ya ha ganado una empresa. */
    public function forCompany(string $cif, array $options = []): array
    {
        return $this->list(['cif' => $cif] + $options);
    }
}
