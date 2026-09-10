<?php

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\User;

/**
 * Items are sub-parts of a care plan, not independently owned — every method
 * delegates its ownership check to CarePlanPolicy::ownsOrSupervises() so the
 * two never drift.
 */
class CarePlanItemPolicy
{
    public function __construct(protected CarePlanPolicy $carePlans) {}

    /**
     * Determine whether the user can add an item to the given plan.
     */
    public function create(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.create')) {
            return false;
        }

        return $this->carePlans->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can update the item's editable fields.
     */
    public function update(User $user, CarePlanItem $item): bool
    {
        if (! $user->can('treatment.update')) {
            return false;
        }

        return $this->carePlans->ownsOrSupervises($user, $item->carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can advance the item's status.
     */
    public function changeStatus(User $user, CarePlanItem $item): bool
    {
        if (! $user->can('treatment.update')) {
            return false;
        }

        return $this->carePlans->ownsOrSupervises($user, $item->carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the item.
     */
    public function cancel(User $user, CarePlanItem $item): bool
    {
        if (! $user->can('treatment.cancel')) {
            return false;
        }

        return $this->carePlans->ownsOrSupervises($user, $item->carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can amend a completed item (spec §31's
     * controlled correction workflow).
     */
    public function correct(User $user, CarePlanItem $item): bool
    {
        if (! $user->can('treatment.correct')) {
            return false;
        }

        return $this->carePlans->ownsOrSupervises($user, $item->carePlan->clinicalEncounter);
    }
}
