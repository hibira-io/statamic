<?php

namespace Hibira\Statamic\Http\Controllers;

use Hibira\Statamic\LivePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** The current user's latest live preview link of an entry, to open on another device. */
class PreviewLinkController extends Controller
{
    use Concerns;

    public function __invoke(string $entry): JsonResponse
    {
        $this->viewableEntry($entry);

        return response()->json(['url' => LivePreview::link((string) $this->user()->id(), $entry)]);
    }
}
