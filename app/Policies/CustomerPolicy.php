<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.view');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.update') && ! $customer->isArchived() && $customer->anonymized_at === null;
    }

    public function archive(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.archive');
    }
}
