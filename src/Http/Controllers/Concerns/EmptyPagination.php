<?php

namespace StripeLri\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait EmptyPagination
{
    protected function emptyPaginator(
        Request $request,
        string $routeName,
        int $defaultPerPage = 8,
        array $allowedPerPage = [8, 12, 25, 50],
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $perPage = (int) $request->query('per_page', $defaultPerPage);
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = $defaultPerPage;
        }
        $page = max(1, (int) $request->query($pageName, 1));

        return (new LengthAwarePaginator([], 0, $perPage, $page, [
            'path' => route($routeName),
            'pageName' => $pageName,
        ]))->withQueryString();
    }
}
