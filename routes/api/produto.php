<?php

use App\Http\Controllers\Produto\ProdutoCategoriaController;
use App\Http\Controllers\Produto\ProdutosCorridaController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
// lista de produtos por estado ou cidade (a da cidade vale sozinha, depois a do estado)
Route::get('produtos-corridas/regioes', [ProdutosCorridaController::class, 'regioes']);
Route::get('produtos-corridas/lista', [ProdutosCorridaController::class, 'regiao']);
Route::put('produtos-corridas/lista', [ProdutosCorridaController::class, 'definirRegiao'])->middleware('throttle:60,1');
Route::apiResource('produtos-corridas', ProdutosCorridaController::class);
Route::apiResource('produto-categorias', ProdutoCategoriaController::class);
