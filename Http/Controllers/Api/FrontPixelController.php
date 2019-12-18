<?php

namespace App\Http\Controllers\Api;

use Config;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Pixel;
use App\Services\TwigCompileService;

class FrontPixelController extends Controller
{
    /**
     * Return rendered pixels at specified location
     * Expected request payloads are:
     *  - location: location of pixel to be fired
     *  - utm_content: utm_content parameter of current path
     *  - revenue: revenue made in current path (for SOI or linkout pixels)
     *  - user_data: array of entered user information
     * For some locations (e.g. splash), revenue and/or user_data may not
     * be available thus not needed
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $pathSlug
     * @return \Illuminate\Http\Response
     */
    public function getPixels(Request $request) {
        $response = [
            'success' => false,
            'pixels' => [],
        ];

        // Validate payload values
        $location = $request->get('location');
        if (empty($location)) {
            $response['error'] = 'Location not specified';
            return response()->json($response, 400);
        }

        $utmContent = $request->get('utm_content');

        $context = [];

        $userData = $request->get('user_data');
        if ($location != 'splash' && empty($userData)) {
            $response['error'] = 'User data required for requested location';
            return response()->json($response, 400);
        } else {
            $context['user_data'] = $userData;
        }

        if ($location == 'survey') {
            $revenue = $request->session()->get(Config::get('constants.sois_revenue_session'), 0);
        } else if ($location == 'linkouts' || $location == 'post-linkout') {
            $revenue = $request->session()->get(Config::get('constants.linkouts_revenue_session'), 0);
        } else {
            $revenue = 0;
        }

        $revenueRequired = ($location != 'splash' && $location != 'registration');
        if ($revenueRequired && empty($revenue) && $revenue !== 0) {
            $response['error'] = 'Revenue value required for requested location';
            return response()->json($response, 400);
        } else {
            $context['revenue'] = $revenue;
        }

        // Get global pixels
        $query = $this->getPixelQuery($location, $utmContent);
        if ($revenueRequired) {
            $query = $query->where('revenue_threshold', '<=', $revenue);
        }

        $pixels = $query->get();
        foreach ($pixels as $pixel) {
            $response['pixels'][] = TwigCompileService::compile($pixel->code, $context);
        }

        // Get CPA partner pixels
        $query = $this->getPixelQuery($location, $utmContent, true);
        $pixels = $query->get();
        foreach ($pixels as $pixel) {
            if (!$pixel->cpa_value || !$pixel->cpa_margin_rate) {
                continue;
            }

            $revenueBucket = $request->session()->get(Config::get('constants.revenue_bucket_session'), 0);
            $pixelCPAamount = $pixel->cpa_value * (1 + $pixel->cpa_margin_rate);
            if ($revenueBucket >= $pixelCPAamount) {
                $context['revenue'] = $pixelCPAamount;
                $response['pixels'][] = TwigCompileService::compile($pixel->code, $context);
                $revenueBucket -= $pixelCPAamount;
                $request->session()->put(Config::get('constants.revenue_bucket_session'), $revenueBucket);
            }
        }

        $response['success'] = true;
        return response()->json($response);
    }

    /**
     * Get pixel db query
     *
     * @param   string      $location
     * @param   string      $utmContent
     * @param   bool        $cpaPartnerPixel
     */
    protected function getPixelQuery($location, $utmContent, $cpaPartnerPixel = false) {
        $query = Pixel::where('location', $location)
            ->where('is_cpa_partner_pixel', $cpaPartnerPixel)
            ->where('enabled', true);

        if (!empty($utmContent)) {
            $query = $query->where(function($q) use ($utmContent) {
                $q->where('traffic_source', 'like', "%$utmContent%");
                $q->orWhere('all_traffic_source', true);
            });
        } else {
            $query = $query->where('all_traffic_source', true);
        }

        return $query;
    }
}
