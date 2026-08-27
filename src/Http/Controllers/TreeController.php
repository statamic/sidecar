<?php

namespace Statamic\Sidecar\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Sidecar\Facades\Sidecar;

class TreeController extends CpController
{
    public function index(string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);

        return ['pages' => $source->driver()->tree($source)];
    }

    public function update(Request $request, string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);
        abort_if($source->readOnly(), 403);

        $request->validate([
            'pages' => 'required|array',
        ]);

        $driver = $source->driver();

        $driver->saveTree($source, $request->pages);

        $driver->afterTreeSaved($source);

        return ['saved' => true];
    }
}
