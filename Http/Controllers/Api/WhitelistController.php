<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Whitelist;

class WhitelistController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $whitelists = Whitelist::all();
        return $whitelists;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $whitelist = new Whitelist();
        $whitelist->fillData($request->all());

        return response()->json([
            'success' => true,
            'whitelist' => $whitelist->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Whitelist  $whitelist
     * @return \Illuminate\Http\Response
     */
    public function show(Whitelist $whitelist)
    {
        return $whitelist;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Whitelist  $whitelist
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Whitelist $whitelist)
    {
        $whitelist->fillData($request->all());

        return response()->json([
            'success' => true,
            'whitelist' => $whitelist->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Whitelist  $whitelist
     * @return \Illuminate\Http\Response
     */
    public function destroy(Whitelist $whitelist)
    {
        $response = [
            'success' => false,
        ];
        if ($whitelist->delete()) {
            $response['success'] = true;
        }
        return response()->json($response);
    }
}
