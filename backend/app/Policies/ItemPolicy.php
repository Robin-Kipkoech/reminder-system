<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    private const MANAGEMENT_ROLES = ['admin', 'manager'];

    /**
     * Visibility itself is enforced by the visibleTo scope, so an item a staff
     * member does not own never reaches this policy: the controller answers 404.
     */
    public function view(User $user, Item $item): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Item $item): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Item $item): bool
    {
        return $this->manages($user);
    }

    public function renew(User $user, Item $item): bool
    {
        return $this->manages($user);
    }

    public function updateStatus(User $user, Item $item): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }
}
