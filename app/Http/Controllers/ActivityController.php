<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The audit trail screen — read-only, by design.
 *
 * There is no store/update/destroy here and there never should be: a log the
 * staff can edit proves nothing. Rows age out through `activitylog:clean`
 * on the schedule, not through this controller.
 */
class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Activity::class);

        return Inertia::render('Activity/Index', [
            'activities' => QueryBuilder::for(Activity::class)
                ->with(['causer:id,name,email,role', 'store:id,name'])
                ->allowedFilters(...[
                    AllowedFilter::exact('log_name'),
                    AllowedFilter::exact('event'),
                    AllowedFilter::exact('causer_id'),
                    AllowedFilter::exact('store_id'),
                    AllowedFilter::exact('subject_id'),
                    // The History button on a record's row sends the short
                    // class name; the column stores the FQCN.
                    AllowedFilter::callback(
                        'subject_type',
                        fn (Builder $q, $type) => $q->where('subject_type', 'App\\Models\\'.$type),
                    ),
                    AllowedFilter::callback('search', function (Builder $query, string $search) {
                        $query->where(function (Builder $q) use ($search) {
                            $q->where('description', 'like', "%{$search}%")
                                ->orWhere('subject_type', 'like', "%{$search}%")
                                ->orWhere('ip_address', 'like', "%{$search}%")
                                ->orWhereHas('causer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
                        });
                    }),
                    // Inclusive on both ends: "from 1st to 1st" means that
                    // whole day, not the single instant at midnight.
                    AllowedFilter::callback('from', fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date)),
                    AllowedFilter::callback('to', fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)),
                ])
                ->latest('id')
                ->paginate(PerPage::resolve($request))
                ->withQueryString()
                ->through(fn (Activity $activity) => $this->present($activity)),

            'filters' => [
                'search' => (string) $request->input('filter.search', ''),
                'log_name' => (string) $request->input('filter.log_name', ''),
                'event' => (string) $request->input('filter.event', ''),
                'causer_id' => (string) $request->input('filter.causer_id', ''),
                'store_id' => (string) $request->input('filter.store_id', ''),
                'subject_type' => (string) $request->input('filter.subject_type', ''),
                'subject_id' => (string) $request->input('filter.subject_id', ''),
                'from' => (string) $request->input('filter.from', ''),
                'to' => (string) $request->input('filter.to', ''),
            ],

            'options' => [
                'logNames' => Activity::logNames(),
                'events' => Activity::query()
                    ->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
                'staff' => User::query()->orderBy('name')->get(['id', 'name']),
                'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * One record's own history — where the per-row History button lands.
     *
     * The subject arrives as a short class name from the URL, resolved
     * against a whitelist rather than trusted: `activity/Product/7` is a
     * page, `activity/../../User/1` with an invented class is a 404.
     */
    public function show(Request $request, string $subjectType, int $subjectId): Response
    {
        $this->authorize('viewAny', Activity::class);

        $class = self::SUBJECTS[$subjectType] ?? abort(404);

        $subject = $class::find($subjectId);

        $entries = Activity::query()
            ->where('subject_type', $class)
            ->where('subject_id', $subjectId)
            ->with(['causer:id,name,email,role', 'store:id,name'])
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString()
            ->through(fn (Activity $activity) => $this->present($activity));

        return Inertia::render('Activity/Show', [
            'entries' => $entries,
            'subject' => [
                'type' => $subjectType,
                'id' => $subjectId,
                // A deleted record still has history — fall back to the name
                // its last entry recorded rather than showing a bare id.
                'label' => $subject?->auditLabel()
                    ?? $this->lastKnownLabel($class, $subjectId)
                    ?? "#{$subjectId}",
                'exists' => $subject !== null,
            ],
        ]);
    }

    /** Short URL name → class, for the record-history page. */
    private const SUBJECTS = [
        'Product' => Product::class,
        'Category' => Category::class,
        'Customer' => Customer::class,
        'Store' => Store::class,
        'Register' => Register::class,
        'Stock' => Stock::class,
        'User' => User::class,
    ];

    /** The name a since-deleted record went by, from its final log entry. */
    private function lastKnownLabel(string $class, int $id): ?string
    {
        $last = Activity::query()
            ->where('subject_type', $class)
            ->where('subject_id', $id)
            ->latest('id')
            ->value('description');

        // Descriptions read `Product “Angkor can” deleted` — the quoted part
        // is the label. No quotes means no name was recorded.
        return $last && preg_match('/“(.+)”/u', $last, $m) ? $m[1] : null;
    }

    /**
     * One row as the screen needs it: who, what, where, and the before/after
     * pairs already zipped together so the client does no diffing of its own.
     */
    private function present(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
            'subject_id' => $activity->subject_id,
            'causer' => $activity->causer?->only(['id', 'name', 'email']),
            'store' => $activity->store?->only(['id', 'name']),
            'ip_address' => $activity->ip_address,
            'changes' => $this->changes($activity),
            'properties' => $this->extraProperties($activity),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * `[{field, from, to}]` for a model edit.
     *
     * v5 keeps the attribute diff in its own `attribute_changes` column
     * (not in `properties`): new values under `attributes`, previous ones
     * under `old`. A create has no `old` half and a delete has no
     * `attributes` half, so each side is looked up per field rather than
     * assumed present.
     */
    private function changes(Activity $activity): array
    {
        $diff = $activity->attribute_changes?->all() ?? [];

        $new = $diff['attributes'] ?? [];
        $old = $diff['old'] ?? [];

        return collect(array_keys($new + $old))
            ->map(fn (string $field) => [
                'field' => $field,
                'from' => $this->readable($old[$field] ?? null),
                'to' => $this->readable($new[$field] ?? null),
            ])
            ->values()
            ->all();
    }

    /** The AuditLog:: payloads — properties holds nothing else in v5. */
    private function extraProperties(Activity $activity): array
    {
        return collect($activity->properties ?? [])
            ->except(['attributes', 'old'])
            ->map(fn ($value) => $this->readable($value))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    /** Arrays (the permissions column) and booleans need to read as text. */
    private function readable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
