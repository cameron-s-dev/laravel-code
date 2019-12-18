<?php

namespace App\Services;

use App\BriteVerifyResult;

class FilterService
{
    public static function getUserData($userData, $field) {
        if ($field == 'age') {
            if (!empty($userData['birthday'])) {
                $year = date('Y', strtotime($userData['birthday']));
            } else if (!empty($userData['year'])) {
                $year = $userData['year'];
            } else {
                return false;
            }
            return date('Y') - $year;
        } else if ($field == 'zip_code') {
            return (
                !empty($userData[$field]) ?
                $userData[$field] :
                (!empty($userData['zip']) ? $userData['zip'] : false)
            );
        } else if ($field == 'brite_verify_valid') {
            // This is a special case. Field value will be BriteVerify api response. Cached response can be used.
            return BriteVerifyResult::queryResult($userData['email']);
        } else {
            return empty($userData[$field]) ? false : $userData[$field];
        }
    }

    public static function checkFilters($modelObject, $userData) {
        $activeTimesCapsFilter = $modelObject->activeTimesCapsFilter;
        if ($activeTimesCapsFilter) {
            // Weekday filter check
            $weekdayFilters = ['active_mon', 'active_tue', 'active_wed', 'active_thu', 'active_fri', 'active_sat', 'active_sun'];
            $weekdayFilterExists = false;
            foreach ($weekdayFilters as $weekdayFilter) {
                if ($activeTimesCapsFilter->getAttribute($weekdayFilter)) {
                    $weekdayFilterExists = true;
                    break;
                }
            }
            if ($weekdayFilterExists) {
                $weekdayFilter = $weekdayFilters[date('N') - 1];
                if (!$activeTimesCapsFilter->getAttribute($weekdayFilter)) {
                    return false;
                }
            }

            // Active hours filter check
            $currentTime = date('H:i');
            $dayPrefix = strtolower(date('D'));
            $active_hours_begin = $dayPrefix . '_active_hours_begin';
            $active_hours_end = $dayPrefix . '_active_hours_end';
            $satisfiesActiveHoursCheck = true;
            if ($activeTimesCapsFilter->$active_hours_begin && $activeTimesCapsFilter->$active_hours_end) {
                if ($activeTimesCapsFilter->$active_hours_begin <= $activeTimesCapsFilter->$active_hours_end) {
                    $satisfiesActiveHoursCheck = (
                        $activeTimesCapsFilter->$active_hours_begin <= $currentTime &&
                        $activeTimesCapsFilter->$active_hours_end >= $currentTime
                    );
                } else {
                    $satisfiesActiveHoursCheck = !(
                        $activeTimesCapsFilter->$active_hours_end <= $currentTime &&
                        $activeTimesCapsFilter->$active_hours_begin >= $currentTime
                    );
                }
            } else if ($activeTimesCapsFilter->$active_hours_begin) {
                $satisfiesActiveHoursCheck = ($activeTimesCapsFilter->$active_hours_begin <= $currentTime);
            } else if ($activeTimesCapsFilter->$active_hours_end) {
                $satisfiesActiveHoursCheck = ($activeTimesCapsFilter->$active_hours_end >= $currentTime);
            }
            if (!$satisfiesActiveHoursCheck) {
                return false;
            }
        }

        // Demographic filter
        foreach ($modelObject->demographicFilters as $filter) {
            if (!$filter->field || !$filter->operator || !$filter->value) {
                continue;
            }

            $actualValue = FilterService::getUserData($userData, $filter->field);
            if (!$actualValue) {
                return false;
            }
            $filterValue = $filter->value;
            switch ($filter->operator) {
                case 'eq':
                    if ($actualValue != $filterValue) return false;
                    break;
                case 'neq':
                    if ($actualValue == $filterValue) return false;
                    break;
                case 'lt':
                    if ($actualValue >= $filterValue) return false;
                    break;
                case 'lte':
                    if ($actualValue > $filterValue) return false;
                    break;
                case 'gt':
                    if ($actualValue <= $filterValue) return false;
                    break;
                case 'gte':
                    if ($actualValue < $filterValue) return false;
                    break;
                case 'in':
                    $filterValues = array_map('strtolower', array_map('trim', explode(',', $filterValue)));
                    if (!in_array(strtolower($actualValue), $filterValues)) return false;
                    break;
                case 'not-in':
                    $filterValues = array_map('strtolower', array_map('trim', explode(',', $filterValue)));
                    if (in_array(strtolower($actualValue), $filterValues)) return false;
                    break;
                case 'has':
                    $filterValues = array_map('strtolower', array_map('trim', explode(',', $filterValue)));
                    $found = false;
                    foreach ($filterValues as $filterValue) {
                        if (stripos(strtolower($actualValue), $filterValue)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) return false;
                    break;
                case 'has-not':
                    $filterValues = array_map('trim', explode(',', $filterValue));
                    foreach ($filterValues as $value) {
                        if (stripos($actualValue, $value)) return false;
                    }
                    break;
                case 'between':
                    $filterValues = array_map('strtolower', array_map('trim', explode(',', $filterValue)));
                    if (count($filterValues) != 2) {
                        return false;
                    }
                    if ($filter->field == 'age') {
                        if ((float)$actualValue < (float)$filterValues[0] || (float)$actualValue > (float)$filterValues[1]) return false;
                    } else {
                        if ($actualValue < $filterValues[0] || $actualValue > $filterValues[1]) return false;
                    }
                    break;
            }
        }

        // Blacklist filter
        foreach ($modelObject->blacklists as $blacklist) {
            if ($blacklist->field == 'name') {
                $blacklistedValues = explode(',', $blacklist->values);
                for ($i = 0; $i < count($blacklistedValues); $i++) {
                    $blacklistedValue = strtolower(trim($blacklistedValues[$i]));
                    if (
                        strtolower($userData['first_name']) == $blacklistedValue ||
                        strtolower($userData['last_name']) == $blacklistedValue
                    ) {
                        return false;
                    }
                }
            } else {
                $actualValue = FilterService::getUserData($userData, $blacklist->field);
                $blacklistedValues = explode(',', $blacklist->values);
                for ($i = 0; $i < count($blacklistedValues); $i++) {
                    $blacklistedValue = strtolower(trim($blacklistedValues[$i]));
                    if (strtolower($actualValue) == $blacklistedValue) {
                        return false;
                    }
                }
            }
        }

        // Whitelist filter
        $whitelisted = (count($modelObject->whitelists) === 0);
        foreach ($modelObject->whitelists as $whitelist) {
            if ($whitelist->field == 'name') {
                $whitelistedValues = explode(',', $whitelist->values);
                for ($i = 0; $i < count($whitelistedValues); $i++) {
                    $whitelistedValue = strtolower(trim($whitelistedValues[$i]));
                    if (
                        strtolower($userData['first_name']) == $whitelistedValue ||
                        strtolower($userData['last_name']) == $whitelistedValue
                    ) {
                        $whitelisted = true;
                        break;
                    }
                }
            } else {
                $actualValue = FilterService::getUserData($userData, $whitelist->field);
                $whitelistedValues = explode(',', $whitelist->values);
                for ($i = 0; $i < count($whitelistedValues); $i++) {
                    $whitelistedValue = strtolower(trim($whitelistedValues[$i]));
                    if (strtolower($actualValue) == $whitelistedValue) {
                        $whitelisted = true;
                        break;
                    }
                }
            }
        }
        if (!$whitelisted) {
            return false;
        }

        return true;
    }
}
