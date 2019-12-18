<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\SurveyQuestion;

class SurveyQuestionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $questions = SurveyQuestion::with('answers')->get();
        return $questions;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $surveyQuestion = null;
        DB::transaction(function() use ($request, &$surveyQuestion) {
            $surveyQuestion = new SurveyQuestion();
            $surveyQuestion->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'survey_question' => $surveyQuestion->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\SurveyQuestion  $surveyQuestion
     * @return \Illuminate\Http\Response
     */
    public function show(SurveyQuestion $surveyQuestion)
    {
        return $surveyQuestion->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\SurveyQuestion  $surveyQuestion
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, SurveyQuestion $surveyQuestion)
    {
        DB::transaction(function() use ($request, &$surveyQuestion) {
            $surveyQuestion->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'survey_question' => $surveyQuestion->toArray(),
        ]);
    }

    /**
     * Enable or disable specified survey question.
     *
     * @param  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggle(Request $request, $id) {
        $surveyQuestion = SurveyQuestion::find($id);
        $surveyQuestion->enabled = !!$request->get('enabled');
        $surveyQuestion->save();

        return response()->json([
            'success' => true,
            'enabled' => $surveyQuestion->enabled,
        ]);
    }
}
