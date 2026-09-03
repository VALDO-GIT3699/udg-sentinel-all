<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OfficialBaselineSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'row_number',
        'normalized_domain',
        'classification',
        'entity',
        'site_name',
        'domain',
        'is_active',
        'cms_label',
        'server_ip',
        'certificate_label',
        'project_status',
        'comments',
        'ticket_number',
        'raw_payload',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'is_active' => 'boolean',
        'raw_payload' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OfficialBaselineSnapshot::class, 'snapshot_id');
    }
}
