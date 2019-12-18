<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use App\Http\Controllers\Controller;
use App\Path;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;

class IMAController extends Controller
{
    /**
     * Generate token using IMA API
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function generateToken(Request $request)
    {
        $client = new Client([
            'base_uri' => env('IMA_API_URL') . '/',
            'timeout'  => 15,
        ]);

        $apiResponse = $client->post("token/", [
            'form_params' => $request->all(),
        ]);
        $bodyObj = $apiResponse->getBody();
        $body = (string)$bodyObj;
        $responseData = json_decode($body, true);
        return response()->json(['token' => $responseData['token']]);
    }
}
