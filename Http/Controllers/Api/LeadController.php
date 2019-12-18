<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use GuzzleHttp\Client;
use App\Http\Controllers\Controller;
use App\Lead;
use App\Advertiser;
use App\SimpleOptIn;

class LeadController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $page = $request->get('page');
        if (!$page) {
            $page = 1;
        }

        $limit = $request->get('limit');
        if (!$limit) {
            $limit = 10;
        }

        $query = Lead::with('user')->orderBy('created_at', 'desc');

        $dateFilter = $request->get('date');
        if ($dateFilter) {
            $query = $query->whereDate('created_at', $dateFilter);
        }

        $statusFilter = $request->get('status');
        if ($statusFilter) {
            $query = $query->where('status', $statusFilter);
        }

        $advertiserFilter = $request->get('advertiser');
        if ($advertiserFilter) {
            $query = $query->where('advertiser', $advertiserFilter);
        }

        $soiFilter = $request->get('soi');
        if ($soiFilter) {
            $query = $query->where('soi', $soiFilter);
        }

        $search = $request->get('search');
        if ($search) {
            $searchPattern = '%' . $search . '%';
            $query = $query->where(function ($query) use ($searchPattern) {
                $query->where('soi', 'LIKE', $searchPattern)
                    ->orWhere('advertiser', 'LIKE', $searchPattern)
                    ->orWhereHas('user', function($query) use ($searchPattern) {
                        $query->where('first_name', 'LIKE', $searchPattern)
                            ->orWhere('last_name', 'LIKE', $searchPattern)
                            ->orWhere('address', 'LIKE', $searchPattern)
                            ->orWhere('city', 'LIKE', $searchPattern)
                            ->orWhere('phone', 'LIKE', $searchPattern)
                            ->orWhere('email', 'LIKE', $searchPattern)
                            ->orWhere('ip', 'LIKE', $searchPattern);
                    });
            });
        }

        $leads = $query->paginate($limit, array('*'), 'page', $page);

        return $leads;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Lead  $lead
     * @return \Illuminate\Http\Response
     */
    public function show(Lead $lead)
    {
        return $lead->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Lead  $lead
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Lead $lead)
    {
        DB::transaction(function() use ($request, &$lead) {
            $lead->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'lead' => $lead->fullData()->toArray(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  $id
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Lead  $lead
     * @return \Illuminate\Http\Response
     */
    public function saveAndResubmit(Request $request, $id)
    {
        try {
            $lead = Lead::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lead not found',
            ], 404);
        }

        $postingData = json_decode($lead->posting_data);
        $delivered = false;
        $soi = $lead->simpleOptIn;

        $json = false;
        $headers = [];
        foreach ($soi->customHeaders as $customHeader) {
            if (!$customHeader->name || !$customHeader->value) {
                continue;
            }
            $headers[$customHeader->name] = $customHeader->value;
            if ($customHeader['name'] == 'Content-Type' && $customHeader['value'] == 'application/json') {
                $json = true;
            }
        }

        $responseBody = '';
        $delivered = false;
        try {
            $client = new Client([
                'timeout'  => 15,
            ]);

            $options = [
                'http_errors' => false,
            ];
            $method = 'get';
            if ($soi->posting_method === 'http_post') {
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

            $_response = $client->$method($soi->posting_url, $options);
            $bodyObj = $_response->getBody();
            $responseBody = (string)$bodyObj;

            if (stripos($responseBody, $soi->success_string) !== false) {
                $delivered = true;
            }
        } catch (Exception $e) {
        }

        DB::transaction(function() use ($request, $lead, $postingData, $delivered, $responseBody) {
            $lead->status = $delivered ? 'Delivered' : 'Rejected';
            $lead->posting_data = json_encode($postingData);
            $lead->submission_response = $responseBody;

            $lead->fillData($request->all(), true);
        });

        return response()->json([
            'success' => true,
            'lead' => $lead->fullData()->toArray(),
        ]);
    }

    /**
     * Return names of advertisers and SOIs for leads list page
     *
     * @return \Illuminate\Http\Response
     */
    public function leadFilters(Request $request)
    {
        $response = [];

        $response['advertisers'] = [];
        $advertisers = Advertiser::select('title')->get();
        foreach ($advertisers as $advertiser) {
            $response['advertisers'][] = $advertiser->title;
        }

        $response['sois'] = [];
        $sois = SimpleOptIn::select('admin_label')->get();
        foreach ($sois as $soi) {
            $response['sois'][] = $soi->admin_label;
        }

        return $response;
    }
}
