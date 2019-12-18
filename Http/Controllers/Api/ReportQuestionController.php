<?php

namespace App\Http\Controllers\Api;

use DateInterval;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use DB;
use App\SurveyQuestion;
use App\SurveyQuestionAnswer;


/**
 * Some notes:
 * - updated_at field (rather than created_at) is used for filtering leads and conversions because
 *  + declined leads can be submitted and delivered later
 *  + for CPA conversions, actual conversion is done by pixel tracking which
 *    updates conversion
 */
class ReportQuestionController extends Controller
{
    /**
     * Return daily statistics data of revenue from grouped by questions
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyQuestionRevenue(Request $request)
    {
        $filters = $this->makeFilters($request);

        $data = [];
        $questions = SurveyQuestion::all();
        foreach ($questions as $question) {
            $data[$question->id] = [
                'revenue' => 0,
                'impressions' => 0,
                'eCPM' => 0,
                'average_cpl' => 0,
            ];
        }

        // Impressions
        $totalClicks = $this->getLeadsQuery($filters)
            ->select('leads.question_id as question_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'question_id');
        $totalSkipCounts = $this->getSOISkipsQuery($filters)
            ->select('soi_skips.question_id as question_id', DB::raw('count(soi_skips.id) as count'))
            ->get()
            ->pluck('count', 'question_id');
        foreach ($questions as $question) {
            $clicks = empty($totalClicks[$question->id]) ? 0 : $totalClicks[$question->id];
            $skips = empty($totalSkipCounts[$question->id]) ? 0 : $totalSkipCounts[$question->id];
            $impressions = $clicks + $skips;
            $data[$question->id]['impressions'] = $impressions;
        }

        // Revenues
        $sums = $this->getLeadsQuery($filters)
            ->where('status', 'Delivered')
            ->select('leads.question_id as question_id', DB::raw('sum(leads.cost) as sum'))
            ->get()
            ->pluck('sum', 'question_id');
        $leadCounts = $this->getLeadsQuery($filters)
            ->select('leads.question_id as question_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'question_id');
        foreach ($questions as $question) {
            if (!empty($sums[$question->id])) {
                $data[$question->id]['revenue'] = $sums[$question->id];
                if (!empty($data[$question->id]['impressions'])) {
                    $data[$question->id]['eCPM'] = $data[$question->id]['revenue'] / $data[$question->id]['impressions'] / 1000;
                }

                $leadCount = empty($leadCounts[$question->id]) ? 0 : $leadCounts[$question->id];
                if ($leadCount > 0) {
                    $data[$question->id]['average_cpl'] = $sums[$question->id] / $leadCount;
                }
            }
        }

        return $data;
    }

    /**
     * Return daily statistics data of revenue from SOI for an question grouped by question answers (=responses)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function dailyQuestionRevenueByAnswer(Request $request)
    {
        $filters = $this->makeFilters($request);
        $questionId = $request->get('question_id');

        $data = [];
        $answers = SurveyQuestionAnswer::where('question_id', $questionId)->get();
        foreach ($answers as $answer) {
            $data[$answer->id] = [
                'revenue' => 0,
                'impressions' => 0,
                'eCPM' => 0,
                'average_cpl' => 0,
            ];
        }

        // Impressions
        $totalClicks = $this->getLeadsQuery($filters, 'answer_id')
            ->where('question_id', $questionId)
            ->select('leads.answer_id as answer_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'answer_id');
        $totalSkipCounts = $this->getSOISkipsQuery($filters, 'answer_id')
            ->where('question_id', $questionId)
            ->select('soi_skips.answer_id as answer_id', DB::raw('count(soi_skips.id) as count'))
            ->get()
            ->pluck('count', 'answer_id');
        foreach ($answers as $answer) {
            $clicks = empty($totalClicks[$answer->id]) ? 0 : $totalClicks[$answer->id];
            $skips = empty($totalSkipCounts[$answer->id]) ? 0 : $totalSkipCounts[$answer->id];
            $impressions = $clicks + $skips;
            $data[$answer->id]['impressions'] = $impressions;
        }

        // Revenues
        $sums = $this->getLeadsQuery($filters, 'answer_id')
            ->where('status', 'Delivered')
            ->where('question_id', $questionId)
            ->select('leads.answer_id as answer_id', DB::raw('sum(leads.cost) as sum'))
            ->get()
            ->pluck('sum', 'answer_id');
        $leadCounts = $this->getLeadsQuery($filters, 'answer_id')
            ->where('question_id', $questionId)
            ->select('leads.answer_id as answer_id', DB::raw('count(leads.id) as count'))
            ->get()
            ->pluck('count', 'answer_id');
        foreach ($answers as $answer) {
            if (!empty($sums[$answer->id])) {
                $data[$answer->id]['revenue'] = $sums[$answer->id];
                if (!empty($data[$answer->id]['impressions'])) {
                    $data[$answer->id]['eCPM'] = $data[$answer->id]['revenue'] / $data[$answer->id]['impressions'] / 1000;
                }

                $leadCount = empty($leadCounts[$answer->id]) ? 0 : $leadCounts[$answer->id];
                if ($leadCount > 0) {
                    $data[$answer->id]['average_cpl'] = $sums[$answer->id] / $leadCount;
                }
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
    public function dailyQuestionRevenueBySegment(Request $request)
    {
        $questionId = $request->get('question_id');
        $answerId = $request->get('answer_id');
        $filters = $this->makeFilters($request);

        $data = [
            'revenue' => 0,
            'impressions' => 0,
            'eCPM' => 0,
            'average_cpl' => 0,
        ];

        // Impressions
        $clickData = $this->getLeadsQueryForSegment($filters)
            ->where('question_id', $questionId)
            ->where('answer_id', $answerId)
            ->select(DB::raw('count(leads.id) as count'))
            ->get();
        $clicks = intval($clickData[0]->count);
        $skipData = $this->getSOISkipsQueryForSegment($filters)
            ->where('question_id', $questionId)
            ->where('answer_id', $answerId)
            ->select(DB::raw('count(soi_skips.id) as count'))
            ->get();
        $skips = intval($skipData[0]->count);
        $data['impressions'] = $clicks + $skips;

        // Revenues
        $sumData = $this->getLeadsQueryForSegment($filters)
            ->where('status', 'Delivered')
            ->where('question_id', $questionId)
            ->where('answer_id', $answerId)
            ->select(DB::raw('sum(leads.cost) as sum'))
            ->get();
        $sum = floatval($sumData[0]->sum);
        $acceptedCountData = $this->getLeadsQueryForSegment($filters)
            ->where('question_id', $questionId)
            ->where('answer_id', $answerId)
            ->select(DB::raw('count(leads.id) as count'))
            ->get();
        $acceptCount = intval($acceptedCountData[0]->count);

        $data['revenue'] = $sum;
        if (!empty($data['impressions'])) {
            $data['eCPM'] = $data['revenue'] / $data['impressions'] / 1000;
        }

        if ($acceptCount > 0) {
            $data['average_cpl'] = $sum / $acceptCount;
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
     * Return base leads query with range-filtering and grouping by
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getLeadsQuery($filters = [], $groupBy = 'question_id') {
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
     * Return base soi skips query with range-filtering and grouping by question or soi
     *
     * @param  array    $range
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getSOISkipsQuery($filters = [], $groupBy = 'question_id') {
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
