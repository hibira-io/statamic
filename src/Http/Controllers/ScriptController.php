<?php

namespace Hibira\Statamic\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/** Serves the control panel script straight from the package, so there is nothing to publish. */
class ScriptController extends Controller
{
    public const FILE = __DIR__.'/../../../dist/cp.js';

    public function __invoke(Request $request): Response
    {
        $etag = '"'.static::version().'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response((string) file_get_contents(static::FILE), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ]);
    }

    public static function version(): string
    {
        return substr((string) md5_file(static::FILE), 0, 12);
    }
}
