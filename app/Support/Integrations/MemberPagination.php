<?php

namespace App\Support\Integrations;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class MemberPagination
{
    public static function size(Request $request): int
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($data['per_page'] ?? 25);
    }

    public static function payload(LengthAwarePaginator $page, callable $present): array
    {
        return ['data' => $page->getCollection()->map($present)->values()->all(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]];
    }
}
