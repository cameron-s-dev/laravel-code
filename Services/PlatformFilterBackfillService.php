<?php

namespace App\Services;

use App\Linkout;
use App\SimpleOptIn;
use App\PlatformFilter;

class PlatformFilterBackfillService {

    public static function process() {
        PlatformFilterBackfillService::fillSOIs();
        PlatformFilterBackfillService::fillLinkouts();
    }

    protected static function fillSOIs() {
        $sois = SimpleOptIn::all();
        foreach ($sois as $soi) {
            $platformFilterCount = PlatformFilter::where('soi_id', $soi->id)->count();
            if (!$platformFilterCount) {
                $platformFilter = new PlatformFilter();
                $platformFilter->soi_id = $soi->id;
                $platformFilter->save();
            }
        }
    }

    protected static function fillLinkouts() {
        $linkouts = Linkout::all();
        foreach ($linkouts as $linkout) {
            $platformFilterCount = PlatformFilter::where('linkout_id', $linkout->id)->count();
            if (!$platformFilterCount) {
                $platformFilter = new PlatformFilter();
                $platformFilter->linkout_id = $linkout->id;
                $platformFilter->save();
            }
        }
    }
}
