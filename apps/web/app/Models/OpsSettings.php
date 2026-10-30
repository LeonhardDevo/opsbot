<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $workspace_id
 * @property float $min_confidence
 * @property string $quiet_hours_start
 * @property string $quiet_hours_end
 * @property bool $reminders_enabled
 * @property CarbonInterface|null $updated_at
 */
class OpsSettings extends Model
{
    public const CREATED_AT = null;

    protected $table = 'ops_settings';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $fillable = ['workspace_id', 'min_confidence', 'quiet_hours_start', 'quiet_hours_end', 'reminders_enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_confidence' => 'float',
            'reminders_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function quietStart(): string
    {
        return substr((string) $this->quiet_hours_start, 0, 5);
    }

    public function quietEnd(): string
    {
        return substr((string) $this->quiet_hours_end, 0, 5);
    }

    /** @return array{min_confidence: float, quiet_hours_start: string, quiet_hours_end: string, reminders_enabled: bool} */
    public function toContract(): array
    {
        return [
            'min_confidence' => round($this->min_confidence, 2),
            'quiet_hours_start' => $this->quietStart(),
            'quiet_hours_end' => $this->quietEnd(),
            'reminders_enabled' => $this->reminders_enabled,
        ];
    }
}
