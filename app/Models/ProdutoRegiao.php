<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um produto na lista de uma região. A lista de uma cidade (ou de um estado) é
 * o conjunto dessas linhas; ver ProdutosDisponiveisService.
 *
 * @property int $produto_id
 * @property int|null $cidade_id
 * @property int|null $estado_id
 */
class ProdutoRegiao extends Model
{
    protected $table = 'produto_regioes';

    protected $fillable = ['produto_id', 'cidade_id', 'estado_id'];
}
