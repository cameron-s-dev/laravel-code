<?php

namespace App\Http\Controllers\Api;

use DateInterval;
use DateTime;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use DB;
use App\Conversion;
use App\ConversionUser;
use App\Lead;
use App\LeadUser;
use App\Visit;
use App\ProgressToEnd;


/**
 * Some notes:
 * - updated_at field (rather than created_at) is used for filtering leads and conversions because
 *  + declined leads can be submitted and delivered later
 *  + for CPA conversions, actual conversion is done by pixel tracking which
 *    updates conversion
 */
class ReportController extends Controller
{
    /**
     * Return statistics data for reports dashboard
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyOverall(Request $request)
    {
        $range = $request->get('range');
        $path_ids = $request->get('path_ids');
        $rangeFrom = date_create_from_format('Y-m-d', $range[0]);
        $rangeFrom->setTime(0, 0, 0);
        $rangeTo = date_create_from_format('Y-m-d', $range[1]);
        $rangeTo->setTime(23, 59, 59);

        $conversions = Conversion::where('updated_at', '>=', $rangeFrom)
            ->where('updated_at', '<=', $rangeTo)
            ->with('conversionObject', 'conversionUser');
        if ($path_ids && count($path_ids) > 0) {
            $conversions = $conversions->whereIn('path_id', $path_ids);
        }
        $conversions = $conversions->get();

        $leads = Lead::where('updated_at', '>=', $rangeFrom)
            ->where('updated_at', '<=', $rangeTo)
            ->where('status', 'Delivered')
            ->with('user');
        if ($path_ids && count($path_ids) > 0) {
            $leads = $leads->whereIn('path_id', $path_ids);
        }
        $leads = $leads->get();

        $visits = Visit::where('created_at', '>=', $rangeFrom)
            ->where('created_at', '<=', $rangeTo);
        if ($path_ids && count($path_ids) > 0) {
            $visits = $visits->whereIn('path_id', $path_ids);
        }
        $visits = $visits->get();

        $ptes = ProgressToEnd::where('created_at', '>=', $rangeFrom)
            ->where('created_at', '<=', $rangeTo);
        if ($path_ids && count($path_ids) > 0) {
            $ptes = $ptes->whereIn('path_id', $path_ids);
        }
        $ptes = $ptes->get();

        $rpv = [];
        $rpmv = [];
        $rps = [];
        $visitors = [];
        $pte = [];
        $revenue = [];
        $rpl = [];

        $timeSegmentEnd = clone $rangeFrom;
        $timeSegmentEnd->add(new DateInterval('PT15M'));
        $timeSegmentEnd = new Carbon($timeSegmentEnd->format(\DateTime::ATOM));
        $rangeEnd = clone $rangeTo;
        $rangeEnd->add(new DateInterval('PT1S'));
        $rangeEnd = new Carbon($rangeEnd->format(\DateTime::ATOM));
        $totalConversionCount = count($conversions);
        $totalLeadCount = count($leads);
        $totalVisitCount = count($visits);
        $totalPTEQueryCount = count($ptes);

        $totalRevenue = 0;
        $totalRevenueFromSOIs = 0;
        $totalRevenueFromLinkouts = 0;
        $totalMonetizedVisitors = 0;
        $totalSOIs = 0;
        $totalLinkouts = 0;
        $totalVisitors = 0;
        $totalRawVisits = 0;
        $totalPTEs = 0;

        $ci = 0;
        $li = 0;
        $vi = 0;
        $pi = 0;
        while ($timeSegmentEnd <= $rangeEnd) {
            $revenueFromLinkout = 0;
            $revenueFromSOI = 0;
            $soiIds = [];
            $linkoutIds = [];
            $visitorIps = [];
            $pteIps = [];
            $monetizedVisitorEmails = [];

            while ($ci < $totalConversionCount && $conversions[$ci]->updated_at <= $timeSegmentEnd) {
                if ($conversions[$ci]->conversion_object_type == 'linkout') {
                    $revenueFromLinkout += $conversions[$ci]->cost;
                    $linkoutIds[] = $conversions[$ci]->conversion_object_id;
                    $monetizedVisitorEmails[] = $conversions[$ci]->conversionUser->email;
                }
                ++$ci;
            }

            while ($li < $totalLeadCount && $leads[$li]->updated_at <= $timeSegmentEnd) {
                $revenueFromSOI += $leads[$li]->cost;
                $soiIds[] = $leads[$li]->soi_id;
                $monetizedVisitorEmails[] = $leads[$li]->user->email;
                ++$li;
            }

            $rawVisitCount = 0;
            while ($vi < $totalVisitCount && $visits[$vi]->created_at < $timeSegmentEnd) {
                $visitorIps[] = $visits[$vi]->ip;
                ++$rawVisitCount;
                ++$vi;
            }

            $pteCount = 0;
            while ($pi < $totalPTEQueryCount && $ptes[$pi]->created_at < $timeSegmentEnd) {
                ++$pteCount;
                ++$pi;
            }

            $soiCount = count(array_unique($soiIds));
            $linkoutCount = count(array_unique($linkoutIds));
            $visitorCount = count(array_unique($visitorIps));
            $monetizedVisitorCount = count(array_unique($monetizedVisitorEmails));
            $revenueInSegment = $revenueFromSOI + $revenueFromLinkout;

            $totalRevenueFromSOIs += $revenueFromSOI;
            $totalRevenueFromLinkouts += $revenueFromLinkout;
            $totalMonetizedVisitors += $monetizedVisitorCount;
            $totalSOIs += $soiCount;
            $totalLinkouts += $linkoutCount;
            $totalVisitors += $visitorCount;
            $totalRawVisits += $rawVisitCount;
            $totalPTEs += $pteCount;

            $rpv[] = $visitorCount > 0 ? $revenueInSegment / $visitorCount : 0;
            $rpmv[] = $monetizedVisitorCount > 0 ? $revenueInSegment / $monetizedVisitorCount : 0;
            $rps[] = $soiCount > 0 ? $revenueFromSOI / $soiCount : 0;
            $visitors[] = $visitorCount;
            $pte[] = $rawVisitCount > 0 ? $pteCount / $rawVisitCount * 100.0 : 0;
            $revenue[] = $revenueInSegment;
            $rpl[] = $linkoutCount > 0 ? $revenueFromLinkout / $linkoutCount : 0;

            $timeSegmentEnd->add(new DateInterval('PT15M'));
        }

        $totalRevenue = $totalRevenueFromSOIs + $totalRevenueFromLinkouts;
        $overall = [
            'rpv' => $totalVisitors > 0 ? $totalRevenue / $totalVisitors : 0,
            'rpmv' => $totalMonetizedVisitors > 0 ? $totalRevenue / $totalMonetizedVisitors : 0,
            'rps' => $totalSOIs > 0 ? $totalRevenueFromSOIs / $totalSOIs : 0,
            'visitors' => $totalVisitors,
            'pte' => $totalRawVisits > 0 ? $totalPTEs / $totalRawVisits * 100 : 0,
            'revenue' => $totalRevenue,
            'rpl' => $totalLinkouts > 0 ? $totalRevenueFromLinkouts / $totalLinkouts : 0,
        ];

        return [
            'segments' => [
                'rpv' => $rpv,
                'rpmv' => $rpmv,
                'rps' => $rps,
                'visitors' => $visitors,
                'pte' => $pte,
                'revenue' => $revenue,
                'rpl' => $rpl,
            ],
            'overall' => $overall,
        ];
    }
}
