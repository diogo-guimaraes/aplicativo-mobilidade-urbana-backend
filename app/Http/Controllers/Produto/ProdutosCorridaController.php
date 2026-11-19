<?php

namespace App\Http\Controllers\Produto;

use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\ProdutoRegiao;
use App\Models\ProdutosCorrida;
use App\Services\ProdutosDisponiveisService;
use App\Support\IconeDoProduto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Os produtos (modalidades) de corrida e quais deles cada região oferece.
 *
 * O ícone é emoji, URL ou imagem enviada. Os campos de arquivo vão em
 * multipart; para editar com arquivo, envie POST com `_method=PUT`.
 */
class ProdutosCorridaController extends Controller
{
    private const TAMANHO_MAXIMO_KB = 2048;

    private const ESTRATEGIAS = ['normal', 'negociada', 'eletrico', 'taxi', 'entrega'];

    public function index(): JsonResponse
    {
        $tarifas = DB::table('tarifas')->selectRaw('produto_id, count(*) as total')->groupBy('produto_id')->pluck('total', 'produto_id');
        $listas = ProdutoRegiao::query()->get(['produto_id', 'cidade_id', 'estado_id'])->groupBy('produto_id');

        $produtos = ProdutosCorrida::query()->orderBy('ordem')->orderBy('id')->get()
            ->map(fn (ProdutosCorrida $produto) => [
                ...$this->formatar($produto),
                'tarifas' => (int) ($tarifas[$produto->id] ?? 0),
                'em_uso' => $this->emUso($produto),
                'regioes' => [
                    'estados' => $listas->get($produto->id, collect())->whereNotNull('estado_id')->count(),
                    'cidades' => $listas->get($produto->id, collect())->whereNotNull('cidade_id')->count(),
                ],
            ]);

        return response()->json(['data' => $produtos]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $this->validar($request, null);

        $produto = DB::transaction(function () use ($dados, $request) {
            $produto = ProdutosCorrida::create([
                ...$dados['campos'],
                'ordem' => $dados['campos']['ordem'] ?? ((int) ProdutosCorrida::max('ordem') + 1),
            ]);

            $this->aplicarIcone($produto, $request, $dados['icone']);

            return $produto;
        });

        return response()->json($this->formatar($produto->fresh()), 201);
    }

    public function show(ProdutosCorrida $produtosCorrida): JsonResponse
    {
        return response()->json($this->formatar($produtosCorrida));
    }

    public function update(Request $request, ProdutosCorrida $produtosCorrida): JsonResponse
    {
        $dados = $this->validar($request, $produtosCorrida);

        DB::transaction(function () use ($produtosCorrida, $dados, $request) {
            $produtosCorrida->update($dados['campos']);
            $this->aplicarIcone($produtosCorrida, $request, $dados['icone']);
        });

        return response()->json($this->formatar($produtosCorrida->fresh()));
    }

    public function destroy(ProdutosCorrida $produtosCorrida): JsonResponse
    {
        if ($this->emUso($produtosCorrida) > 0) {
            return response()->json(['message' => 'Este produto já tem corridas. Desative-o em vez de excluir.'], 409);
        }

        $arquivo = $produtosCorrida->icone_path;

        DB::transaction(function () use ($produtosCorrida) {
            DB::table('produto_categorias')->where('produto_id', $produtosCorrida->id)->delete();
            DB::table('tarifas')->where('produto_id', $produtosCorrida->id)->delete();
            $produtosCorrida->delete();
        });

        $this->apagarArquivo($arquivo);

        return response()->json(null, 204);
    }

    /**
     * A lista de uma região: quais produtos o passageiro vê ali e de onde vem
     * essa lista (da própria região, do estado ou geral).
     */
    public function regiao(Request $request, ProdutosDisponiveisService $disponiveis): JsonResponse
    {
        [$cidadeId, $estadoId] = $this->regiaoDoPedido($request);

        $propria = $this->linhasDaRegiao($cidadeId, $estadoId)->pluck('produto_id');
        $efetiva = $disponiveis->listaDaRegiao($cidadeId, $estadoId);

        return response()->json([
            'cidade_id' => $cidadeId,
            'estado_id' => $estadoId,
            'tem_lista_propria' => $propria->isNotEmpty(),
            // de onde vem a lista que vale hoje para esta região
            'origem_efetiva' => $efetiva['origem'],
            'produto_ids_proprios' => $propria->values(),
            'produto_ids_efetivos' => $efetiva['ids'],
        ]);
    }

    /**
     * Define a lista de uma região (`produto_ids`) ou volta a herdar (`produto_ids` nulo).
     */
    public function definirRegiao(Request $request, ProdutosDisponiveisService $disponiveis): JsonResponse
    {
        [$cidadeId, $estadoId] = $this->regiaoDoPedido($request);

        $dados = $request->validate([
            'produto_ids' => 'present|nullable|array|max:100',
            'produto_ids.*' => 'integer|distinct|exists:produtos_corridas,id',
        ]);

        DB::transaction(function () use ($cidadeId, $estadoId, $dados) {
            $this->linhasDaRegiao($cidadeId, $estadoId)->each->delete();

            foreach ($dados['produto_ids'] ?? [] as $produtoId) {
                ProdutoRegiao::create(['produto_id' => $produtoId, 'cidade_id' => $cidadeId, 'estado_id' => $estadoId]);
            }
        });

        return $this->regiao($request, $disponiveis);
    }

    /** Estados e cidades para o seletor de região. */
    public function regioes(): JsonResponse
    {
        return response()->json([
            'estados' => Estado::query()->orderBy('sigla')->get(['id', 'sigla', 'nome']),
            'cidades' => Cidade::query()->orderBy('nome')->get(['id', 'nome', 'uf', 'uf_id']),
        ]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function regiaoDoPedido(Request $request): array
    {
        $dados = $request->validate([
            'cidade_id' => 'nullable|integer|exists:cidades,id',
            'estado_id' => 'nullable|integer|exists:estados,id',
        ]);

        $cidadeId = $dados['cidade_id'] ?? null;
        $estadoId = $dados['estado_id'] ?? null;

        abort_if(($cidadeId === null) === ($estadoId === null), 422, 'Informe a cidade ou o estado, só um dos dois.');

        return [$cidadeId === null ? null : (int) $cidadeId, $estadoId === null ? null : (int) $estadoId];
    }

    /**
     * @return Collection<int, ProdutoRegiao>
     */
    private function linhasDaRegiao(?int $cidadeId, ?int $estadoId): Collection
    {
        return ProdutoRegiao::query()
            ->when($cidadeId !== null, fn ($q) => $q->where('cidade_id', $cidadeId))
            ->when($estadoId !== null, fn ($q) => $q->where('estado_id', $estadoId)->whereNull('cidade_id'))
            ->get();
    }

    /**
     * @return array{campos: array<string, mixed>, icone: array{tipo: string|null|false, valor: string|null}}
     */
    private function validar(Request $request, ?ProdutosCorrida $atual): array
    {
        $criando = $atual === null;
        $sempre = $criando ? 'required' : 'sometimes';

        $dados = $request->validate([
            'nome' => [$sempre, 'string', 'min:2', 'max:60'],
            'codigo' => [$criando ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9_]{2,40}$/', 'unique:produtos_corridas,codigo'],
            'estrategia_precificacao' => [$sempre, Rule::in(self::ESTRATEGIAS)],
            'grupo' => 'nullable|string|max:40',
            'tipo_veiculo' => ['nullable', Rule::in(['carro', 'moto'])],
            'requisito_veiculo' => ['nullable', Rule::in(['eletrico', 'taxi'])],
            'prioritario' => 'sometimes|boolean',
            'ativo' => 'sometimes|boolean',
            'ordem' => 'sometimes|integer|between:0,999',
            'descricao' => 'nullable|string|max:120',
            'icone_tipo' => ['nullable', Rule::in(['emoji', 'url', 'imagem'])],
            'icone_valor' => 'nullable|string|max:500',
            'imagem' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|max:'.self::TAMANHO_MAXIMO_KB,
        ], [
            'codigo.regex' => 'Use só letras minúsculas, números e _ (de 2 a 40).',
        ]);

        $tipo = $dados['icone_tipo'] ?? null;
        $valor = isset($dados['icone_valor']) ? trim($dados['icone_valor']) : null;

        if ($tipo === 'url' && ($valor === null || ! preg_match('#^https?://#i', $valor) || filter_var($valor, FILTER_VALIDATE_URL) === false)) {
            abort(422, 'Informe uma URL de imagem que comece com http:// ou https://.');
        }

        if ($tipo === 'emoji' && ($valor === null || $valor === '' || mb_strlen($valor) > 16)) {
            abort(422, 'Informe um emoji.');
        }

        if ($tipo === 'imagem' && ! $request->hasFile('imagem') && blank($atual?->icone_path)) {
            abort(422, 'Envie o arquivo da imagem.');
        }

        $campos = collect($dados)->only([
            'nome', 'codigo', 'estrategia_precificacao', 'grupo', 'tipo_veiculo', 'requisito_veiculo',
            'prioritario', 'ativo', 'ordem', 'descricao',
        ])->all();

        foreach (['grupo', 'tipo_veiculo', 'requisito_veiculo', 'descricao'] as $opcional) {
            if (array_key_exists($opcional, $campos) && blank($campos[$opcional])) {
                $campos[$opcional] = null;
            }
        }

        // `false`: o pedido não fala do ícone (por exemplo, só liga ou desliga o produto)
        return ['campos' => $campos, 'icone' => ['tipo' => $request->has('icone_tipo') ? $tipo : false, 'valor' => $valor]];
    }

    /**
     * @param  array{tipo: string|null|false, valor: string|null}  $icone
     */
    private function aplicarIcone(ProdutosCorrida $produto, Request $request, array $icone): void
    {
        if ($icone['tipo'] === false) {
            return;
        }

        $antigo = $produto->icone_path;
        $novo = null;
        $valor = null;

        if ($icone['tipo'] === 'imagem') {
            $novo = $antigo;

            if ($request->file('imagem') instanceof UploadedFile) {
                $nome = Str::uuid().'.'.$request->file('imagem')->extension();
                Storage::disk(IconeDoProduto::DISCO)->putFileAs('', $request->file('imagem'), $nome, 'public');
                $novo = $nome;
            }
        } elseif ($icone['tipo'] !== null) {
            $valor = $icone['valor'];
        }

        $produto->update([
            'icone_tipo' => $icone['tipo'],
            'icone_valor' => $valor,
            'icone_path' => $novo,
        ]);

        // o arquivo velho só sai depois que o produto já aponta para o novo
        if ($antigo !== null && $antigo !== $novo) {
            $this->apagarArquivo($antigo);
        }
    }

    private function apagarArquivo(?string $nome): void
    {
        if (filled($nome)) {
            Storage::disk(IconeDoProduto::DISCO)->delete(basename($nome));
        }
    }

    private function emUso(ProdutosCorrida $produto): int
    {
        return DB::table('corridas')->where('produto_id', $produto->id)->count()
            + DB::table('corrida_opcoes')->where('produto_id', $produto->id)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatar(ProdutosCorrida $produto): array
    {
        return [
            'id' => $produto->id,
            'nome' => $produto->nome,
            'codigo' => $produto->codigo,
            'estrategia_precificacao' => $produto->estrategia_precificacao,
            'grupo' => $produto->grupo,
            'tipo_veiculo' => $produto->tipo_veiculo,
            'requisito_veiculo' => $produto->requisito_veiculo,
            'prioritario' => (bool) $produto->prioritario,
            'ativo' => (bool) $produto->ativo,
            'ordem' => $produto->ordem,
            'descricao' => $produto->descricao,
            'icone_tipo' => $produto->icone_tipo,
            'icone_valor' => $produto->icone_tipo === 'imagem' ? null : $produto->icone_valor,
            'icone' => $produto->icone(),
        ];
    }
}
