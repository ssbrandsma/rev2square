<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Realtime\ChannelNames;
use App\Services\Realtime\RealtimeEpoch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Current realtime channel names for rejoining after a missed rotation.
 * A client offline during rotation holds stale names; this endpoint lets
 * it discover the current ones. 403 means genuinely denied (suspended or
 * no view access), distinguishing lost access from an obsolete epoch.
 */
class RealtimeController extends Controller
{
    public function channels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room' => ['required', 'string', 'regex:/^(lounge|online|project:\d+)$/'],
        ]);

        if ($request->user()->isSuspended()) {
            abort(403, 'Suspended accounts cannot use realtime chat.');
        }

        $global = RealtimeEpoch::current();

        if ($data['room'] === 'lounge') {
            return response()->json([
                'chat' => ChannelNames::lounge($global),
                'presence' => ChannelNames::loungeRoster($global),
            ]);
        }

        if ($data['room'] === 'online') {
            return response()->json([
                'chat' => null,
                'presence' => ChannelNames::online($global),
            ]);
        }

        $project = Project::findOrFail((int) substr($data['room'], strlen('project:')));
        Gate::authorize('view', $project);

        return response()->json([
            'chat' => ChannelNames::projectChat($project, $global),
            'presence' => ChannelNames::projectRoster($project, $global),
        ]);
    }
}
