<?php

namespace App;

use Exception;
use App\BaseModel;

class Lead extends BaseModel
{
    protected $fillable = ['advertiser', 'soi', 'traffic_source'];

    /**************************************
     *              Relations             *
     **************************************/

    public function user() {
        return $this->hasOne('App\LeadUser');
    }

    public function simpleOptIn() {
        return $this->belongsTo('App\SimpleOptIn', 'soi_id', 'id');
    }

    /**************************************
     *           Public methods           *
     **************************************/

    public function fillData($data, $ignoreStatus = false, ...$args) {
        if (!$ignoreStatus) {
            $this->status = $data['status'];
        }
        parent::fillData($data);

        if ($this->user) {
            $leadUser = $this->user;
        } else {
            $leadUser = new LeadUser();
            $leadUser->lead_id = $lead;
        }

        $leadUser->fillData($data);
    }

    public function fullData() {
        $tmp = $this->user;
        return $this;
    }

    /**************************************
     *          Validation Rules          *
     **************************************/

    public function validationRules(...$args) {
        return [
            'status' => 'required|string|max:100',
            'advertiser' => 'required|string|max:100',
            'soi' => 'required|string|max:100',
        ];
    }
}
