<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SecurityHeader extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'checked_at',
        'has_hsts',
        'has_csp',
        'has_x_frame_options',
        'has_x_content_type',
        'has_referrer_policy',
        'has_permissions_policy',
        'score_contribution',
        'raw_headers',
    ];

    protected $casts = [
        'checked_at'              => 'immutable_datetime',
        'has_hsts'                => 'boolean',
        'has_csp'                 => 'boolean',
        'has_x_frame_options'     => 'boolean',
        'has_x_content_type'      => 'boolean',
        'has_referrer_policy'     => 'boolean',
        'has_permissions_policy'  => 'boolean',
        'score_contribution'      => 'integer',
        'raw_headers'             => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function getPassedHeadersCount(): int
    {
        return (int) $this->has_hsts
            + (int) $this->has_csp
            + (int) $this->has_x_frame_options
            + (int) $this->has_x_content_type
            + (int) $this->has_referrer_policy
            + (int) $this->has_permissions_policy;
    }

    public function getTotalHeadersCount(): int
    {
        return 6;
    }
}
