<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Http\Controllers\Controller;
use App\SimpleOptIn;
use App\PathSoiAssignmentSoi;

class SOIController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $sois = SimpleOptIn::with('owner')->get();
        return $sois;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $requestData = $request->all();
        if (empty($requestData['disclaimer_text'])) {
            $requestData['disclaimer_text'] = '';
        }

        $soi = null;
        DB::transaction(function() use ($request, $requestData, &$soi) {
            $soi = new SimpleOptIn();
            $soi->fillData($requestData);
        });

        return response()->json([
            'success' => true,
            'soi' => $soi->fullData()->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\SimpleOptIn  $simpleOptIn
     * @return \Illuminate\Http\Response
     */
    public function show(SimpleOptIn $simpleOptIn)
    {
        return $simpleOptIn->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\SimpleOptIn  $simpleOptIn
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, SimpleOptIn $simpleOptIn)
    {
        $requestData = $request->all();
        if (empty($requestData['disclaimer_text'])) {
            $requestData['disclaimer_text'] = '';
        }

        DB::transaction(function() use ($request, $requestData, &$simpleOptIn) {
            $simpleOptIn->fillData($requestData);
        });

        return response()->json([
            'success' => true,
            'soi' => $simpleOptIn->fullData()->toArray(),
        ]);
    }

    /**
     * Enable or disable specified SOI.
     *
     * @param  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggle(Request $request, $id) {
        try {
            $soi = SimpleOptIn::find($id);
            $soi->enabled = !!$request->get('enabled');
            $soi->save();

            return response()->json([
                'success' => true,
                'enabled' => $soi->enabled,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'SOI not found',
            ], 404);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\SimpleOptIn  $simpleOptIn
     * @return \Illuminate\Http\Response
     */
    public function destroy(SimpleOptIn $simpleOptIn)
    {
        DB::transaction(function() use ($simpleOptIn) {
            PathSoiAssignmentSoi::where('soi_id', $simpleOptIn->id)->delete();
            $simpleOptIn->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * Test submission of the SOI.
     *
     * @param  Request $request
     * @param  $id
     * @return \Illuminate\Http\Response
     */
    public function testSubmission(Request $request)
    {
        $postingUrl = $request->get('posting_url');
        $postingMethod = $request->get('posting_method');
        $postingData = $request->get('posting_data');
        $customHeaders = $request->get('custom_headers');

        $json = false;
        $headers = [];
        foreach ($customHeaders as $customHeader) {
            if (!$customHeader['name'] || !$customHeader['value']) {
                continue;
            }
            $headers[$customHeader['name']] = $customHeader['value'];
            if ($customHeader['name'] == 'Content-Type' && $customHeader['value'] == 'application/json') {
                $json = true;
            }
        }

        $client = new Client([
            'timeout'  => 15,
        ]);

        $options = [
            'http_errors' => false,
        ];
        $method = 'get';
        if ($postingMethod === 'http_post') {
            if ($json) {
                $options['json'] = $postingData;
            } else {
                $options['form_params'] = $postingData;
            }
            $method = 'post';
        } else {
            $options['query'] = $postingData;
        }

        if (count($headers) > 0) {
            $options['headers'] = $headers;
        }

        $_response = $client->$method($postingUrl, $options);
        $bodyObj = $_response->getBody();
        $body = (string)$bodyObj;

        return response()->json([
            'success' => true,
            'submission_response' => $body,
        ]);
    }

    /**
     * Duplicate the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  $id
     * @return \Illuminate\Http\Response
     */
    public function duplicate(Request $request, $id)
    {
        $soi = SimpleOptIn::find($id);
        if (!$soi) {
            return response()->json([
                'success' => false,
                'error' => 'SOI not found',
            ], 400);
        }

        $duplicatedSOI = null;
        DB::transaction(function() use ($soi, &$duplicatedSOI) {
            $duplicatedSOI = $soi->duplicate();
        });

        return response()->json([
            'success' => true,
            'soi' => $duplicatedSOI->fullData()->toArray(),
        ]);
    }
}
