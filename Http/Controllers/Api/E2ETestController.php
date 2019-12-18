<?php

namespace App\Http\Controllers\Api;

use Config;
use DateTime;
use DB;
use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class E2ETestController extends Controller
{
    /**
     * Respond with provided result string for pre-pinging SOIs and linkouts
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function respondToPrePing(Request $request, $result) {
        // return response()->text($result);
        return response($result);
    }
}
