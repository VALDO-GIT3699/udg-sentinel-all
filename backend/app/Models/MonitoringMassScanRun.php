<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringMassScanRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'initiated_by_user_id',
        'trigger_mode',
        'status',
        'total_sites',
        'total_tasks',
        'completed_tasks',
        'failed_tasks',
        'started_at',
        'last_progress_at',
        'completed_at',
        'last_error',
    ];

    protected $casts = [
        'initiated_by_user_id' => 'integer',
        'total_sites' => 'integer',
        'total_tasks' => 'integer',
        'completed_tasks' => 'integer',
        'failed_tasks' => 'integer',
        'started_at' => 'immutable_datetime',
        'last_progress_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
