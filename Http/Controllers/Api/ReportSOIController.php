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
use App\Lead;
use App\LeadUser;
use App\SimpleOptIn;
use App\Visit;


/**
 * Some notes:
 * - updated_at field (rather than created_at) is used for filtering leads and conversions because
 *  + declined leads can be submitted and delivered later
 *  + for CPA conversions, actual conversion is done by pixel tracking which
 *    updates conversion
 */
class ReportSOIController extends Controller
{
    /**
     * Return daily statistics data of revenue from SOI grouped by advertisers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailySOIRevenue(Request $request)
    {
        $filters = $this->makeFilters($request);

        $data = [];
        $advertisers = Advertiser::has('sois')->get();
        foreach ($advertisers as $advertiser) {
            $data[$advertiser->id] = [
                'accepted' => 0,
                'rejected' => 0,
                'revenue' => 0,
                'impressions' => 0,
                'clicks' => 0,
                'click_percent' => 0,
                'conversions' => 0,
                'conversion_percent' => 0,
                'eCPM' => 0,
            ];
        }

        // Accepted
        $acceptedCounts = $this->getLeadsQuery($filters)
            ->where('status', 'Delivered')
            ->select('leads.advertiser_id as advertiser_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        foreach ($advertisers as $advertiser) {
            if (!empty($acceptedCounts[$advertiser->id])) {
                $data[$advertiser->id]['accepted'] = $acceptedCounts[$advertiser->id];
            }
        }

        // Rejected
        $rejectedCounts = $this->getLeadsQuery($filters)
            ->where('status', 'Rejected')
            ->select('leads.advertiser_id as advertiser_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        foreach ($advertisers as $advertiser) {
            if (!empty($rejectedCounts[$advertiser->id])) {
                $data[$advertiser->id]['rejected'] = $rejectedCounts[$advertiser->id];
            }
        }

        // Conversions and impressions
        $totalClicks = $this->getLeadsQuery($filters)
            ->select('leads.advertiser_id as advertiser_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        $totalSkipCounts = $this->getSOISkipsQuery($filters)
            ->select('soi_skips.advertiser_id as advertiser_id', DB::raw('count(soi_skips.id) as count'))
            ->get()
            ->pluck('count', 'advertiser_id');
        foreach ($advertisers as $advertiser) {
            $clicks = empty($totalClicks[$advertiser->id]) ? 0 : $totalClicks[$advertiser->id];
            $skips = empty($totalSkipCounts[$advertiser->id]) ? 0 : $totalSkipCounts[$advertiser->id];
            $conversions = empty($acceptedCounts[$advertiser->id]) ? 0 : $acceptedCounts[$advertiser->id];
            $impressions = $clicks + $skips;

            $data[$advertiser->id]['impressions'] = $impressions;
            $data[$advertiser->id]['clicks'] = $clicks;
            if ($impressions > 0) {
                $data[$advertiser->id]['click_percent'] = $clicks / $impressions * 100;
            }
            $data[$advertiser->id]['conversions'] = $conversions;
            if ($clicks > 0) {
                $data[$advertiser->id]['conversion_percent'] = $conversions / $impressions * 100;
            }
        }

        // Revenues
        $sums = $this->getLeadsQuery($filters)
            ->where('status', 'Delivered')
            ->select('leads.advertiser_id as advertiser_id', DB::raw('sum(leads.cost) as sum'))
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
     * Return overall statistics data of revenue from SOI grouped by advertisers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallSOIRevenue(Request $request)
    {
        $data = [];
        $advertisers = Advertiser::has('sois')->get();
        foreach ($advertisers as $advertiser) {
            $data[$advertiser->id] = [
                'monthly' => 0,
                'total' => 0,
            ];
        }

        $totalRevenues = $this->getLeadsQuery()
            ->where('status', 'Delivered')
            ->select('leads.advertiser_id as advertiser_id', DB::raw('sum(leads.cost) as sum'))
            ->get()
            ->pluck('sum', 'advertiser_id');
        $datesQuery = $this->getLeadsQuery()
            ->where('status', 'Delivered')
            ->select(
                'leads.advertiser_id as advertiser_id',
                DB::raw('min(leads.updated_at) as earliest'),
                DB::raw('max(leads.updated_at) as latest')
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
     * Return daily statistics data of revenue from SOI for an advertiser grouped by campaigns(=SOIs)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailySOIRevenueByCampaign(Request $request)
    {
        $filters = $this->makeFilters($request);
        $advertiserId = $request->get('advertiser_id');

        $data = [];
        $sois = SimpleOptIn::where('advertiser_id', $advertiserId)->get();
        foreach ($sois as $soi) {
            $data[$soi->id] = [
                'accepted' => 0,
                'rejected' => 0,
                'revenue' => 0,
                'impressions' => 0,
                'clicks' => 0,
                'click_percent' => 0,
                'conversions' => 0,
                'conversion_percent' => 0,
                'eCPM' => 0,
            ];
        }

        // Accepted
        $acceptedCounts = $this->getLeadsQuery($filters, 'soi_id')
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->select('leads.soi_id as soi_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'soi_id');
        foreach ($sois as $soi) {
            if (!empty($acceptedCounts[$soi->id])) {
                $data[$soi->id]['accepted'] = $acceptedCounts[$soi->id];
            }
        }

        // Rejected
        $rejectedCounts = $this->getLeadsQuery($filters, 'soi_id')
            ->where('status', 'Rejected')
            ->where('leads.advertiser_id', $advertiserId)
            ->select('leads.soi_id as soi_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'soi_id');
        foreach ($sois as $soi) {
            if (!empty($rejectedCounts[$soi->id])) {
                $data[$soi->id]['rejected'] = $rejectedCounts[$soi->id];
            }
        }

        // Conversions and impressions
        $totalClicks = $this->getLeadsQuery($filters, 'soi_id')
            ->where('leads.advertiser_id', $advertiserId)
            ->select('leads.soi_id as soi_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'soi_id');
        $totalSkipCounts = $this->getSOISkipsQuery($filters, 'soi_id')
            ->where('soi_skips.advertiser_id', $advertiserId)
            ->select('soi_skips.soi_id as soi_id', DB::raw('count(soi_skips.id) as count'))
            ->get()
            ->pluck('count', 'soi_id');
        foreach ($sois as $soi) {
            $clicks = empty($totalClicks[$soi->id]) ? 0 : $totalClicks[$soi->id];
            $skips = empty($totalSkipCounts[$soi->id]) ? 0 : $totalSkipCounts[$soi->id];
            $conversions = empty($acceptedCounts[$soi->id]) ? 0 : $acceptedCounts[$soi->id];
            $impressions = $clicks + $skips;

            $data[$soi->id]['impressions'] = $impressions;
            $data[$soi->id]['clicks'] = $clicks;
            if ($impressions > 0) {
                $data[$soi->id]['click_percent'] = $clicks / $impressions * 100;
            }
            $data[$soi->id]['conversions'] = $conversions;
            if ($clicks > 0) {
                $data[$soi->id]['conversion_percent'] = $conversions / $impressions * 100;
            }
        }

        // Revenues
        $sums = $this->getLeadsQuery($filters, 'soi_id')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('status', 'Delivered')
            ->select('leads.soi_id as soi_id', DB::raw('sum(leads.cost) as sum'))
            ->get()
            ->pluck('sum', 'soi_id');
        foreach ($sois as $soi) {
            if (!empty($sums[$soi->id])) {
                $data[$soi->id]['revenue'] = $sums[$soi->id];
                if (!empty($data[$soi->id]['impressions'])) {
                    $data[$soi->id]['eCPM'] = $data[$soi->id]['revenue'] / $data[$soi->id]['impressions'] / 1000;
                }
            }
        }

        return $data;
    }

    /**
     * Return overall statistics data of revenue from SOI for an advertiser grouped by campaigns(=SOIs)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallSOIRevenueByCampaign(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');

        $data = [];
        $sois = SimpleOptIn::where('advertiser_id', $advertiserId)
            ->with('activeTimesCapsFilter')
            ->get();
        foreach ($sois as $soi) {
            $data[$soi->id] = [
                'daily_cap' => $soi->activeTimesCapsFilter->caps_daily,
                'monthly' => 0,
                'total' => 0,
            ];
        }

        $totalRevenues = $this->getLeadsQuery([], 'soi_id')
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->select('leads.soi_id as soi_id', DB::raw('sum(leads.cost) as sum'))
            ->get()
            ->pluck('sum', 'soi_id');
        $datesQuery = $this->getLeadsQuery([], 'soi_id')
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->select(
                'leads.soi_id as soi_id',
                DB::raw('min(leads.updated_at) as earliest'),
                DB::raw('max(leads.updated_at) as latest')
            )
            ->get();
        $earliestDates = $datesQuery->pluck('earliest', 'soi_id');
        $latestDates = $datesQuery->pluck('latest', 'soi_id');

        $today = new \DateTime();
        foreach ($sois as $soi) {
            if (!empty($totalRevenues[$soi->id])) {
                $data[$soi->id]['total'] = $totalRevenues[$soi->id];
                $dateDiff = date_diff(new DateTime($latestDates[$soi->id]), new DateTime($earliestDates[$soi->id]));
                $months = $dateDiff->y * 12 + $dateDiff->m + 1;
                $data[$soi->id]['monthly'] = $data[$soi->id]['total'] / $months;
            }
        }

        return $data;
    }

    /**
     * Return daily statistics data of revenue from SOI for a filtered segment
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailySOIRevenueBySegment(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');
        $soiId = $request->get('soi_id');
        $filters = $this->makeFilters($request);

        $data = [
            'accepted' => 0,
            'rejected' => 0,
            'revenue' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'click_percent' => 0,
            'conversions' => 0,
            'conversion_percent' => 0,
            'eCPM' => 0,
        ];

        // Accepted
        $acceptedCounts = $this->getLeadsQueryForSegment($filters)
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->select(DB::raw('count(leads.id) as count'))
            ->get();
        $data['accepted'] = intval($acceptedCounts[0]->count);

        // Rejected
        $rejectedCounts = $this->getLeadsQueryForSegment($filters, 'soi_id')
            ->where('status', 'Rejected')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->select(DB::raw('count(leads.id) as count'))
            ->get();
        $data['rejected'] = intval($rejectedCounts[0]->count);

        // Conversions and impressions
        $totalClicks = $this->getLeadsQueryForSegment($filters, 'soi_id')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->select(DB::raw('count(leads.id) as count'))
            ->get();
        $totalSkipCounts = $this->getSOISkipsQueryForSegment($filters, 'soi_id')
            ->where('soi_skips.advertiser_id', $advertiserId)
            ->where('soi_skips.soi_id', $soiId)
            ->select(DB::raw('count(soi_skips.id) as count'))
            ->get();
        $clicks = empty($totalClicks[0]->count) ? 0 : $totalClicks[0]->count;
        $skips = empty($totalSkipCounts[0]->count) ? 0 : $totalSkipCounts[0]->count;
        $conversions = empty($acceptedCounts[0]->count) ? 0 : $acceptedCounts[0]->count;
        $impressions = $clicks + $skips;

        $data['impressions'] = $impressions;
        $data['clicks'] = $clicks;
        if ($impressions > 0) {
            $data['click_percent'] = $clicks / $impressions * 100;
        }
        $data['conversions'] = $conversions;
        if ($clicks > 0) {
            $data['conversion_percent'] = $conversions / $impressions * 100;
        }

        // Revenues
        $sums = $this->getLeadsQueryForSegment($filters, 'soi_id')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->where('status', 'Delivered')
            ->select(DB::raw('sum(leads.cost) as sum'))
            ->get();
        $data['revenue'] = floatval($sums[0]->sum);
        if ($impressions > 0) {
            $data['eCPM'] = $data['revenue'] / $impressions / 1000;
        }

        return $data;
    }

    /**
     * Return overall statistics data of revenue from SOI for a filtered segment
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function overallSOIRevenueBySegment(Request $request)
    {
        $advertiserId = $request->get('advertiser_id');
        $soiId = $request->get('soi_id');
        $filters = $this->makeFilters($request);

        $data = [
            'monthly' => 0,
            'total' => 0,
        ];

        $totalRevenues = $this->getLeadsQueryForSegment($filters)
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->select(DB::raw('sum(leads.cost) as sum'))
            ->get();
        $dates = $this->getLeadsQueryForSegment($filters)
            ->where('status', 'Delivered')
            ->where('leads.advertiser_id', $advertiserId)
            ->where('leads.soi_id', $soiId)
            ->select(
                DB::raw('min(leads.updated_at) as earliest'),
                DB::raw('max(leads.updated_at) as latest')
            )
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

            // age filter
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
     * Return base leads query with range-filtering and grouping by advertiser or soi
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getLeadsQuery($filters = [], $groupBy = 'advertiser_id') {
        $query = DB::table('leads')
            ->join('simple_opt_ins', 'simple_opt_ins.id', '=', 'leads.soi_id');
        if (!empty($filters['from'])) {
            $query = $query->where('leads.updated_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('leads.updated_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('simple_opt_ins.owner_id', $filters['owner_ids']);
        }
        $query = $query->groupBy($groupBy);
        return $query;
    }


    /**
     * Return base soi skips query with range-filtering and grouping by advertiser or soi
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getSOISkipsQuery($filters = [], $groupBy = 'advertiser_id') {
        $query = DB::table('soi_skips')
            ->join('simple_opt_ins', 'simple_opt_ins.id', '=', 'soi_skips.soi_id');
        if (!empty($filters['from'])) {
            $query = $query->where('soi_skips.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('soi_skips.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('simple_opt_ins.owner_id', $filters['owner_ids']);
        }
        $query = $query->groupBy($groupBy);
        return $query;
    }

    /**
     * Return base leads query with range and segment filtering
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getLeadsQueryForSegment($filters = []) {
        $query = DB::table('leads')
            ->join('lead_users', 'lead_users.lead_id', '=', 'leads.id')
            ->join('simple_opt_ins', 'simple_opt_ins.id', '=', 'leads.soi_id');
        if (!empty($filters['from'])) {
            $query = $query->where('leads.updated_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('leads.updated_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('simple_opt_ins.owner_id', $filters['owner_ids']);
        }
        if (!empty($filters['gender'])) {
            $query = $query->whereIn('lead_users.gender', $filters['gender']);
        }
        if (!empty($filters['platform'])) {
            $query = $query->whereIn('platform', $filters['platform']);
        }
        if (!empty($filters['age_range'])) {
            $from = Carbon::now()->subYears($filters['age_range'][1]);
            $to = Carbon::now()->subYears($filters['age_range'][0])->addYear()->subDay();
            $query = $query->whereBetween('lead_users.birthday', [$from, $to]);
        }
        if (!empty($filters['zip_codes']) && !empty($filters['states'])) {
            $query = $query->where(function ($query) use ($filters) {
                $query->whereIn('lead_users.zip_code', $filters['zip_codes'])
                    ->orWhereIn('lead_users.state', $filters['states']);
            });
        } else if (!empty($filters['zip_codes'])) {
            $query = $query->where('lead_users.zip_code', $filters['zip_codes']);
        } else if (!empty($filters['states'])) {
            $query = $query->where('lead_users.state', $filters['states']);
        }
        return $query;
    }

    /**
     * Return base soi skips query with range and segment filtering
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getSOISkipsQueryForSegment($filters = []) {
        $query = DB::table('soi_skips')
            ->join('simple_opt_ins', 'simple_opt_ins.id', '=', 'soi_skips.soi_id');
        if (!empty($filters['from'])) {
            $query = $query->where('soi_skips.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where('soi_skips.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        if (!empty($filters['owner_ids'])) {
            $query = $query->whereIn('simple_opt_ins.owner_id', $filters['owner_ids']);
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
