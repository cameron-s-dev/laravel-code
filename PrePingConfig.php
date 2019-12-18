<?php

namespace App;

use Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\BaseModel;
use App\PrePingUrlParam;

class PrePingConfig extends BaseModel
{
    protected $fillable = ['enabled', 'url', 'method', 'success_response', 'positive'];
    public $timestamps = false;

    /**************************************
     *              Relations             *
     **************************************/

    public function prePingUrlParams() {
        return $this->hasMany('App\PrePingUrlParam');
    }

    /**************************************
     *           Public methods           *
     **************************************/

    public function getEnabledAttribute($value) {
        return boolval($value);
    }

    public function getPositiveAttribute($value) {
        return boolval($value);
    }

    public function fillData($data, ...$args) {
        parent::fillData($data, $data['enabled']);

        foreach ($data['pre_ping_url_params'] as $urlParamData) {
            $urlParam = new PrePingUrlParam();
            $urlParam->pre_ping_config_id = $this->id;
            $urlParam->fillData($urlParamData);
        }
    }

    public function duplicate() {
        $newModel = $this->replicate();
        $newModel->save();
        foreach ($this->prePingUrlParams as $urlParam) {
            $_urlParam = $urlParam->replicate();
            $_urlParam->pre_ping_config_id = $newModel->id;
            $_urlParam->save();
        }
        return $newModel;
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        $prePingEnabled = $args[0];
        if ($prePingEnabled) {
            return [
                'enabled' => 'required|boolean',
                'url' => 'required|string|max:300',
                'method' => [ 'required', Rule::in(['get', 'post']) ],
                'success_response' => 'required|string|max:100',
                'positive' => 'required|boolean',
                'url_params' => 'array',
            ];
        } else {
            return [
                'enabled' => 'boolean',
                'url' => 'string|max:300',
                'method' => Rule::in(['get', 'post']),
                'success_response' => 'string|max:100',
                'positive' => 'boolean',
                'url_params' => 'array',
            ];
        }
    }
}
