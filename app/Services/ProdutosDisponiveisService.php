<?php

namespace App\Services;

use App\Models\Cidade;
use App\Models\ProdutoRegiao;
use App\Models\ProdutosCorrida;
use Illuminate\Support\Collection;

/**
 * Quais produtos de corrida o passageiro vê ("Escolha uma ou mais categorias").
 *
 * Mesma hierarquia das tarifas: a lista da cidade, se ela tem uma, vale sozinha;
 * senão a do estado dela; senão todos os produtos. Em qualquer nível, produto
 * desligado (`ativo = false`) não aparece.
 */
class ProdutosDisponiveisService
{
    /**
     * @return array{origem: 'cidade'|'estado'|'geral', ids: list<int>}
     */
    public function listaDaRegiao(?int $cidadeId, ?int $estadoId = null): array
    {
        $ativos = ProdutosCorrida::query()->where('ativo', true)->pluck('id');

        if ($cidadeId !== null) {
            $propria = $this->idsDaLista('cidade_id', $cidadeId);

            if ($propria->isNotEmpty()) {
                return ['origem' => 'cidade', 'ids' => $ativos->intersect($propria)->values()->all()];
            }

            $estadoId ??= Cidade::query()->whereKey($cidadeId)->value('uf_id');
        }

        if ($estadoId !== null) {
            $doEstado = $this->idsDaLista('estado_id', $estadoId);

            if ($doEstado->isNotEmpty()) {
                return ['origem' => 'estado', 'ids' => $ativos->intersect($doEstado)->values()->all()];
            }
        }

        return ['origem' => 'geral', 'ids' => $ativos->values()->all()];
    }

    public function disponivelNaCidade(string $codigo, ?int $cidadeId): bool
    {
        $produto = ProdutosCorrida::query()->where('codigo', $codigo)->first(['id']);

        // produto que ninguém cadastrou não é bloqueado por esta lista
        return $produto === null || in_array($produto->id, $this->listaDaRegiao($cidadeId)['ids'], true);
    }

    /**
     * @return Collection<int, int>
     */
    private function idsDaLista(string $coluna, int $regiaoId): Collection
    {
        return ProdutoRegiao::query()->where($coluna, $regiaoId)->pluck('produto_id');
    }
}
