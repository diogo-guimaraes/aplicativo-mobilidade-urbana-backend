<?php

namespace App\Models;

use App\Support\IconeDoProduto;
use Database\Factories\ProdutosCorridaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nome
 * @property string $codigo
 * @property string $estrategia_precificacao
 * @property string|null $grupo
 * @property string|null $tipo_veiculo
 * @property string|null $requisito_veiculo
 * @property bool $prioritario chamada vai antes das outras (Expresso)
 * @property int|null $ordem
 */
class ProdutosCorrida extends Model
{
    /** @use HasFactory<ProdutosCorridaFactory> */
    use HasFactory;

    protected $fillable = [
        'nome',
        'codigo',
        'estrategia_precificacao',
        'grupo',
        'tipo_veiculo',
        'requisito_veiculo',
        'prioritario',
        'ordem',
        'ativo',
        'icone_tipo',
        'icone_valor',
        'icone_path',
        'descricao',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['prioritario' => 'boolean', 'ativo' => 'boolean'];
    }

    /** @return array{tipo: string, valor: string}|null */
    public function icone(): ?array
    {
        return IconeDoProduto::de($this->icone_tipo, $this->icone_valor, $this->icone_path);
    }

    public function atendidoPor(Veiculo $veiculo): bool
    {
        if ($this->tipo_veiculo === null) {
            return true;
        }

        if ($veiculo->categoria !== $this->tipo_veiculo) {
            return false;
        }

        return match ($this->requisito_veiculo) {
            'eletrico' => $veiculo->eletrico,
            'taxi' => $veiculo->taxi,
            default => true,
        };
    }

    /**
     * Produtos que o veículo pode atender: os do tipo dele (carro ou moto) e,
     * entre os que têm requisito, só os que ele cumpre (elétrico, táxi).
     *
     * @param  Builder<ProdutosCorrida>  $consulta
     */
    public function scopeAtendidosPor(Builder $consulta, Veiculo $veiculo): void
    {
        $requisitos = array_keys(array_filter([
            'eletrico' => $veiculo->eletrico,
            'taxi' => $veiculo->taxi,
        ]));

        $consulta->where(fn (Builder $produto) => $produto
            ->whereNull('tipo_veiculo')
            ->orWhere(fn (Builder $doTipo) => $doTipo
                ->where('tipo_veiculo', $veiculo->categoria)
                ->where(fn (Builder $requisito) => $requisito
                    ->whereNull('requisito_veiculo')
                    ->orWhereIn('requisito_veiculo', $requisitos))));
    }
}
