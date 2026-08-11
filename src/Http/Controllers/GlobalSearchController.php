<?php

declare(strict_types=1);

namespace Inlay\Resources\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inlay\PanelRegistry;
use Inlay\Resources\GlobalSearch;

/**
 * Protected, panel-scoped resource search endpoint.
 *
 * Search is deliberately a GET contract: it is read-only, easy to debounce
 * from either renderer, and inherits the panel's authentication, tenant, and
 * policy middleware before any resource query is built.
 */
final class GlobalSearchController
{
    public function __construct(private readonly PanelRegistry $panels) {}

    public function index(Request $request): JsonResponse
    {
        $panel = $this->panels->get((string) $request->route('inlayPanel'));
        $term = $request->query('q', '');
        $limit = $request->integer('limit', 5);

        if (! is_string($term) || mb_strlen($term) > 200) {
            return new JsonResponse(['message' => 'The search query must be a string of at most 200 characters.'], 422);
        }

        if ($limit < 1 || $limit > 50) {
            return new JsonResponse(['message' => 'The search limit must be between 1 and 50.'], 422);
        }

        return new JsonResponse([
            'contract' => 'inlay.resources.global-search.v1',
            'query' => trim($term),
            'results' => GlobalSearch::across($panel->getResources())->search(
                term: $term,
                user: $request->user(),
                prefix: $panel->pathValue(),
                limit: $limit,
            ),
        ]);
    }
}
