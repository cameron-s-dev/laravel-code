<?php

namespace App\Http\Controllers\Api;

use DateInterval;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use DB;
use App\Advertiser;
use App\Conversion;
use App\ConversionUser;
use App\Linkout;
use App\Visit;


/**
 * Some notes:
 * - updated_at field (rather than created_at) is used for filtering conversions and conversions because
 *  + declined conversions can be submitted and delivered later
 *  + for CPA conversions, actual conversion is done by pixel tracking which
 *    updates conversion
 */
class ReportLinkoutController extends Controller
{
    /**
     * Return daily statistics data of revenue from Linkout grouped by advertisers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyLinkoutRevenue(Request $request)
    {
        $filters = $this->makeFilters($request);

        $data = [];
        $advertisers = Advertiser::has('linkouts')->get();
        foreach ($advertisers as $advertiser) {
            $data[$advertiser->id] = [
                'skip_percent' => 0,
                'click_percent' => 0,
                'cpa_conversion_percent' => 0,
                'revenue' => 0,
                'impressions' => 0,
                'eCPM' => 0,
                'clicks' => 0,
            ];
        }

        // Conversions and impressions
        $totalCPAConversions = $this->getConversionsQuery($filters)
            ->where('conversions.type', 'cpa')
            ->select('linkouts.advertiser_id as advertiser_id', DB::raw('count(conversions.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        $totalClicks = $this->getConversionsQuery($filters)
            ->select('linkouts.advertiser_id as advertiser_id', DB::raw('count(conversions.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        $totalSkipCounts = $this->getLinkoutSkipsQuery($filters)
            ->select('linkout_skips.advertiser_id as advertiser_id', DB::raw('count(linkout_skips.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        foreach ($advertisers as $advertiser) {
            $clicks = empty($totalClicks[$advertiser->id]) ? 0 : $totalClicks[$advertiser->id];
            $skips = empty($totalSkipCounts[$advertiser->id]) ? 0 : $totalSkipCounts[$advertiser->id];
            $cpaConversions = empty($totalCPAConversions[$advertiser->id]) ? 0 : $totalCPAConversions[$advertiser->id];
            $impressions = $clicks + $skips;

            $data[$advertiser->id]['impressions'] = $impressions;
            $data[$advertiser->id]['clicks'] = $clicks;
            if ($impressions > 0) {
                $data[$advertiser->id]['click_percent'] = $clicks / $impressions * 100;
                $data[$advertiser->id]['skip_percent'] = $skips / $impressions * 100;
            }
            if ($clicks > 0) {
                $data[$advertiser->id]['cpa_conversion_percent'] = $cpaConversions / $impressions * 100;
            }
        }

        // Revenues
        $sums = $this->getConversionsQuery($filters)
            ->select('linkouts.advertiser_id as advertiser_id', DB::raw('sum(conversions.cost) as sum'))
            ->get()
            ->pluck('sum', 'advertiser_id');
        foreach ($advertisers as $advertiser) {
            if (!empty($sums[$advertiser->id])) {
                $data[$advertiser->id]['revenue'] = $sums[$advertiser->id];
                if (!empty($data[$advertiser->id]['impressions'])) {
                    $data[$advertiser->id]['eCPM'] = $data[$advertiser->id]['revenue'] / $data[$advertiser->id]['impressions'] / 1000;
                }
            }
        }

        return $data;
    }

    /**
     * Return overall statistics data of revenue from Linkout grouped by advertisers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallLinkoutRevenue(Request $request)
    {
        $data = [];
        $advertisers = Advertiser::has('linkouts')->get();
        foreach ($advertisers as $advertiser) {
            $data[$advertiser->id] = [
                'monthly' => 0,
                'total' => 0,
            ];
        }

        $totalRevenues = $this->getConversionsQuery()
            ->select('linkouts.advertiser_id as advertiser_id', DB::raw('sum(conversions.cost) as sum'))
            ->get()
            ->pluck('sum', 'advertiser_id');
        $datesQuery = $this->getConversionsQuery()
            ->select(
                'linkouts.advertiser_id as advertiser_id',
                DB::raw('min(conversions.updated_at) as earliest'),
                DB::raw('max(conversions.updated_at) as latest')
            )
            ->get();
        $earliestDates = $datesQuery->pluck('earliest', 'advertiser_id');
        $latestDates = $datesQuery->pluck('latest', 'advertiser_id');

        $today = new \DateTime();
        foreach ($advertisers as $advertiser) {
            if (!empty($totalRevenues[$advertiser->id])) {
                $data[$advertiser->id]['total'] = $totalRevenues[$advertiser->id];
                $dateDiff = date_diff(new DateTime($latestDates[$advertiser->id]), new DateTime($earliestDates[$advertiser->id]));
                $months = $dateDiff->y * 12 + $dateDiff->m + 1;
                $data[$advertiser->id]['monthly'] = $data[$advertiser->id]['total'] / $months;
            }
        }

        return $data;
    }

    /**
     * Return daily statistics data of revenue from Linkout for an advertiser grouped by campaigns(=Linkouts)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyLinkoutRevenueByCampaign(Request $request)
    {
        $filters = $this->makeFilters($request);
        $advertiserId = $request->get('advertiser_id');

        $data = [];
        $linkouts = Linkout::where('advertiser_id', $advertiserId)->get();
        foreach ($linkouts as $linkout) {
            $data[$linkout->id] = [
                'skip_percent' => 0,
                'click_percent' => 0,
                'cpa_conversion_percent' => 0,
                'revenue' => 0,
                'impressions' => 0,
                'eCPM' => 0,
                'clicks' => 0,
            ];
        }

        // Conversions and impressions
        $totalCPAConversions = $this->getConversionsQuery($filters, 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('conversions.type', 'cpa')
            ->select('conversion_object_id as linkout_id', DB::raw('count(conversions.id) as count'))
            ->get()
            ->pluck('count', 'linkout_id');
        $totalClicks = $this->getConversionsQuery($filters, 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->select('conversion_object_id as linkout_id', DB::raw('count(conversions.id) as count'))
            ->get()
            ->pluck('count', 'linkout_id');
        $totalSkipCounts = $this->getLinkoutSkipsQuery($filters, 'linkout_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->select('linkout_skips.linkout_id as linkout_id', DB::raw('count(linkout_skips.id) as count'))
            ->get()
            ->pluck('count', 'linkout_id');
        foreach ($linkouts as $linkout) {
            $clicks = empty($totalClicks[$linkout->id]) ? 0 : $totalClicks[$linkout->id];
            $skips = empty($totalSkipCounts[$linkout->id]) ? 0 : $totalSkipCounts[$linkout->id];
            $cpaConversions = empty($totalCPAConversions[$linkout->id]) ? 0 : $totalCPAConversions[$linkout->id];
            $impressions = $clicks + $skips;

            $data[$linkout->id]['impressions'] = $impressions;
            $data[$linkout->id]['clicks'] = $clicks;
            if ($impressions > 0) {
                $data[$linkout->id]['click_percent'] = $clicks / $impressions * 100;
                $data[$linkout->id]['skip_percent'] = $skips / $impressions * 100;
            }
            if ($clicks > 0) {
                $data[$linkout->id]['cpa_conversion_percent'] = $cpaConversions / $impressions * 100;
            }
        }

        // Revenues
        $sums = $this->getConversionsQuery($filters, 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->select('conversion_object_id as linkout_id', DB::raw('sum(conversions.cost) as sum'))
            ->get()
            ->pluck('sum', 'linkout_id');

        foreach ($linkouts as $linkout) {
            if (!empty($sums[$linkout->id])) {
                $data[$linkout->id]['revenue'] = $sums[$linkout->id];
                if (!empty($data[$linkout->id]['impressions'])) {
                    $data[$linkout->id]['eCPM'] = $data[$linkout->id]['revenue'] / $data[$linkout->id]['impressions'] / 1000;
                }
            }
        }

        return $data;
    }

    /**
     * Return overall statistics data of revenue from Linkout for an advertiser grouped by campaigns(=Linkouts)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallLinkoutRevenueByCampaign(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');

        $data = [];
        $linkouts = Linkout::where('advertiser_id', $advertiserId)
            ->with('activeTimesCapsFilter')
            ->get();
        foreach ($linkouts as $linkout) {
            $data[$linkout->id] = [
                'daily_cap' => $linkout->activeTimesCapsFilter->caps_daily,
                'monthly' => 0,
                'total' => 0,
            ];
        }

        $totalRevenues = $this->getConversionsQuery([], 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->select('conversion_object_id as linkout_id', DB::raw('sum(conversions.cost) as sum'))
            ->get()
            ->pluck('sum', 'linkout_id');
        $datesQuery = $this->getConversionsQuery([], 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->select(
                'conversion_object_id as linkout_id',
                DB::raw('min(conversions.updated_at) as earliest'),
                DB::raw('max(conversions.updated_at) as latest')
            )
            ->get();
        $earliestDates = $datesQuery->pluck('earliest', 'linkout_id');
        $latestDates = $datesQuery->pluck('latest', 'linkout_id');

        $today = new \DateTime();
        foreach ($linkouts as $linkout) {
            if (!empty($totalRevenues[$linkout->id])) {
                $data[$linkout->id]['total'] = $totalRevenues[$linkout->id];
                $dateDiff = date_diff(new DateTime($latestDates[$linkout->id]), new DateTime($earliestDates[$linkout->id]));
                $months = $dateDiff->y * 12 + $dateDiff->m + 1;
                $data[$linkout->id]['monthly'] = $data[$linkout->id]['total'] / $months;
            }
        }

        return $data;
    }

    /**
     * Return daily statistics data of revenue from Linkout for a filtered segment
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyLinkoutRevenueBySegment(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');
        $linkoutId = $request->get('linkout_id');
        $filters = $this->makeFilters($request);

        $data = [
            'skip_percent' => 0,
            'click_percent' => 0,
            'cpa_conversion_percent' => 0,
            'revenue' => 0,
            'impressions' => 0,
            'eCPM' => 0,
            'clicks' => 0,
        ];

        // Conversions and impressions
        $totalCPAConversions = $this->getConversionsQueryForSegment($filters)
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkouts.id', $linkoutId)
            ->where('conversions.type', 'cpa')
            ->select(DB::raw('count(conversions.id) as count'))
            ->get();
        $totalClicks = $this->getConversionsQueryForSegment($filters, 'conversion_object_id')
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkouts.id', $linkoutId)
            ->select(DB::raw('count(conversions.id) as count'))
            ->get();
        $totalSkipCounts = $this->getLinkoutSkipsQueryForSegment($filters)
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkout_skips.linkout_id', $linkoutId)
            ->select(DB::raw('count(linkout_skips.id) as count'))
            ->get();
        $clicks = empty($totalClicks[0]->count) ? 0 : $totalClicks[0]->count;
        $skips = empty($totalSkipCounts[0]->count) ? 0 : $totalSkipCounts[0]->count;
        $cpaConversions = empty($totalCPAConversions[0]->count) ? 0 : $totalCPAConversions[0]->count;
        $impressions = $clicks + $skips;

        $data['impressions'] = $impressions;
        $data['clicks'] = $clicks;
        if ($impressions > 0) {
            $data['click_percent'] = $clicks / $impressions * 100;
            $data['skip_percent'] = $skips / $impressions * 100;
        }
        if ($clicks > 0) {
            $data['cpa_conversion_percent'] = $cpaConversions / $impressions * 100;
        }

        // Revenues
        $sums = $this->getConversionsQueryForSegment($filters)
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkouts.id', $linkoutId)
            ->select(DB::raw('sum(conversions.cost) as sum'))
            ->get();

        if (!empty($sums[0]->sum)) {
            $data['revenue'] = floatval($sums[0]->sum);
            if (!empty($data['impressions'])) {
                $data['eCPM'] = $data['revenue'] / $data['impressions'] / 1000;
            }
        }

        return $data;
    }

    /**
     * Return overall statistics data of revenue from Linkout for a filtered segment
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallLinkoutRevenueBySegment(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');
        $linkoutId = $request->get('linkout_id');
        $filters = $this->makeFilters($request);

        $data = [
            'monthly' => 0,
            'total' => 0,
        ];

        $totalRevenues = $this->getConversionsQueryForSegment($filters)
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkouts.id', $linkoutId)
            ->select(DB::raw('sum(conversions.cost) as sum'))
            ->get();
        $dates = $this->getConversionsQueryForSegment([])
            ->where('linkouts.advertiser_id', $advertiserId)
            ->where('linkouts.id', $linkoutId)
            ->select(DB::raw('min(conversions.updated_at) as earliest'), DB::raw('max(conversions.updated_at) as latest'))
            ->get();

        $data['total'] = floatval($totalRevenues[0]->sum);
        if ($dates[0]->earliest) {
            $today = new \DateTime();
            $dateDiff = date_diff(new DateTime($dates[0]->latest), new DateTime($dates[0]->earliest));
            $months = $dateDiff->y * 12 + $dateDiff->m + 1;
            $data['monthly'] = $data['total'] / $months;
        }

        return $data;
    }

    /**
     * Create filters array from request parameters
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function makeFilters($request) {
        $filters = [];

        $range = $request->get('range');
        if ($range) {
            $rangeFrom = date_create_from_format('Y-m-d', $range[0]);
            $rangeFrom->setTime(0, 0, 0);
            $filters['from'] = $rangeFrom;
            $rangeTo = date_create_from_format('Y-m-d', $range[1]);
            $rangeTo->setTime(23, 59, 59);
            $filters['to'] = $rangeTo;
        }

        $pathIds = $request->get('path_ids');
        if ($pathIds && count($pathIds) > 0) {
            $filters['path_ids'] = $pathIds;
        }

        $ownerIds = $request->get('owner_ids');
        if ($ownerIds && count($ownerIds) > 0) {
            $filters['owner_ids'] = $ownerIds;
        }

        $segmentFilters = $request->get('filters');
        if ($segmentFilters) {
            // gender filter
            $filters['gender'] = [];
            if ($segmentFilters['male']) {
                $filters['gender'][] = 'male';
            }
            if ($segmentFilters['female']) {
                $filters['gender'][] = 'female';
            }
            if (!$segmentFilters['male'] && !$segmentFilters['female']) {
                $filters['gender'] = ['male', 'female'];
            }

            // platform filter
            $filters['platform'] = [];
            if ($segmentFilters['desktop']) {
                $filters['platform'][] = 'desktop';
            }
            if ($segmentFilters['mobile']) {
                $filters['platform'][] = 'ios';
                $filters['platform'][] = 'android';
                $filters['platform'][] = 'mobile';
            }
            if (!$segmentFilters['desktop'] && !$segmentFilters['mobile']) {
                $filters['platform'] = ['desktop', 'ios', 'android', 'mobile'];
            }

            $filters['age_range'] = $segmentFilters['ageRange'];
            
            // geo filter (comma-separated strings of zip codes and/or states)
            $filters['zip_codes'] = [];
            $filters['states'] = [];
            $geoFilters = explode(',', $segmentFilters['geo']);
            foreach ($geoFilters as $geoFilter) {
                $gf = trim($geoFilter);
                if (!$gf) {
                    continue;
                }
                if (preg_match('/^\d+$/', $gf)) {
                    $filters['zip_codes'][] = $gf;
                } else {
                    $filters['states'][] = $gf;
                }
            }
        }

        return $filters;
    }

    /**
     * Return base conversions query with range-filtering and grouping by advertiser or linkout
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getConversionsQuery($filters = [], $groupBy = 'advertiser_id') {
        $query = DB::table('conversions')
            ->join('linkouts', function($join) {
                $join->on('conversions.conversion_object_id', '=', 'linkouts.id')
                     ->on('conversions.conversion_object_type', '=', DB::raw('"linkout"'));
            });
        if (!empty($filters['from'])) {
            $query = $query->where('conversions.updated_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('conversions.updated_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('linkouts.owner_id', $filters['owner_ids']);
        }
        $query = $query->groupBy($groupBy);
        return $query;
    }

    /**
     * Return base linkout skips query with range-filtering and grouping by advertiser or linkout
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getLinkoutSkipsQuery($filters = [], $groupBy = 'advertiser_id') {
        $query = DB::table('linkout_skips')
            ->join('linkouts', 'linkouts.id', '=', 'linkout_skips.linkout_id');
        if (!empty($filters['from'])) {
            $query = $query->where('linkout_skips.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('linkout_skips.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('linkouts.owner_id', $filters['owner_ids']);
        }
        $query = $query->groupBy($groupBy);
        return $query;
    }

    /**
     * Return base conversions query with range and segment filtering
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getConversionsQueryForSegment($filters = []) {
        $query = DB::table('conversions')
            ->join('conversion_users', 'conversion_users.id', '=', 'conversions.conversion_user_id')
            ->join('linkouts', function($join) {
                $join->on('conversions.conversion_object_id', '=', 'linkouts.id')
                     ->on('conversions.conversion_object_type', '=', DB::raw('"linkout"'));
            });
        if (!empty($filters['from'])) {
            $query = $query->where('conversions.updated_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('conversions.updated_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('linkouts.owner_id', $filters['owner_ids']);
        }
        if (!empty($filters['gender'])) {
            $query = $query->whereIn('conversion_users.gender', $filters['gender']);
        }
        if (!empty($filters['platform'])) {
            $query = $query->whereIn('platform', $filters['platform']);
        }
        if (!empty($filters['age_range'])) {
            $from = Carbon::now()->subYears($filters['age_range'][1]);
            $to = Carbon::now()->subYears($filters['age_range'][0])->addYear()->subDay();
            $query = $query->whereBetween('conversion_users.birthday', [$from, $to]);
        }
        if (!empty($filters['zip_codes']) && !empty($filters['states'])) {
            $query = $query->where(function ($query) use ($filters) {
                $query->whereIn('conversion_users.zip_code', $filters['zip_codes'])
                    ->orWhereIn('conversion_users.state', $filters['states']);
            });
        } else if (!empty($filters['zip_codes'])) {
            $query = $query->where('conversion_users.zip_code', $filters['zip_codes']);
        } else if (!empty($filters['states'])) {
            $query = $query->where('conversion_users.state', $filters['states']);
        }
        return $query;
    }

    /**
     * Return base linkout skips query with range and segment filtering
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getLinkoutSkipsQueryForSegment($filters = []) {
        $query = DB::table('linkout_skips')
            ->join('linkouts', 'linkouts.id', '=', 'linkout_skips.linkout_id');
        if (!empty($filters['from'])) {
            $query = $query->where('linkout_skips.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('linkout_skips.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('linkouts.owner_id', $filters['owner_ids']);
        }
        if (!empty($filters['gender'])) {
            $query = $query->whereIn('gender', $filters['gender']);
        }
        if (!empty($filters['platform'])) {
            $query = $query->whereIn('platform', $filters['platform']);
        }
        if (!empty($filters['age_range'])) {
            $from = Carbon::now()->subYears($filters['age_range'][1]);
            $to = Carbon::now()->subYears($filters['age_range'][0])->addYear()->subDay();
            $query = $query->whereBetween('birthday', [$from, $to]);
        }
        if (!empty($filters['zip_codes']) && !empty($filters['states'])) {
            $query = $query->where(function ($query) use ($filters) {
                $query->whereIn('zip_code', $filters['zip_codes'])
                    ->orWhereIn('state', $filters['states']);
            });
        } else if (!empty($filters['zip_codes'])) {
            $query = $query->where('zip_code', $filters['zip_codes']);
        } else if (!empty($filters['states'])) {
            $query = $query->where('state', $filters['states']);
        }
        return $query;
    }
}
