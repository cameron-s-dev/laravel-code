<?php

namespace App\Http\Controllers\Api;

use Config;
use DateTime;
use DB;
use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use App\Path;
use App\SimpleOptIn;
use App\Lead;
use App\LeadUser;
use App\Product;
use App\DynamicImage;
use App\Visit;
use App\SoiSkip;
use App\LinkoutSkip;
use App\ProgressToEnd;
use App\Pixel;
use App\Services\FilterService;
use App\Traits\PrepareUserData;

class FrontController extends Controller
{
    use PrepareUserData;

    /**
     * Find path by slug and return its information
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $pathSlug
     * @return \Illuminate\Http\Response
     */
    public function getPath(Request $request, $pathSlug) {
        $response = [
            'success' => false,
        ];

        $request->session()->put(Config::get('constants.revenue_bucket_session'), 0);

        $domain = $request->get('domain');
        $path = Path::where('slug', $pathSlug)->where('domain', $domain)->with('urlParams')->first();
        if ($path) {
            $response['success'] = true;
            $response['path'] = $path->toArray();
            if ($path->redirect_to_gif_welcome_page) {
                $response['redirect_gif_url'] = sprintf('%s/welcome-to-get-it-free/', env('WP_SITE_URL'));
            }

            $utm_content = $request->get('utm_content');
            if ($utm_content) {
                $product = Product::where('utm_content', $utm_content)->first();
                if ($product) {
                    $response['product_post_id'] = $product->post_id;
                }
                $dynamicImage = DynamicImage::where('utm_content', $utm_content)->first();
                if ($dynamicImage) {
                    $response['dynamic_image_url'] = $dynamicImage->image;
                }
            }

            $utmSource = $request->get('utm_source');
            $utmSource = empty($utmSource) ? '' : $utmSource;

            $visit = new Visit([
                'ip' => $this->getIp(),
                'traffic_source' => $utmSource,
            ]);
            $visit->path_id = $path->id;
            $visit->save();

            // Generate session nonce
            $generatedNonce = bin2hex(random_bytes(16));
            $request->session()->put('api-nonce', $generatedNonce);
            $response['nonce'] = $generatedNonce;
        } else {
            $response['error'] = 'Path not found';
            return response()->json($response, 404);
        }

        return response()->json($response);
    }

    /**
     * Find path by slug and return its survey questions, answers, follow-ups and SOIs
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $pathSlug
     * @return \Illuminate\Http\Response
     */
    public function getPathSurvey(Request $request, $pathSlug) {
        $response = [
            'success' => false,
        ];

        $request->session()->put(Config::get('constants.sois_revenue_session'), 0);

        $userData = $request->get('user');
        $request->session()->put('user', $userData);

        $path = Path::where('slug', $pathSlug)->first();
        if (!$path) {
            $response['error'] = 'Path not found';
            return response()->json($response, 404);
        }

        // Return all Survey questions, follow-up questions and all answers
        $survey = $path->survey()->with([
            'surveyQuestions.surveyQuestion',
            'surveyQuestions.surveyQuestion.answers',
            'surveyQuestions.surveyQuestion.activeTimesCapsFilter',
            'surveyQuestions.surveyQuestion.demographicFilters',
            'surveyQuestions.surveyQuestion.platformFilter',
            'surveyQuestions.surveyQuestion.blacklists',
            'surveyQuestions.surveyQuestion.whitelists',
            'surveyQuestions.followUps.question',
            'surveyQuestions.followUps.question.answers',
            'surveyQuestions.followUps.question.activeTimesCapsFilter',
            'surveyQuestions.followUps.question.demographicFilters',
            'surveyQuestions.followUps.question.platformFilter',
            'surveyQuestions.followUps.question.blacklists',
            'surveyQuestions.followUps.question.whitelists',
        ])->first();

        $response['survey'] = [
            'id' => $survey->id,
            'name' => $survey->name,
            'survey_questions' => [],
        ];
        foreach ($survey->surveyQuestions as $surveyQuestionSurvey) {
            if (
                $surveyQuestionSurvey->surveyQuestion->enabled &&
                FilterService::checkFilters($surveyQuestionSurvey->surveyQuestion, $userData)
            ) {
                $surveyQuestionData = [
                    'id' => $surveyQuestionSurvey->id,
                    'survey_question_id' => $surveyQuestionSurvey->survey_question_id,
                    'survey_question' => $surveyQuestionSurvey->surveyQuestion,
                    'follow_ups' => [],
                ];
                foreach ($surveyQuestionSurvey->followUps as $followUp) {
                    if (
                        $followUp->question->enabled &&
                        FilterService::checkFilters($followUp->question, $userData)
                    ) {
                        $surveyQuestionData['follow_ups'][] = $followUp;
                    }
                }
                $response['survey']['survey_questions'][] = $surveyQuestionData;
            }
        }

        // Return all SOIs bound to survey questions in this path
        // Pre-ping-required SOIs will have only IDs returned
        $soiAssignments = $path->pathSois;
        $response['sois'] = [];
        foreach ($soiAssignments as $soiAssignment) {
            $soiIds = [];
            $soiAssignmentSois = $soiAssignment->soiIds()->with([
                'soi',
                'soi.prePingConfig',
                'soi.activeTimesCapsFilter',
                'soi.demographicFilters',
                'soi.platformFilter',
                'soi.postingFields',
                'soi.questions.answers',
                'soi.blacklists',
                'soi.whitelists',
            ])->get();
            $count = 0;

            foreach ($soiAssignmentSois as $soiAssignmentSoi) {
                if (!$soiAssignmentSoi->soi->enabled) {
                    continue;
                }
                $soiIdData = collect($soiAssignmentSoi->toArray())->except(
                    'soi.pre_ping_config',
                    'soi.active_times_caps_filter',
                    'soi.demographic_filters',
                    'soi.blacklists',
                    'soi.whitelists'
                );

                $soi = $soiAssignmentSoi->soi;
                if ($soi->isPrePingRequired()) {
                    // For pre-ping SOIs, we can skip filter checks so that we can avoid double check,
                    // as pre-ping API does check to hide pre-ping-passed but check-failing SOIs
                    $soiIdData['soi'] = [
                        'id' => $soi->id,
                        'pre_ping' => true,
                    ];
                } else {
                    if (!FilterService::checkFilters($soi, $userData)) {
                        continue;
                    }
                    if (!$soi->checkOfferCaps()) {
                        continue;
                    }
                }

                $soiIds[] = $soiIdData;

                $count++;
                if ($path->max_sois_shown_per_question && $count >= $path->max_sois_shown_per_question) {
                    break;
                }
            }
            if (count($soiIds) > 0) {
                $soiAssignmentArray = $soiAssignment->toArray();
                $soiAssignmentArray['soi_ids'] = $soiIds;
                $response['sois'][] = $soiAssignmentArray;
            }
        }

        $response['success'] = true;
        return response()->json($response);
    }

