<?php

namespace App\Support;

/**
 * O ícone de um produto de corrida: emoji, URL ou imagem enviada pela gestão.
 */
class IconeDoProduto
{
    public const DISCO = 'produtos';

    public const PASTA_PUBLICA = '/images/produtos/';

    /**
     * @return array{tipo: string, valor: string}|null valor é o emoji ou a URL final da imagem
     */
    public static function de(?string $tipo, ?string $valor, ?string $arquivo): ?array
    {
        return match ($tipo) {
            'emoji' => filled($valor) ? ['tipo' => 'emoji', 'valor' => $valor] : null,
            'url' => filled($valor) ? ['tipo' => 'url', 'valor' => $valor] : null,
            'imagem' => filled($arquivo) ? ['tipo' => 'imagem', 'valor' => url(self::PASTA_PUBLICA.$arquivo)] : null,
            default => null,
        };
    }
}
