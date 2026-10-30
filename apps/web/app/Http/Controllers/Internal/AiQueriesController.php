<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Opsbot\Actions\AiQueryLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AiQueryRequest;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;

final class AiQueriesController extends Controller
{
    public function __invoke(AiQueryRequest $request, RequestId $requestId, AiQueryLogger $logger): JsonResponse
    {
        $result = $logger->log($request->validated(), $requestId->get());
        $query = $result['query'];

        return response()->json([
            'id' => $query->id,
            'actions' => $logger->proposedActions($query),
            'citations' => $logger->citationUrls($query),
            'duplicate' => ! $result['created'],
        ], $result['created'] ? 201 : 200);
    }
}
