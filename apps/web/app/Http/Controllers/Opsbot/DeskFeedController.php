<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\DeskFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeskFeedController extends OpsController
{
    public function __invoke(Request $request, DeskFeed $feed): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'date']]);

        return response()->json($feed->feed(
            $this->actor(),
            isset($data['after']) ? (string) $data['after'] : null,
            (int) config('opsbot.desk_feed_limit', 30),
        ));
    }
}
