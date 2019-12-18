<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Path;

class PathController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $paths = Path::all();
        return $paths;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $path = null;
        DB::transaction(function() use ($request, &$path) {
            $path = new Path();
            $path->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'path' => $path->fullData()->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Path  $path
     * @return \Illuminate\Http\Response
     */
    public function show(Path $path)
    {
        return $path->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Path  $path
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Path $path)
    {
        DB::transaction(function() use ($request, &$path) {
            $path->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'path' => $path->fullData()->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Path  $path
     * @return \Illuminate\Http\Response
     */
    public function destroy(Path $path)
    {
        DB::transaction(function() use ($path) {
            foreach ($path->pathSois as $pathSoi) {
                $pathSoi->soiIds()->delete();
            }
            $path->pathSois()->delete();
            $path->pathLinkouts()->delete();
            $path->urlParams()->delete();
            $path->delete();
        });

        return response()->json(['success' => true]);
    }
}
