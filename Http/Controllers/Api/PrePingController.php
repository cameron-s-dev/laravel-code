<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use App\Http\Controllers\Controller;

class PrePingController extends Controller
{
    /**
     * Test-call pre-ping url and return its response
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  $id
     * @return \Illuminate\Http\Response
     */
    public function testPrePing(Request $request)
    {
        $postingUrl = $request->get('posting_url');
        $postingMethod = $request->get('posting_method');
        $postingData = $request->get('posting_data');

        $client = new Client([
            'timeout'  => 15,
        ]);
        try {
            if ($postingMethod === 'post') {
                $_response = $client->post($postingUrl, [
                    'form_params' => $postingData,
                ]);
            } else {
                $_response = $client->get($postingUrl, [
                    'query' => $postingData,
                ]);
            }
            $bodyObj = $_response->getBody();
            $body = (string)$bodyObj;
        } catch (ClientException $e) {
            $body = '';
        }

        return response()->json([
            'submission_response' => mb_convert_encoding($body, 'UTF-8', 'UTF-8'),
        ]);
    }
}
