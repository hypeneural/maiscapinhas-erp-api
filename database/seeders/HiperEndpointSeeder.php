<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HiperEndpoint;
use Illuminate\Database\Seeder;

class HiperEndpointSeeder extends Seeder
{
    public function run(): void
    {
        $endpoints = [
            [
                'key' => 'usuarios.perfis',
                'method' => 'GET',
                'path' => '/usuarios/perfis',
                'headers' => null,
                'query_template' => null,
                'body_template' => null,
            ],
            [
                'key' => 'entidades.listagem.funcionarios',
                'method' => 'GET',
                'path' => '/entidades/listagem',
                'headers' => null,
                'query_template' => [
                    'orderByDesc' => 'false',
                    'orderBy' => '',
                    'page' => '1',
                    'situacao' => '0',
                    'ehCliente' => 'false',
                    'ehFornecedor' => 'false',
                    'ehFuncionario' => 'true',
                    'ehParceiroIndicador' => 'false',
                    'ehRepresentante' => 'false',
                    'ehTransportadora' => 'false',
                ],
                'body_template' => null,
            ],
            [
                'key' => 'operacoes.detalhes',
                'method' => 'GET',
                'path' => '/operacoes/{id}/detalhes',
                'headers' => null,
                'query_template' => null,
                'body_template' => null,
            ],
            [
                'key' => 'operacoes.listar',
                'method' => 'POST',
                'path' => '/operacoes/ListarOperacoes',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'query_template' => null,
                'body_template' => [
                    'filtro' => [
                        'ApenasOperacoesMaquininhaStone' => false,
                        'CodigoPedidoVenda' => null,
                        'EntidadeId' => null,
                        'EntidadeNome' => null,
                        'LojaId' => null,
                        'Lojas' => [],
                    ],
                ],
            ],
            [
                'key' => 'operacoes.detalhes.fechamento',
                'method' => 'GET',
                'path' => '/operacoes/fechamento-de-caixa/api/caixa/{id}',
                'headers' => null,
                'query_template' => null,
                'body_template' => null,
            ],
        ];

        foreach ($endpoints as $ep) {
            HiperEndpoint::updateOrCreate(
                ['key' => $ep['key']],
                $ep
            );
        }
    }
}
