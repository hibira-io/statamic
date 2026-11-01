<?php

namespace Hibira\Statamic\Http\Controllers;

use Hibira\Statamic\Members;
use Hibira\Statamic\Realtime;
use Hibira\Statamic\Routes;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** Everything the control panel script needs, for the signed-in user. */
class ConfigController extends Controller
{
    use Concerns;

    public function __invoke(Realtime $realtime): JsonResponse
    {
        $user = $this->user();
        $config = config('hibira-statamic');

        if (! $realtime->configured()) {
            return response()->json(['enabled' => false]);
        }

        return response()->json([
            'enabled' => true,
            'connection' => $realtime->clientOptions(),
            'user' => Members::info($user) + ['super' => (bool) $user->isSuper()],
            'csrf' => csrf_token(),
            'features' => [
                'presence' => (bool) $config['presence'],
                'comments' => (bool) $config['comments']['enabled'],
                'livePreview' => (bool) $config['live_preview'],
            ],
            'maxLength' => (int) $config['comments']['max_length'],
            'routes' => Routes::urls(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'strings' => trans('hibira-statamic::messages'),
        ]);
    }
}