    /**
     * Submit SOI lead
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function submitLead(Request $request) {
        $response = [
            'success' => false,
        ];

        $userData = $request->get('user');
        $soiId = $request->get('soi_id');
        $pathId = $request->get('path_id');
        $postingData = $request->get('posting_data');
        $surveyData = $request->get('survey_data');     // TODO: determine how survey data will be saved and used
        $platform = $request->get('platform');
        $questionId = $request->get('question_id');
        $answerId = $request->get('answer_id');
        $utmSource = $request->get('utm_source');

        $soi = null;
        try {
            $soi = SimpleOptIn::where('id', $soiId)->with('advertiser')->firstOrFail();
        } catch (ModelNotFoundException $e) {
            $response['error'] = 'SOI not found';
            return response()->json($response, 404);
        }

        $delivered = false;

        foreach ($soi->postingFields as $postingField) {
            if (!$postingField->enabled || !$postingField->client_field) {
                continue;
            }
            if ($postingField->field == 'Date') {
                if ($postingField->format) {
                    $postingData[$postingField->client_field] = date($postingField->format);
                }
            } else if ($postingField->field == 'IP Address') {
                $postingData[$postingField->client_field] = $this->getIp();
            }
        }

        foreach ($soi->hardcodedFields as $hardcodedField) {
            $postingData[$hardcodedField->field] = $hardcodedField->value;
        }

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

        DB::transaction(function() use (
            $soi, $pathId, $userData, $postingData, $platform,
            $delivered, $responseBody, $questionId, $answerId,
            $utmSource
        ) {
            $lead = new Lead();
            $lead->status = (
                $responseBody ?
                ($delivered ? 'Delivered' : 'Rejected') :
                'Flagged'
            );
            $lead->advertiser = $soi->advertiser->title;
            $lead->advertiser_id = $soi->advertiser->id;
            $lead->soi = $soi->admin_label;
            $lead->soi_id = $soi->id;
            $lead->path_id = $pathId;
            $lead->cost = $soi->cost;
            $lead->posting_data = json_encode($postingData);
            $lead->platform = $platform;
            $lead->submission_response = $responseBody;
            $lead->question_id = $questionId;
            $lead->answer_id = $answerId;
            $lead->traffic_source = $utmSource;
            $lead->save();

            $leadUser = new LeadUser();
            $leadUser->fill([
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'zip_code' => $userData['zip_code'],
                'address' => $userData['address'],
                'city' => $userData['city'],
                'state' => $userData['state'],
                'birthday' => DateTime::createFromFormat(
                    'Y-n-j',
                    sprintf('%d-%d-%d', $userData['year'], $userData['month'], $userData['date'])
                ),
                'phone' => $userData['phone'],
                'gender' => $userData['gender'],
                'email' => $userData['email'],
            ]);
            $leadUser->ip = request()->ip();
            $leadUser->lead_id = $lead->id;
            $leadUser->save();
        });

        if ($delivered) {
            $revenue = $request->session()->get(Config::get('constants.sois_revenue_session'), 0);
            $revenue += $soi->cost;
            $request->session()->put(Config::get('constants.sois_revenue_session'), $revenue);

            $revenueBucket = $request->session()->get(Config::get('constants.revenue_bucket_session'), 0);
            $revenueBucket += $soi->cost;
            $request->session()->put(Config::get('constants.revenue_bucket_session'), $revenueBucket);
        }

        return response()->json([
            'success' => true,
            'delivered' => $delivered,
        ]);
    }

    /**
     * Find path by slug and return its linkouts
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $pathSlug
     * @return \Illuminate\Http\Response
     */
    public function getPathLinkouts(Request $request, $pathSlug) {
        $response = [
            'success' => false,
        ];

        $request->session()->put(Config::get('constants.linkouts_revenue_session'), 0);

        $userData = $request->get('user');
        $surveyData = $request->get('survey_data');

        $path = Path::where('slug', $pathSlug)->first();
        if (!$path) {
            $response['error'] = 'Path not found';
            return response()->json($response, 404);
        }

        // Return all Survey questions, follow-up questions and all answers
        $pathLinkouts = $path->pathLinkouts()->with([
            'linkout',
            'linkout.prePingConfig',
            'linkout.activeTimesCapsFilter',
            'linkout.demographicFilters',
            'linkout.platformFilter',
            'linkout.conditionals',
            'linkout.urlParams',
            'linkout.hardcodedUrlParams',
            'linkout.blacklists',
            'linkout.whitelists',
        ])->whereHas('linkout', function($q) {
            $q->where('enabled', true);
        })->orderBy('id', 'asc')->get();

        $linkouts = [];
        $count = 0;
        foreach ($pathLinkouts as $pathLinkout) {
            if (!$pathLinkout->linkout->checkConditionals($surveyData)) {
                continue;
            }
            if (!$pathLinkout->linkout->checkOfferCaps()) {
                continue;
            }
            if ($pathLinkout->linkout->isPrePingRequired()) {
                // Skip filter check for doing it later in pre-ping API
                $linkouts[] = [
                    'id' => $pathLinkout->linkout->id,
                    'pre_ping' => true,
                ];
            } else {
                if (!FilterService::checkFilters($pathLinkout->linkout, $userData)) {
                    continue;
                }
                $linkouts[] = collect($pathLinkout->linkout->toArray())->except(
                    'pre_ping_config',
                    'active_times_caps_filter',
                    'demographic_filters',
                    'blacklists',
                    'whitelists'
                );
            }

            $count++;
            if ($path->max_linkouts_shown && $count >= $path->max_linkouts_shown) {
                break;
            }
        }

        $response['linkouts'] = $linkouts;

        $response['success'] = true;
        return response()->json($response);
    }

