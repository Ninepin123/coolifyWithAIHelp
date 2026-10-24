<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to the AI deployment assistant for a failed deployment: the plan it proposed and what
 * happened to it.
 */
class AiDeploymentAttempt extends BaseModel
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_READY = 'ready';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISMISSED = 'dismissed';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTOMATIC = 'automatic';

    /** @var array<int, string> */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ANALYZING];

    protected $fillable = [
        'team_id',
        'application_id',
        'integration_token_id',
        'deployment_uuid',
        'trigger',
        'status',
        'diagnosis',
        'summary',
        'fixable',
        'plan',
        'plan_hash',
        'error',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'created_resources',
        'deployment_uuids',
        'requested_by',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'fixable' => 'boolean',
            'plan' => 'array',
            'created_resources' => 'array',
            'deployment_uuids' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function canBeApplied(): bool
    {
        return $this->status === self::STATUS_READY && $this->fixable && is_array($this->plan);
    }
}
