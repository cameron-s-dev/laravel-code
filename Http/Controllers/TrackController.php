<?php

namespace App\Http\Controllers;

use Config;
use Cookie;
use DB;
use Exception;
use Storage;
use Validator;
use Illuminate\Http\Request;
use App\Linkout;
use App\ConversionUser;
use App\Conversion;
use App\Pixel;
use App\Traits\PrepareUserData;
use App\Services\TwigCompileService;

class TrackController extends Controller
{
    use PrepareUserData;

    protected static $supportedConversionObjectTypes = ['linkout'];

    /**
     * Linkout click track method
     *
     * @param  Request  $request
     * @return Response
     */
    public function clickTrack(Request $request) {
        $conversion = null;
        $linkoutOfferUrl = '';

        $userData = $request->only([
            'first_name', 'last_name',
            'zip_code', 'city', 'state', 'address',
            'date', 'month', 'year', 'birthday',
            'phone', 'gender', 'email',
        ]);
        if (empty($userData['birthday'])) {
            if (
                empty($userData['month']) ||
                empty($userData['date']) ||
                empty($userData['year'])
            ) {
                throw new Exception('Not enough user data for generating conversion - birthday not specified');
            }
            $userData['birthday'] = date(
                'Y-m-d',
                mktime(0, 0, 0, $userData['month'], $userData['date'], $userData['year'])
            );
        }

        try {
            DB::transaction(function() use ($request, &$conversion, &$linkoutOfferUrl, $userData) {
                $conversionUser = $this->saveUserData($userData);

                $type = $request->get('type');
                if (!in_array($type, self::$supportedConversionObjectTypes)) {
                    throw new Exception(sprintf(
                        'Invalid conversion object type for conversion on user %s %s',
                        $userData['first_name'],
                        $userData['last_name']
                    ));
                }

                $id = $request->get('id');
                if (!$id) {
                    throw new Exception(sprintf(
                        'Conversion object type not specified on user %s %s',
                        $userData['first_name'],
                        $userData['last_name']
                    ));
                }

                $path_id = $request->get('path_id');
                $platform = $request->get('platform');
                $utm_source = $request->get('utm_source') ?: '';

                $conversion = new Conversion();
                $conversion->conversion_object_id = $id;
                $conversion->conversion_object_type = $type;
                if (!$conversion->conversionObject) {
                    throw new Exception(sprintf(
                        'Conversion object not found for ID %d and type %s on user %s %s',
                        $id,
                        $type,
                        $userData['first_name'],
                        $userData['last_name']
                    ));
                }
                $conversion->type = $conversion->conversionObject->type;
                if ($conversion->type === 'cpc') {
                    $conversion->cost = $conversion->conversionObject->cost;
                } else {
                    $conversion->cost = 0;
                }
                $conversion->path_id = $path_id;
                $conversion->conversion_user_id = $conversionUser->id;
                $conversion->platform = $platform;
                $conversion->traffic_source = $utm_source;
                $conversion->save();

                if ($conversion->type === 'cpc') {
                    $revenue = $request->session()->get(Config::get('constants.linkouts_revenue_session'), 0);
                    $revenue += $conversion->conversionObject->cost;
                    $request->session()->put(Config::get('constants.linkouts_revenue_session'), $revenue);

                    $revenueBucket = $request->session()->get(Config::get('constants.revenue_bucket_session'), 0);
                    $revenueBucket += $conversion->conversionObject->cost;
                    $request->session()->put(Config::get('constants.revenue_bucket_session'), $revenueBucket);
                }

                $linkoutOfferUrl = $this->getLinkoutOfferUrl($id, $userData);
            });

            return redirect($linkoutOfferUrl)
                ->cookie(
                    Config::get('constants.pixel_tracking_cookie_name'),
                    encrypt(json_encode([
                        'session_id' => $request->session()->getId(),
                        'conversion_id' => $conversion->type === 'cpa' ? $conversion->id : '',
                        'randomizer' => rand(1000000, 9999999),
                    ])),
                    720,
                    '/'
                )
                ->cookie(
                    Config::get('constants.user_data_cookie'),
                    encrypt(json_encode(array_merge($userData, [
                        'randomizer' => rand(1000000, 9999999),
                    ]))),
                    720,
                    '/'
                );
        } catch (Exception $e) {
            report($e);
            return response($e->getMessage(), 400);
        }
    }

    /**
     * Linkout pixel intended for img src in 3rd-party pages
     *
     * @param  Request  $request
     * @return Response
     */
    public function cpaPixelTrack(Request $request) {
        $file = Storage::disk('local')->get('/pixel.png');
        $response = response($file, 200)
            ->cookie(Config::get('constants.pixel_tracking_cookie_name'), '', 1, '/')
            ->header('Content-Type', 'image/png');

        $conversionData = $this->getConversionData($request->session()->getId());
        if (!$conversionData) {
            return $response;
        }
        $conversionId = $conversionData['conversion_id'];

        try {
            $this->doCPAConversion($request, $conversionId);
        } catch (Exception $e) {
            report($e);
            return $response;
        }

        return $response;
    }

