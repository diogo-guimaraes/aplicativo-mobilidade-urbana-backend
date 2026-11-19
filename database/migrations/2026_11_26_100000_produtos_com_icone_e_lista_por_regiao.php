<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_corridas', function (Blueprint $table) {
            // desligado, o produto não aparece em lugar nenhum
            $table->boolean('ativo')->default(true)->after('prioritario');
            // o que o passageiro vê ao lado do nome: emoji, URL ou imagem enviada
            $table->string('icone_tipo', 10)->nullable()->after('ativo');
            $table->string('icone_valor', 500)->nullable()->after('icone_tipo');
            $table->string('icone_path')->nullable()->after('icone_valor');
            $table->string('descricao', 120)->nullable()->after('icone_path');
        });

        // lista de produtos por região: se a cidade tem linhas aqui, a lista dela
        // vale sozinha; sem linhas, vale a do estado; sem linhas, todos os ativos
        Schema::create('produto_regioes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produto_id')->constrained('produtos_corridas')->cascadeOnDelete();
            $table->unsignedBigInteger('cidade_id')->nullable()->index();
            $table->unsignedBigInteger('estado_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_regioes');

        Schema::table('produtos_corridas', function (Blueprint $table) {
            $table->dropColumn(['ativo', 'icone_tipo', 'icone_valor', 'icone_path', 'descricao']);
        });
    }
};
