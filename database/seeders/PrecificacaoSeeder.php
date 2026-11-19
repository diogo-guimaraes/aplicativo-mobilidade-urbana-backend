<?php

namespace Database\Seeders;

use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use App\Support\IconeDoProduto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrecificacaoSeeder extends Seeder
{
    /**
     * Os produtos (modalidades) de corrida, na ordem em que a gestão os lista.
     * O Expresso é prioritário: vai primeiro aos motoristas mais perto (raio
     * menor) e aparece antes das outras chamadas. A Compartilhada não tem
     * tarifa própria: usa a do Pop (ver config/compartilhada.php) e só entra
     * aqui para a gestão poder ligá-la ou desligá-la por região. Os Envios têm
     * fluxo próprio e não aparecem na lista de categorias de corrida.
     *
     * `icone`: emoji. Sem ele o app usa a arte que já tem para a categoria.
     *
     * @var array<string, array{nome: string, estrategia: string, grupo: string, tipo_veiculo: string, requisito_veiculo: string|null, descricao: string|null, icone?: string, prioritario?: bool}>
     */
    private const PRODUTOS = [
        'pop' => ['nome' => '69 Pop', 'estrategia' => 'normal', 'grupo' => 'normal', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => null],
        'negocia' => ['nome' => 'Negocia', 'estrategia' => 'negociada', 'grupo' => 'negocia', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => 'Negocie e escolha'],
        'carona' => ['nome' => 'Carona', 'estrategia' => 'normal', 'grupo' => 'carona', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => 'Mais barata, para quem não tem pressa', 'icone' => '🙋'],
        'compartilhada' => ['nome' => 'Compartilhada', 'estrategia' => 'normal', 'grupo' => 'compartilhada', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => 'Divida o carro e pague menos', 'icone' => '👥'],
        'moto' => ['nome' => 'Moto', 'estrategia' => 'normal', 'grupo' => 'normal', 'tipo_veiculo' => 'moto', 'requisito_veiculo' => null, 'descricao' => null],
        'expresso' => ['nome' => 'Expresso', 'estrategia' => 'normal', 'grupo' => 'expresso', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => 'Chega mais rápido', 'prioritario' => true],
        'moto_negocia' => ['nome' => 'Negocia moto', 'estrategia' => 'negociada', 'grupo' => 'negocia', 'tipo_veiculo' => 'moto', 'requisito_veiculo' => null, 'descricao' => 'Negocie e escolha'],
        'taxi' => ['nome' => 'Táxi', 'estrategia' => 'taxi', 'grupo' => 'taxi', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => 'taxi', 'descricao' => null],
        'envio_moto' => ['nome' => 'Envio moto', 'estrategia' => 'entrega', 'grupo' => 'entrega', 'tipo_veiculo' => 'moto', 'requisito_veiculo' => null, 'descricao' => 'Entrega rápida de pequenos volumes', 'icone' => '📦'],
        'envio_carro' => ['nome' => 'Envio carro', 'estrategia' => 'entrega', 'grupo' => 'entrega', 'tipo_veiculo' => 'carro', 'requisito_veiculo' => null, 'descricao' => 'Entrega de volumes maiores', 'icone' => '🚚'],
    ];

    /**
     * @var array<string, array{base: float, km: float, minuto: float, espera: float, minimo: float}>
     */
    private const TARIFAS = [
        'pop' => ['base' => 2.00, 'km' => 1.55, 'minuto' => 0.28, 'espera' => 0.30, 'minimo' => 8.00],
        'negocia' => ['base' => 0.00, 'km' => 1.55, 'minuto' => 0.28, 'espera' => 0.30, 'minimo' => 8.00],
        'carona' => ['base' => 1.50, 'km' => 1.25, 'minuto' => 0.22, 'espera' => 0.25, 'minimo' => 6.50],
        'moto' => ['base' => 1.00, 'km' => 0.90, 'minuto' => 0.16, 'espera' => 0.20, 'minimo' => 5.00],
        'expresso' => ['base' => 2.50, 'km' => 1.85, 'minuto' => 0.33, 'espera' => 0.30, 'minimo' => 9.50],
        'moto_negocia' => ['base' => 0.00, 'km' => 0.90, 'minuto' => 0.16, 'espera' => 0.20, 'minimo' => 5.00],
        'taxi' => ['base' => 3.00, 'km' => 1.80, 'minuto' => 0.32, 'espera' => 0.35, 'minimo' => 10.00],
        'envio_moto' => ['base' => 2.00, 'km' => 1.00, 'minuto' => 0.18, 'espera' => 0.20, 'minimo' => 7.00],
        'envio_carro' => ['base' => 3.50, 'km' => 1.70, 'minuto' => 0.30, 'espera' => 0.30, 'minimo' => 12.00],
    ];

    private const TAXA_PLATAFORMA_PERCENTUAL = 6.00;

    private const RAIO_BUSCA_MOTORISTA_KM = 5;

    // o Expresso começa só com quem está bem perto
    private const RAIO_BUSCA_PRIORITARIO_KM = 2;

    /**
     * `preservarExistentes` (massa de demonstração): só cria o que falta, sem
     * mexer no que a gestão já editou e sem aposentar produtos antigos.
     */
    public function run(bool $preservarExistentes = false): void
    {
        $metodo = $preservarExistentes ? 'firstOrCreate' : 'updateOrCreate';
        $ordem = 0;

        foreach (self::PRODUTOS as $codigo => $dados) {
            $ordem++;
            $produto = ProdutosCorrida::$metodo(
                ['codigo' => $codigo],
                [
                    'nome' => $dados['nome'],
                    'estrategia_precificacao' => $dados['estrategia'],
                    'ordem' => $ordem,
                    'grupo' => $dados['grupo'],
                    'tipo_veiculo' => $dados['tipo_veiculo'],
                    'requisito_veiculo' => $dados['requisito_veiculo'],
                    'prioritario' => $dados['prioritario'] ?? false,
                    'ativo' => true,
                    'descricao' => $dados['descricao'],
                    'icone_tipo' => isset($dados['icone']) ? 'emoji' : null,
                    'icone_valor' => $dados['icone'] ?? null,
                    'icone_path' => null,
                ]
            );

            if (! isset(self::TARIFAS[$codigo])) {
                continue;
            }

            $valores = self::TARIFAS[$codigo];

            Tarifa::$metodo(
                [
                    'produto_id' => $produto->id,
                    'cidade_id' => null,
                    'estado_id' => null,
                ],
                [
                    'horario_inicio' => '00:00:00',
                    'horario_fim' => '23:59:59',
                    'dias_semana' => json_encode([1, 2, 3, 4, 5, 6, 7]),
                    'vira_dia' => false,
                    'valor_minimo_corrida' => $valores['minimo'],
                    'tarifa_base' => $valores['base'],
                    'valor_por_km' => $valores['km'],
                    'valor_por_minuto' => $valores['minuto'],
                    'valor_por_minuto_espera' => $valores['espera'],
                    'taxa_plataforma_percentual' => self::TAXA_PLATAFORMA_PERCENTUAL,
                    'raio_busca_motorista_km' => ($dados['prioritario'] ?? false)
                        ? self::RAIO_BUSCA_PRIORITARIO_KM
                        : self::RAIO_BUSCA_MOTORISTA_KM,
                    'ativo' => true,
                ]
            );
        }

        if (! $preservarExistentes) {
            $this->aposentarOutros(array_keys(self::PRODUTOS));
        }
    }

    /**
     * O que não está na lista nova sai: o produto sem corridas é apagado com as
     * tarifas; o que já tem histórico só é desligado (as corridas antigas
     * continuam apontando para ele).
     *
     * @param  list<string>  $codigos
     */
    private function aposentarOutros(array $codigos): void
    {
        foreach (ProdutosCorrida::query()->whereNotIn('codigo', $codigos)->get() as $antigo) {
            $emUso = DB::table('corridas')->where('produto_id', $antigo->id)->exists()
                || DB::table('corrida_opcoes')->where('produto_id', $antigo->id)->exists();

            if ($emUso) {
                $antigo->update(['ativo' => false]);
                Tarifa::query()->where('produto_id', $antigo->id)->update(['ativo' => false]);

                continue;
            }

            DB::table('produto_categorias')->where('produto_id', $antigo->id)->delete();
            Tarifa::query()->where('produto_id', $antigo->id)->delete();

            if (filled($antigo->icone_path)) {
                Storage::disk(IconeDoProduto::DISCO)->delete(basename($antigo->icone_path));
            }

            $antigo->delete();
        }
    }
}
