<?php

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;

/**
 * Setiap aksi pada satu mesin harus lolos DUA hal: permission (boleh aksinya)
 * dan scope (mesin itu ada di outlet yang jadi tanggung jawabnya).
 * Admin dilewatkan lebih dulu oleh Gate::before di AuthServiceProvider.
 */
class MachinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('machine.view');
    }

    public function view(User $user, Machine $machine): bool
    {
        return $user->hasPermission('machine.view')
            && $user->canAccessOutlet($machine->outlet_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('machine.manage');
    }

    public function update(User $user, Machine $machine): bool
    {
        return $user->hasPermission('machine.manage')
            && $user->canAccessOutlet($machine->outlet_id);
    }

    public function delete(User $user, Machine $machine): bool
    {
        return $this->update($user, $machine);
    }

    /**
     * Perintah yang MENGUBAH keadaan perangkat: sync jam, hapus log di mesin.
     * Sengaja disamakan dengan `update` — semuanya menyentuh perangkat keras.
     */
    public function operate(User $user, Machine $machine): bool
    {
        return $this->update($user, $machine);
    }
}
