<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use App\Http\Controllers\Controller;
use App\DynamicImage;
use Illuminate\Http\Request;

class DynamicImageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return DynamicImage::all();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\DynamicImage  $dynamicImage
     * @return \Illuminate\Http\Response
     */
    public function show(DynamicImage $dynamicImage)
    {
        return $dynamicImage;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\DynamicImage  $dynamicImage
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, DynamicImage $dynamicImage)
    {
        DB::transaction(function() use ($request, &$dynamicImage) {
            $dynamicImage->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'dynamic_image' => $dynamicImage->fullData()->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\DynamicImage  $dynamicImage
     * @return \Illuminate\Http\Response
     */
    public function destroy(DynamicImage $dynamicImage)
    {
        $dynamicImage->delete();
        
        return response()->json(['success' => true]);
    }
}
