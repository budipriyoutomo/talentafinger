<?php

namespace App\Services;

use App\Models\EmployeeMapping;

class EmployeeMappingService
{
    public function findTalentaId(string $machineId, string $bioId): ?string
    {
        $mapping = EmployeeMapping::with('employee')
            ->where('machine_id', $machineId)
            ->where('biometric_id_lokal', $bioId)
            ->first();

        return $mapping?->employee?->talenta_employee_id;
    }

    public function findEmployeeName(string $machineId, string $bioId): ?string
    {
        $mapping = EmployeeMapping::with('employee')
            ->where('machine_id', $machineId)
            ->where('biometric_id_lokal', $bioId)
            ->first();

        return $mapping?->employee?->name;
    }
}
