<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Pixel;

class PixelController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return Pixel::all();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $response = [
            'success' => false,
        ];

        try {
            $pixel = null;
            DB::transaction(function() use ($request, &$pixel) {
                $pixel = new Pixel();
                $pixel->fillData($request->all());
            });
            $response['success'] = true;
            $response['pixel'] = $pixel->toArray();
        } catch (ValidationException $e) {
            $response['success'] = false;
            $response['validation_errors'] = $e->validator->errors()->all();
            return response()->json($response, 400);
        } catch (Exception $e) {
            Log::error($e);
            $response['success'] = false;
            $response['error'] = $e->getMessage();
            return response()->json($response, 500);
        }

        return response()->json($response);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Pixel  $pixel
     * @return \Illuminate\Http\Response
     */
    public function show(Pixel $pixel)
    {
        return $pixel;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Pixel  $pixel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Pixel $pixel)
    {
        $response = [
            'success' => false,
        ];

        try {
            $pixel->fillData($request->all());
            $response['success'] = true;
            $response['pixel'] = $pixel->toArray();
        } catch (ValidationException $e) {
            $response['success'] = false;
            $response['validation_errors'] = $e->validator->errors()->all();
            return response()->json($response, 400);
        } catch (Exception $e) {
            Log::error($e);
        }

        return response()->json($response);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Pixel  $pixel
     * @return \Illuminate\Http\Response
     */
    public function destroy(Pixel $pixel)
    {
        $response = [
            'success' => false,
        ];
        if ($pixel->delete()) {
            $response['success'] = true;
        }
        return response()->json($response);
    }
}
