<?php

namespace App\ApiClients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webmozart\Assert\Assert;

class MoySklad
{

    private mixed $token;
    private mixed $baseUrl;

    public function __construct()
    {
        $this->token = config('services.moysklad.token');

        Assert::notEmpty($this->token, 'envirnoment variable MOYSKLAD_TOKEN not defined');

        $this->baseUrl = config('services.moysklad.base_url');
    }

    public function reportStockByStore(
        ?int $limit = null,
        ?int $offset = null,
        ?string $store = null,
        ?string $filterField = null,
        ?string $filterValues = null,
    ): ?array {
        $params = [];

        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        if ($store !== null) {
            $params['store'] = $store;
        }

        if ($filterField !== null) {
            Assert::notNull($filterValues);
            $params['filter'] = sprintf("%s=%s/entity/product/%s", $filterField, $this->baseUrl, $filterValues);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get($this->baseUrl . '/report/stock/bystore', $params);

        return $response->successful() ? $response->json() : null;
    }

}
