<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToHotel;

    protected $table = 'employees';

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['clock_pin']; // v4.9 kiosk PIN hash

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'leaving_date' => 'date',
            'is_active' => 'boolean',
            'ot_eligible' => 'boolean',
            'tax_resident' => 'boolean',      // v7.43 Act 896: a non-resident's employment income is taxed at a flat 25%
            'tax_relief' => 'decimal:2',      // v7.43 GRA personal reliefs, monthly figure
            'sc_eligible' => 'boolean',
            'sc_points' => 'decimal:2',
            'salary' => 'decimal:2',
            'contract_end' => 'date',          // v6.9 talent
            'probation_end' => 'date',
            'confirmed_on' => 'date',
            'statement_issued_on' => 'date',
            'has_disability' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function manager() // v6.9 line manager (appraisal reviewer)
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function onboardingTasks() // v6.9
    {
        return $this->hasMany(TalentTask::class);
    }

    public function trainingRecords() // v6.0
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function sopAcknowledgements() // v6.0
    {
        return $this->hasMany(SopAcknowledgement::class);
    }

    public function rosterShifts() // v4.9
    {
        return $this->hasMany(RosterShift::class);
    }

    public function punches()
    {
        return $this->hasMany(TimePunch::class);
    }

        public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function payComponents()
    {
        return $this->hasMany(EmployeePayComponent::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function advances()
    {
        return $this->hasMany(StaffAdvance::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }
}