    /**
     * Validate zip code that user enters in registration form
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function validateZipCode(Request $request) {
        $client = new Client([
            'timeout'  => 15,
        ]);
        $_response = $client->get("http://www.geonames.org/postalCodeLookupJSON", [
            'query' => array(
                'country' => 'US',
                'callback' => '',
                'postalcode' => $request->get('zip_code'),
            ),
        ]);
        $bodyObj = $_response->getBody();
        $response = (string)$bodyObj;
        $response = substr($response, 1, strlen($response) - 3);
        return response($response);
    }

    public function prePing(Request $request, $pathSlug) {
        $response = [
            'success' => false,
        ];

        $path = Path::where('slug', $pathSlug)->first();
        if (!$path) {
            $response['error'] = 'Path not found';
            return response()->json($response, 400);
        }

        $userData = $request->get('user');

        $sois = SimpleOptIn::with([
            'prePingConfig',
            'prePingConfig.prePingUrlParams',
            'platformFilter',
            'postingFields',
            'hardcodedFields',
            'questions.answers',
        ])->whereHas('prePingConfig', function($q) {
            $q->where('enabled', true);
        })->get();

        $pathLinkouts = $path->pathLinkouts()->with([
            'linkout',
            'linkout.prePingConfig',
            'linkout.prePingConfig.prePingUrlParams',
            'linkout.platformFilter',
            'linkout.conditionals',
            'linkout.urlParams',
            'linkout.hardcodedUrlParams',
        ])->whereHas('linkout', function($q) {
            $q->where('enabled', true);
        })->whereHas('linkout.prePingConfig', function($q) {
            $q->where('enabled', true);
        })->get();

        $client = new Client([
            'timeout'  => 30,
        ]);
        $promises = [];
        foreach ($sois as $soi) {
            $data = $this->preparePrePingData($userData, $soi->prePingConfig->prePingUrlParams);
            if ($soi->prePingConfig->method == 'get') {
                $promises['soi_' . $soi->id] = $client->getAsync($soi->prePingConfig->url, [
                    'query' => $data,
                ]);
            } else {
                $promises['soi_' . $soi->id] = $client->getAsync($soi->prePingConfig->url, [
                    'form_params' => $data,
                ]);
            }
        }
        foreach ($pathLinkouts as $pathLinkout) {
            $linkout = $pathLinkout->linkout;
            $data = $this->preparePrePingData($userData, $linkout->prePingConfig->prePingUrlParams);
            if ($linkout->prePingConfig->method == 'get') {
                $promises['linkout_' . $linkout->id] = $client->getAsync($linkout->prePingConfig->url, [
                    'query' => $data,
                ]);
            } else {
                $promises['linkout_' . $linkout->id] = $client->getAsync($linkout->prePingConfig->url, [
                    'form_params' => $data,
                ]);
            }
        }

        $results = Promise\settle($promises)->wait();

        $response['sois'] = [];
        $response['linkouts'] = [];
        foreach ($sois as $soi) {
            if ($results['soi_' . $soi->id]['state'] == 'fulfilled') {
                $apiResponse = $results['soi_' . $soi->id]['value'];
                $bodyObj = $apiResponse->getBody();
                $body = (string)$bodyObj;
                if (
                    ($body == $soi->prePingConfig->success_response && !$soi->prePingConfig->positive) ||
                    ($body != $soi->prePingConfig->success_response && $soi->prePingConfig->positive)
                ) {
                    if (!FilterService::checkFilters($soi, $userData)) {
                        continue;
                    }
                    if (!$soi->checkOfferCaps()) {
                        continue;
                    }
                    $response['sois'][] = collect($soi->toArray())->except('pre_ping_config');
                }
            }
        }
        foreach ($pathLinkouts as $pathLinkout) {
            $linkout = $pathLinkout->linkout;
            if ($results['linkout_' . $linkout->id]['state'] == 'fulfilled') {
                $apiResponse = $results['linkout_' . $linkout->id]['value'];
                $bodyObj = $apiResponse->getBody();
                $body = (string)$bodyObj;
                if (
                    ($body == $linkout->prePingConfig->success_response && !$linkout->prePingConfig->positive) ||
                    ($body != $linkout->prePingConfig->success_response && $linkout->prePingConfig->positive)
                ) {
                    if (!FilterService::checkFilters($linkout, $userData)) {
                        continue;
                    }
                    $response['linkouts'][] = collect($linkout->toArray())->except('pre_ping_config');
                }
            }
        }

        $response['success'] = true;
        return response()->json($response);
    }

    /**
     * Record SOI skips
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function recordSOISkip(Request $request) {
        $data = $request->all();
        $data['birthday'] = DateTime::createFromFormat(
            'Y-n-j',
            sprintf('%d-%d-%d', $data['year'], $data['month'], $data['date'])
        );

        $soiSkip = new SoiSkip();
        $soiSkip->fillData($data);

        $soiSkip->advertiser_id = $soiSkip->simpleOptIn->advertiser_id;
        $soiSkip->save();

        return response(['success' => true]);
    }

    /**
     * Record linkout skips
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function recordLinkoutSkip(Request $request) {
        $data = $request->all();
        $data['birthday'] = DateTime::createFromFormat(
            'Y-n-j',
            sprintf('%d-%d-%d', $data['year'], $data['month'], $data['date'])
        );

        $linkoutSkip = new LinkoutSkip();
        $linkoutSkip->fillData($data);

        $linkoutSkip->advertiser_id = $linkoutSkip->linkout->advertiser_id;
        $linkoutSkip->save();

        return response(['success' => true]);
    }

    /**
     * Record progress to end from users
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $pathSlug
     * @return \Illuminate\Http\Response
     */
    public function recordProgressToEnd(Request $request) {
        $pathId = $request->get('path_id');
        $utmSource = $request->get('utm_source');

        $progressToEnd = new ProgressToEnd([
            'ip' => $this->getIp(),
            'traffic_source' => $utmSource,
        ]);
        $progressToEnd->path_id = $pathId;
        $progressToEnd->save();

        return response()->json([
            'success' => true,
        ]);
    }

    protected function getIp() {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip); // just to be safe
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return request()->ip();
    }
}
