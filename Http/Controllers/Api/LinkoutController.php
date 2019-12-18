<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Linkout;
use App\PathLinkout;

class LinkoutController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $linkouts = Linkout::with('owner')->get();
        return $linkouts;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $linkout = null;
        DB::transaction(function() use ($request, &$linkout) {
            $linkout = new Linkout();
            $linkout->enabled = true;
            $linkout->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'linkout' => $linkout->fullData()->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Linkout  $linkout
     * @return \Illuminate\Http\Response
     */
    public function show(Linkout $linkout)
    {
        return $linkout->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Linkout  $linkout
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Linkout $linkout)
    {
        DB::transaction(function() use ($request, &$linkout) {
            $linkout->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'linkout' => $linkout->fullData()->toArray(),
        ]);
    }

    /**
     * Enable or disable specified linkout.
     *
     * @param  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggle(Request $request, $id) {
        try {
            $linkout = Linkout::find($id);
            $linkout->enabled = !!$request->get('enabled');
            $linkout->save();

            return response()->json([
                'success' => true,
                'enabled' => $linkout->enabled,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Linkout not found',
            ], 404);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Linkout  $linkout
     * @return \Illuminate\Http\Response
     */
    public function destroy(Linkout $linkout)
    {
        DB::transaction(function() use ($linkout) {
            PathLinkout::where('linkout_id', $linkout->id)->delete();
            $linkout->delete();
        });

        return response(['success' => true]);
    }

    /**
     * Duplicate the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function duplicate(Request $request, $id)
    {
        $linkout = null;

        try {
            $linkout = Linkout::find($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Linkout not found',
            ], 400);
        }

        $duplicatedLinkout = null;
        DB::transaction(function() use ($linkout, &$response, &$duplicatedLinkout) {
            $duplicatedLinkout = $linkout->duplicate();
        });

        return response()->json([
            'success' => true,
            'linkout' => $duplicatedLinkout->fullData()->toArray(),
        ]);
    }
}
