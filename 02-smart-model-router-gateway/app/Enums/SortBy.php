<?php

namespace App\Enums;

/**
 * Critérios que o OpenRouter aceita em "provider.sort.by".
 *
 * Usar um enum em vez de strings soltas dá duas coisas de graça:
 * a validação do request (Rule::enum) e autocomplete no editor.
 */
enum SortBy: string
{
    case Price = 'price';
    case Throughput = 'throughput';
    case Latency = 'latency';
}