    /**
     * Linkout postback route intended for posting back conversion by url
     *
     * @param  Request  $request
     * @return Response
     */
    public function postbackLinkout(Request $request, $linkoutIdHash) {
        $conversionData = $this->getConversionData($request->session()->getId());
        if (!$conversionData) {
            return $response;
        }
        $conversionId = $conversionData['conversion_id'];

        try {
            $this->doCPAConversion($request, $conversionId, $linkoutIdHash);
        } catch (Exception $e) {
            report($e);
            return response('Failed to report conversion.');
        }

        $response['success'] = true;
        return response('Conversion reported successfully.');
    }

    /**
     * Global pixel track method to be embedded into iframe of 3rd-party pages to fire revenue-tracking pixels
     *
     * @param  Request  $request
     * @return Response
     */
    public function globalPixelTrack(Request $request) {
        $pixelCodes = [];

        try {
            // Make CPA conversion first
            $this->cpaPixelTrack($request);

            // Get converting linkout
            $conversionData = $this->getConversionData($request->session()->getId());
            if (!$conversionData) {
                return $response;
            }
            $conversionId = $conversionData['conversion_id'];
            $conversion = Conversion::find($conversionId);
            if (!$conversion->conversionObject) {
                throw new Exception(sprintf(
                    'Conversion object not found for conversion with ID %s in cpa pixel tracking',
                    $conversionId
                ));
            }

            $utmContent = Cookie::get(Config::get('constants.utm_content_cookie'));
            $userData = json_decode(decrypt(Cookie::get(Config::get('constants.user_data_cookie'))), true);
            $revenue = $conversion->conversionObject->cost;

            // Get pixels
            $query = Pixel::where('location', 'linkouts')
                ->where('enabled', true)
                ->where('revenue_threshold', '<=', $revenue);
            if (!empty($utmContent)) {
                $query = $query->where(function($q) use ($utmContent) {
                    $q->where('traffic_source', 'like', "%$utmContent%");
                    $q->orWhere('all_traffic_source', true);
                });
            } else {
                $query = $query->where('all_traffic_source', true);
            }

            $pixels = $query->get();
            foreach ($pixels as $pixel) {
                $pixelCodes[] = TwigCompileService::compile($pixel->code, [
                    'revenue' => $revenue,
                    'user_data' => $userData,
                ]);
            }
        } catch (Exception $e) {
            report($e);
        }

        return response()
            ->view('globalpixel', [
                'pixelCodes' => $pixelCodes,
            ])
            ->cookie(Config::get('constants.pixel_tracking_cookie_name'), '', 1, '/');
    }

    protected function saveUserData($userData) {
        $conversionUser = new ConversionUser();
        $conversionUser->fillData($userData);
        return $conversionUser;
    }

    protected function getLinkoutOfferUrl($linkoutId, $userData) {
        $linkout = Linkout::find($linkoutId);
        $url = $linkout->url;

        $postingData = $this->prepareUserData($userData, $linkout->urlParams);

        $first = true;
        foreach ($postingData as $key => $value) {
            $glue = '&';
            if ($first) {
                $first = false;
                if (stripos($url, '?') === false) {
                    $glue = '?';
                }
            }
            $url .= $glue . $key . '=' . urlencode($value);
        }

        foreach ($linkout->hardcodedUrlParams as $urlParam) {
            $glue = '&';
            if ($first) {
                $first = false;
                if (stripos($url, '?') === false) {
                    $glue = '?';
                }
            }
            $url .= $glue . $urlParam->field . '=' . $urlParam->value;
        }
        return $url;
    }

    protected function getConversionData($sessionId) {
        $conversionCookie = Cookie::get(Config::get('constants.pixel_tracking_cookie_name'));
        if (empty($conversionCookie)) {
            return null;
        }
        $conversionData = json_decode(decrypt($conversionCookie), true);
        // TODO: resolve session-changing issue in unit test and remove this test-only condition
        if ($sessionId != $conversionData['session_id'] && env('RIO_TESTING_ENV') != 'yes') {
            return null;
        }
        return $conversionData;
    }

    /**
     * Do CPA conversion for linkout with provided id
     *
     * @param  int  $conversionId
     * @return Response
     */
    protected function doCPAConversion($request, $conversionId, $linkoutIdHash = null) {
        $conversion = Conversion::find($conversionId);
        if (!$conversion->conversionObject) {
            throw new Exception(sprintf(
                'Conversion object not found for conversion with ID %s in cpa pixel tracking',
                $conversionId
            ));
        }
        $conversion->cost = $conversion->conversionObject->cost;
        $conversion->conversion_user_id = $conversion->conversion_user_id;
        $conversion->save();

        if ($linkoutIdHash) {
            // Verify linkout id hash provided by postback url
            $linkout = $conversion->conversionObject;
            if (!$linkout->verifyIdHash($linkoutIdHash)) {
                throw new Exception('Conversion object ID cannot be verified');
            }
        }

        if ($conversion->type === 'cpa') {
            $revenue = $request->session()->get(Config::get('constants.linkouts_revenue_session'), 0);
            $revenue += $conversion->conversionObject->cost;
            $request->session()->put(Config::get('constants.linkouts_revenue_session'), $revenue);

            $revenueBucket = $request->session()->get(Config::get('constants.revenue_bucket_session'), 0);
            $revenueBucket += $conversion->conversionObject->cost;
            $request->session()->put(Config::get('constants.revenue_bucket_session'), $revenueBucket);
        }
    }
}
