<?php

namespace App\Services\Migration\Importers;

use App\Models\Department;
use App\Models\Employee;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Employees (v7.35 cutover). Departments are found or created by name.
 */
class EmployeeImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Employees';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Legacy system id, stable across exports', 'example' => 'E-3001', 'aliases' => ['id', 'employee_id']],
            'name' => ['required' => true, 'desc' => 'Full name', 'example' => 'Kwame Boateng', 'aliases' => ['full_name']],
            'emp_code' => ['desc' => 'Staff number (kept as-is when given)', 'example' => 'EMP-0042', 'aliases' => ['staff_no', 'code']],
            'department' => ['desc' => 'Department name (found or created)', 'example' => 'Front Office', 'aliases' => ['dept']],
            'designation' => ['desc' => 'Job title', 'example' => 'Night Auditor', 'aliases' => ['job_title', 'title']],
            'phone' => ['desc' => 'Phone number', 'example' => '+233 20 111 2233'],
            'email' => ['desc' => 'E-mail address', 'example' => 'kwame@example.com'],
            'joining_date' => ['desc' => 'Date of joining (YYYY-MM-DD or DD/MM/YYYY)', 'example' => '2023-02-01'],
            'salary' => ['desc' => 'Monthly salary (numbers only)', 'example' => '4500'],
            'id_proof' => ['desc' => 'ID document reference', 'example' => 'GHA-123456789-0'],
            'address' => ['desc' => 'Home address', 'example' => '12 Ridge Rd, Accra'],
            'is_active' => ['desc' => '1/0 (default 1)', 'example' => '1', 'aliases' => ['active']],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->str($row['name'] ?? null, 255)) {
            return 'name is required.';
        }
        if (! empty($row['joining_date']) && ! $this->date($row['joining_date'])) {
            return "joining_date \"{$row['joining_date']}\" is not a recognised date.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $ext = $this->externalId($row['external_id']);
        $deptName = $this->str($row['department'] ?? null, 80);
        $departmentId = null;
        if ($deptName && ! $dryRun) {
            $dept = Department::whereRaw('lower(name) = ?', [mb_strtolower($deptName)])->first();
            $departmentId = $dept?->id ?? Department::create(['name' => $deptName])->id;
        } elseif ($deptName) {
            $departmentId = Department::whereRaw('lower(name) = ?', [mb_strtolower($deptName)])->value('id');
        }

        $fields = [
            'name' => $this->str($row['name'], 255),
            'emp_code' => $this->str($row['emp_code'] ?? null, 30),
            'department_id' => $departmentId,
            'designation' => $this->str($row['designation'] ?? null, 80),
            'phone' => $this->str($row['phone'] ?? null, 30),
            'email' => $this->str($row['email'] ?? null, 255),
            'joining_date' => $this->date($row['joining_date'] ?? null),
            'salary' => $this->num($row['salary'] ?? null),
            'id_proof' => $this->str($row['id_proof'] ?? null, 60),
            'address' => $this->str($row['address'] ?? null, 255),
            'is_active' => $this->bool($row['is_active'] ?? null, true),
        ];

        $id = $ctx->mappedId('employees', $ext);
        $model = $id ? Employee::find($id) : null;
        if (! $model && ! empty($fields['emp_code'])) {
            $model = Employee::where('emp_code', $fields['emp_code'])->first();
        }
        if (! $model && ! empty($fields['phone'])) {
            $model = Employee::where('phone', $fields['phone'])->where('name', $fields['name'])->first();
        }

        if (! $model) {
            if (! $fields['emp_code']) {
                $fields['emp_code'] = 'IMP-'.$ext; // staff number cannot be empty
            }
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => Employee::class, 'model_id' => null];
            }
            $model = Employee::create($fields);
            $ctx->map('employees', $ext, Employee::class, $model->id);

            return ['status' => 'created', 'message' => null, 'model_type' => Employee::class, 'model_id' => $model->id];
        }

        $dirty = $this->dirty($model, $fields);
        unset($dirty['emp_code']); // staff numbers are never rewritten once assigned
        if (! $dirty) {
            if (! $id && ! $dryRun) {
                $ctx->map('employees', $ext, Employee::class, $model->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => Employee::class, 'model_id' => $model->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => Employee::class, 'model_id' => $model->id];
        }
        $model->fill($dirty)->save();
        $ctx->map('employees', $ext, Employee::class, $model->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => Employee::class, 'model_id' => $model->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        return [
            'Employees in this property now' => Employee::count(),
            'Active employees' => Employee::where('is_active', true)->count(),
            'External ids mapped (all batches)' => \App\Models\DataMigrationIdMap::withoutGlobalScopes()
                ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'employees')->count(),
            'Departments' => Department::count(),
        ];
    }
}
