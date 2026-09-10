<?php

namespace App\Modules\Broadcasting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UsageMeter extends Model
{
    protected $table = 'usage_meters';

    protected $fillable = ['workspace_id', 'metric', 'period', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'period' => 'integer'];
    }

    /**
     * Add $by to this workspace's counter for $metric in the current month.
     *
     * WHAT WAS WRONG. The previous implementation did:
     *
     *     static::updateOrCreate([workspace, metric, period], ['value' => 0]);
     *     static::where(...)->increment('value', $by);
     *
     * updateOrCreate does not only insert — on an existing row it *writes* the
     * attributes, so every call reset value to 0 and then incremented it. The
     * stored number was therefore always "the size of the most recent call",
     * never a month-to-date total. A workspace could send ten thousand messages
     * and the meter would read 1. Everything downstream inherited that lie:
     * App\Http\Middleware\EnforceLimit compares this number against the plan
     * limit, and HandleInertiaRequests renders it as the usage bar.
     *
     * The fix is a single additive upsert: insert $by, or add $by to what is
     * already there. It is one statement, so two concurrent queue workers
     * metering the same workspace cannot lose an increment the way a
     * read-then-write pair can. The (workspace_id, metric, period) unique index
     * created with the table is what makes the conflict clause resolve.
     *
     * Non-positive $by is ignored: no caller decrements, the column is unsigned
     * so the result could not be stored, and a zero would only insert an empty
     * row that current() already reports as 0.
     */
    public static function track(int $workspaceId, string $metric, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }

        $period = (int) now()->format('Ym');

        // Wrapped through the connection's own grammar so the expression is
        // quoted correctly on MySQL, SQLite and Postgres alike. $by is an int
        // parameter, so interpolating it carries no injection risk.
        $valueColumn = static::query()->getQuery()->getGrammar()->wrap('value');

        static::query()->upsert(
            [[
                'workspace_id' => $workspaceId,
                'metric' => $metric,
                'period' => $period,
                'value' => $by,
            ]],
            ['workspace_id', 'metric', 'period'],
            ['value' => DB::raw($valueColumn.' + '.$by)],
        );
    }

    public static function current(int $workspaceId, string $metric): int
    {
        $period = (int) now()->format('Ym');

        return (int) static::where('workspace_id', $workspaceId)
            ->where('metric', $metric)
            ->where('period', $period)
            ->value('value');
    }
}
