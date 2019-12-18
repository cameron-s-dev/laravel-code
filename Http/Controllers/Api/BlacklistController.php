<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Blacklist;

class BlacklistController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $blacklists = Blacklist::all();
        return $blacklists;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $blacklist = new Blacklist();
        $blacklist->fillData($request->all());

        return response()->json([
            'success' => true,
            'blacklist' => $blacklist->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Blacklist  $blacklist
     * @return \Illuminate\Http\Response
     */
    public function show(Blacklist $blacklist)
    {
        return $blacklist;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Blacklist  $blacklist
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Blacklist $blacklist)
    {
        $blacklist->fillData($request->all());

        return response()->json([
            'success' => true,
            'blacklist' => $blacklist->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Blacklist  $blacklist
     * @return \Illuminate\Http\Response
     */
    public function destroy(Blacklist $blacklist)
    {
        $response = [
            'success' => false,
        ];
        if ($blacklist->delete()) {
            $response['success'] = true;
        }
        return response()->json($response);
    }
}
