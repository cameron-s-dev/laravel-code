<?php

namespace App\Http\Controllers\Api;

use DateInterval;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use DB;
use App\Path;


/**
 * Some notes:
 * - updated_at field (rather than created_at) is used for filtering leads and conversions because
 *  + declined leads can be submitted and delivered later
 *  + for CPA conversions, actual conversion is done by pixel tracking which
 *    updates conversion
 */
class ReportSourceController extends Controller
{
    /**
     * Return daily statistics data of revenue from grouped by traffic sources
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailySourceRevenue(Request $request)
    {
        $filters = $this->makeFilters($request);

        $totalLeadCosts = $this->getBaseQueryWithGroupBy('leads', $filters, 'updated_at')
            ->where('leads.status', 'Delivered')
            ->select('traffic_source', DB::raw('sum(cost) as sum'))
            ->get()
            ->pluck('sum', 'traffic_source')
            ->toArray();
        $totalConversionCosts = $this->getBaseQueryWithGroupBy('conversions', $filters, 'updated_at')
            ->select('traffic_source', DB::raw('sum(cost) as sum'))
            ->get()
            ->pluck('sum', 'traffic_source')
            ->toArray();
        $totalVisits = $this->getBaseQueryWithGroupBy('visits', $filters)
            ->select('traffic_source', DB::raw('count(id) as count'))
            ->get()
            ->pluck('count', 'traffic_source')
            ->toArray();
        $totalPTEs = $this->getBaseQueryWithGroupBy('progress_to_ends', $filters)
            ->select('traffic_source', DB::raw('count(id) as count'))
            ->get()
            ->pluck('count', 'traffic_source')
            ->toArray();

        $trafficSources = array_unique(array_merge(
            array_keys($totalLeadCosts),
            array_keys($totalConversionCosts),
            array_keys($totalVisits),
            array_keys($totalPTEs)
        ));

        $data = [];
        foreach ($trafficSources as $trafficSource) {
            $row = [
                'visitors' => 0,
                'revenue' => 0,
                'pte' => 0,
                'rpv' => 0,
                'rpmv' => 0,
            ];

            if (!empty($totalLeadCosts[$trafficSource])) {
                $row['revenue'] += $totalLeadCosts[$trafficSource];
            }
            if (!empty($totalConversionCosts[$trafficSource])) {
                $row['revenue'] += $totalConversionCosts[$trafficSource];
            }

            if (!empty($totalVisits[$trafficSource])) {
                $row['visitors'] = $totalVisits[$trafficSource];
                $row['rpv'] = $row['revenue'] / $row['visitors'];
                if (!empty($totalPTEs[$trafficSource])) {
                    $row['pte'] = $totalPTEs[$trafficSource] / $totalVisits[$trafficSource] * 100;
                }
            }

            $leadUserEmails = $this->getBaseQuery('leads', $filters, 'updated_at')
                ->join('lead_users', 'lead_users.lead_id', '=', 'leads.id')
                ->where('leads.status', 'Delivered')
                ->where('traffic_source', $trafficSource)
                ->select('email')
                ->get()
                ->pluck('email')
                ->toArray();
            $conversionUserEmails = $this->getBaseQuery('conversions', $filters, 'updated_at')
                ->join('conversion_users', 'conversion_users.id', '=', 'conversions.conversion_user_id')
                ->where('traffic_source', $trafficSource)
                ->select('email')
                ->get()
                ->pluck('email')
                ->toArray();
            $monetizedVisitors = count(array_unique(array_merge($leadUserEmails, $conversionUserEmails)));
            if ($monetizedVisitors > 0) {
                $row['rpmv'] = $row['revenue'] / $monetizedVisitors;
            }

            $data[$trafficSource] = $row;
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
     * Return base query with range-filtering
     *
     * @param  string   $tableName
     * @param  array    $filters
     * @param  string   dateColumn
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getBaseQuery($tableName, $filters = [], $dateColumn = 'created_at') {
        $query = DB::table($tableName);
        if (!empty($filters['from'])) {
            $query = $query->where($dateColumn, '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query = $query->where($dateColumn, '<=', $filters['to']);
        }
        if (!empty($filters['path_ids'])) {
            $query = $query->whereIn('path_id', $filters['path_ids']);
        }
        return $query;
    }

    /**
     * Return base query with range-filtering and grouping by
     *
     * @param  string   $tableName
     * @param  array    $filters
     * @param  string   dateColumn
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getBaseQueryWithGroupBy($tableName, $filters = [], $dateColumn = 'created_at') {
        return $this->getBaseQuery($tableName, $filters, $dateColumn)
            ->groupBy('traffic_source');
    }
}
