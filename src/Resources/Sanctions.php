<?php

namespace ApiEmpresas\Resources;

use ApiEmpresas\ApiEmpresas;

/**
 * (Business) Listas de sanciones de la UE, la ONU y OFAC (EE. UU.). 1 consulta por llamada.
 * Cada coincidencia trae su grado (strong o possible): confírmala con la fecha de nacimiento,
 * el país y los documentos antes de decidir.
 */
class Sanctions
{
    private ApiEmpresas $client;

    public function __construct(ApiEmpresas $client)
    {
        $this->client = $client;
    }

    /**
     * Comprueba una empresa (['cif' => ...], con sus administradores vigentes) o un nombre
     * (['name' => ..., 'type' => 'person'|'entity'|'any']). Con cif, 'directors' => false
     * comprueba solo la empresa. Devuelve el bloque data de la respuesta.
     */
    public function screen(array $options): array
    {
        if (empty($options['cif']) && empty($options['name'])) {
            throw new \InvalidArgumentException("Indica 'cif' o 'name'.");
        }
        $query = [];
        foreach (['cif', 'name', 'type'] as $k) {
            if (!empty($options[$k])) {
                $query[$k] = $options[$k];
            }
        }
        if (array_key_exists('directors', $options) && $options['directors'] === false) {
            $query['directors'] = 'false';
        }
        $response = $this->client->request('GET', '/sanctions/screen?' . http_build_query($query));
        return $response['data'] ?? [];
    }
}
