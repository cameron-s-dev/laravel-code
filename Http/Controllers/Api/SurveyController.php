<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Survey;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $surveys = Survey::all();
        $surveysArray = [];
        foreach ($surveys as $survey) {
            $surveyArray = $survey->toArray();
            $surveyArray['question_count'] = $survey->questions()->count();
            $surveysArray[] = $surveyArray;
        }
        return response()->json($surveysArray);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $survey = null;
        DB::transaction(function() use ($request, &$survey) {
            $survey = new Survey();
            $survey->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'survey' => $survey->fullData()->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function show(Survey $survey)
    {
        return $survey->fullData();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Survey $survey)
    {
        DB::transaction(function() use ($request, &$survey) {
            $survey->fillData($request->all());
        });

        return response()->json([
            'success' => true,
            'survey' => $survey->fullData()->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function destroy(Survey $survey)
    {
        DB::transaction(function() use ($survey) {
            foreach ($survey->surveyQuestions as $question) {
                $question->followUps()->delete();
            }
            $survey->surveyQuestions()->delete();
            $survey->delete();
        });

        return response()->json(['success' => true]);
    }
}
