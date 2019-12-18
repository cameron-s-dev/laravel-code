<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\OauthClient;

class AuthController extends Controller
{

    /**
     * login method
     *
     * @param Request $request
     * @return Response
     */
    public function login(Request $request) {
        $response = [
            'success' => true,
        ];

        $client = OauthClient::where('password_client', 1)->first();
        if (!$client) {
            $response['success'] = false;
            $response['error'] = 'Internal error occurred: failed to get OAuth client.';
            return response()->json($response, 500);
        }

        $request->request->add([
            'username' => $request->get('email'),
            'password' => $request->get('password'),
            'grant_type' => 'password',
            'client_id' => $client->id,
            'client_secret' => $client->secret,
            'scope' => '*',
        ]);

        $tokenRequest = Request::create(
            env('APP_URL') . '/oauth/token',
            'post'
        );
        $passportResponse = json_decode(Route::dispatch($tokenRequest)->getContent(), true);
        if (!empty($passportResponse['error'])) {
            $response['success'] = false;
            if ($passportResponse['error'] == 'invalid_credentials') {
                $response['error'] = 'Invalid e-mail or password';
            } else {
                $response['error'] = $passportResponse['error'];
            }
            return response()->json($response, 401);
        } else {
            $response = $passportResponse;
            $response['success'] = true;
        }
        return response()->json($response);
    }
}
