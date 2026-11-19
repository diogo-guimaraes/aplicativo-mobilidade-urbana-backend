<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\CompartilhamentoVeiculoService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Quem está online agora. Online é quem manda a posição há pouco (o mesmo
 * prazo do despacho): livre, esperando chamada, ou em corrida.
 */
class MotoristasOnlineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'situacao' => ['nullable', Rule::in(['disponiveis', 'em_corrida', 'todos'])],
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $situacao = $dados['situacao'] ?? 'disponiveis';
        $porPagina = (int) ($dados['per_page'] ?? 25);

        $emCorrida = fn (Builder $q) => $q->select(DB::raw(1))
            ->from('corridas')
            ->whereColumn('corridas.motorista_id', 'status_buscas.motorista_id')
            ->whereIn('corridas.status_corrida', CompartilhamentoVeiculoService::CORRIDAS_ATIVAS);

        $recentes = fn () => DB::table('status_buscas')
            ->where('status_buscas.visto_em', '>=', now()->subSeconds($this->prazoDoSinal()));

        $resumo = [
            'disponiveis' => (clone $recentes())->where('status_buscas.disponivel', true)->count(),
            'em_corrida' => (clone $recentes())->whereExists($emCorrida)->count(),
        ];

        $consulta = $recentes()
            ->join('motoristas', 'motoristas.id', '=', 'status_buscas.motorista_id')
            ->leftJoin('veiculos', 'veiculos.id', '=', 'status_buscas.veiculo_id')
            ->leftJoin('cidades', 'cidades.id', '=', 'motoristas.cidade_id')
            ->whereNull('motoristas.deleted_at');

        match ($situacao) {
            'disponiveis' => $consulta->where('status_buscas.disponivel', true),
            'em_corrida' => $consulta->whereExists($emCorrida),
            default => $consulta->where(fn (Builder $q) => $q
                ->where('status_buscas.disponivel', true)
                ->orWhereExists($emCorrida)),
        };

        if (($busca = trim($dados['search'] ?? '')) !== '') {
            $consulta->where(function (Builder $q) use ($busca) {
                $q->where('motoristas.name', 'like', '%'.$busca.'%')
                    ->orWhere('motoristas.telefone', 'like', '%'.$busca.'%')
                    ->orWhere('veiculos.placa', 'like', '%'.$busca.'%');
                if (ctype_digit($busca) && strlen($busca) < 11) {
                    $q->orWhere('motoristas.id', (int) $busca);
                }
            });
        }

        $pagina = $consulta
            ->selectRaw('status_buscas.motorista_id as id, motoristas.name, motoristas.telefone, cidades.nome as cidade')
            ->addSelect([
                'status_buscas.disponivel', 'status_buscas.latitude', 'status_buscas.longitude', 'status_buscas.visto_em',
                'veiculos.marca', 'veiculos.modelo', 'veiculos.placa', 'veiculos.cor', 'veiculos.categoria',
            ])
            ->selectSub($emCorrida, 'em_corrida')
            ->orderByDesc('status_buscas.visto_em')
            ->orderBy('motoristas.id')
            ->paginate($porPagina);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn ($m) => [
                'id' => (int) $m->id,
                'nome' => $m->name,
                'telefone' => $m->telefone,
                'cidade' => $m->cidade,
                'situacao' => $m->em_corrida ? 'em_corrida' : 'disponivel',
                'latitude' => $m->latitude !== null ? (float) $m->latitude : null,
                'longitude' => $m->longitude !== null ? (float) $m->longitude : null,
                'visto_em' => $m->visto_em !== null ? Carbon::parse($m->visto_em)->toIso8601String() : null,
                'veiculo' => $m->placa === null ? null : [
                    'descricao' => trim($m->marca.' '.$m->modelo.($m->cor ? ' · '.$m->cor : '')),
                    'placa' => $m->placa,
                    'categoria' => $m->categoria,
                ],
            ])->values(),
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'ultima_pagina' => $pagina->lastPage(),
            'resumo' => $resumo,
            'prazo_sinal_segundos' => $this->prazoDoSinal(),
        ]);
    }

    private function prazoDoSinal(): int
    {
        return max((int) config('precificacao.motorista_online_expira_segundos', 90), 30);
    }
}
