<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Advertiser;

class AdvertiserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $advertisers = Advertiser::orderBy('title')->get();

        $advertiserListData = [];
        foreach ($advertisers as $key => $advertiser) {
            $advertiserData = $advertiser->toArray();
            $searchKeywords = [$advertiser->title];
            foreach ($advertiser->linkouts as $key => $linkout) {
                $searchKeywords[] = $linkout->owner ? $linkout->owner->name : '';
                $searchKeywords[] = $linkout->admin_label;
            }
            foreach ($advertiser->sois as $key => $soi) {
                $searchKeywords[] = $soi->owner ? $soi->owner->name : '';
                $searchKeywords[] = $soi->admin_label;
            }
            $advertiserData['search_keywords'] = implode('_', array_map('strtolower', $searchKeywords));
            $advertiserListData[] = $advertiserData;
        }

        return response()->json($advertiserListData);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $advertiser = new Advertiser();
        $advertiser->fillData($request->all());

        return response()->json([
            'success' => true,
            'advertiser' => $advertiser->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Advertiser  $advertiser
     * @return \Illuminate\Http\Response
     */
    public function show(Advertiser $advertiser)
    {
        return $advertiser;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Advertiser  $advertiser
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Advertiser $advertiser)
    {
        $advertiser->fillData($request->all());

        return response()->json([
            'success' => true,
            'advertiser' => $advertiser->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Advertiser  $advertiser
     * @return \Illuminate\Http\Response
     */
    public function destroy(Advertiser $advertiser)
    {
        $response = [
            'success' => false,
        ];
        if ($advertiser->delete()) {
            $response['success'] = true;
        }
        return response()->json($response);
    }
}
