<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\StatusBusca;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Os carrinhos do mapa do passageiro: motoristas livres perto dele. */
class MotoristasProximosController extends Controller
{
    private const LIMITE = 25;

    private const RAIO_PADRAO_KM = 3.0;

    private const RAIO_MAXIMO_KM = 8.0;

    /**
     * Só quem está disponível e com sinal recente. Quem está em corrida tem
     * `disponivel = false` (o aceite desliga), então some do mapa. Devolve só
     * posição e tipo do veículo, sem identificar o motorista.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_if(PerfilLogado::passageiro($request) === null, 403, 'Só o passageiro vê motoristas no mapa.');

        $dados = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'raio_km' => 'nullable|numeric|min:0.3|max:'.self::RAIO_MAXIMO_KM,
        ]);

        $latitude = (float) $dados['latitude'];
        $longitude = (float) $dados['longitude'];
        $raio = (float) ($dados['raio_km'] ?? self::RAIO_PADRAO_KM);

        $graus = $raio / 111.0;
        $grausLongitude = $graus / max(cos(deg2rad($latitude)), 0.01);
        $limite = max((int) config('precificacao.motorista_online_expira_segundos', 90), 30);

        $motoristas = StatusBusca::query()
            ->leftJoin('veiculos', 'veiculos.id', '=', 'status_buscas.veiculo_id')
            ->where('status_buscas.disponivel', true)
            ->where('status_buscas.visto_em', '>=', now()->subSeconds($limite))
            ->whereBetween('status_buscas.latitude', [$latitude - $graus, $latitude + $graus])
            ->whereBetween('status_buscas.longitude', [$longitude - $grausLongitude, $longitude + $grausLongitude])
            ->get(['status_buscas.id', 'status_buscas.latitude', 'status_buscas.longitude', 'veiculos.categoria'])
            ->map(function ($linha) use ($latitude, $longitude) {
                $dLat = ($linha->latitude - $latitude) * 111.0;
                $dLon = ($linha->longitude - $longitude) * 111.0 * cos(deg2rad($latitude));

                return [
                    'id' => (int) $linha->id,
                    'latitude' => (float) $linha->latitude,
                    'longitude' => (float) $linha->longitude,
                    'veiculo' => $linha->categoria === 'moto' ? 'moto' : 'carro',
                    'distancia_km' => sqrt($dLat ** 2 + $dLon ** 2),
                ];
            })
            ->filter(fn (array $m) => $m['distancia_km'] <= $raio)
            ->sortBy('distancia_km')
            ->take(self::LIMITE)
            ->map(fn (array $m) => array_diff_key($m, ['distancia_km' => 0]))
            ->values();

        return response()->json(['motoristas' => $motoristas]);
    }
}
