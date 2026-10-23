<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\Cursor;
use App\Mcp\Tools\Concerns\ValidatesClosedInput;
use App\Models\AlertDelivery;
use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('monitor_history')]
#[Description('Read bounded, cursor-paginated check-in and incident history with redacted alert delivery outcomes for one local monitor.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class MonitorHistoryTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedToolArray;
    }
    use RespectsEffectCeiling;
    use ValidatesClosedInput;

    /** @var list<string> */
    private const array EVENT_TYPES = [
        CheckInEvent::TYPE_CHECK_IN,
        CheckInEvent::TYPE_LATE,
        CheckInEvent::TYPE_MISSED,
        CheckInEvent::TYPE_RECOVERED,
        CheckInEvent::TYPE_STARTED,
        CheckInEvent::TYPE_FINISHED,
        CheckInEvent::TYPE_SKIPPED,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
    ];

    public function handle(Request $request): Response
    {
        resolve(ActingPrincipalResolver::class)->require(request());

        $validated = $this->validateClosed($request, [
            'monitor_id' => ['required', 'integer:strict', 'min:1'],
            'event_types' => ['sometimes', 'array', 'list', 'min:1'],
            'event_types.*' => ['string', Rule::in(self::EVENT_TYPES)],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer:strict', 'between:1,100'],
        ]);
        $monitor = CredentialPartition::local()->monitors()->find($validated['monitor_id'], [
            'id',
            'name',
            'status',
        ]);

        if ($monitor === null) {
            return Response::error('Monitor not found.');
        }

        $eventTypes = $validated['event_types'] ?? self::EVENT_TYPES;
        $limit = $validated['limit'] ?? 25;
        $cursor = $this->cursor($validated['cursor'] ?? null);

        /** @var Collection<int, CheckInEvent> $events */
        $events = $monitor->events()
            ->whereIn('event_type', $eventTypes)
            ->when($cursor !== null, function (Builder $query) use ($cursor): void {
                $query->where(function (Builder $query) use ($cursor): void {
                    $query->where('occurred_at', '<', $cursor['occurred_at'])
                        ->orWhere(function (Builder $query) use ($cursor): void {
                            $query->where('occurred_at', $cursor['occurred_at'])
                                ->where('id', '<', $cursor['id']);
                        });
                });
            })
            ->with(['alertDeliveries' => fn ($query) => $query
                ->select([
                    'id',
                    'check_in_event_id',
                    'channel',
                    'status',
                    'attempt_count',
                    'last_error_code',
                    'last_attempt_at',
                    'delivered_at',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('channel')
                ->orderBy('id')])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $events->count() > $limit;
        $page = $events->take($limit)->values();

        return Response::json([
            'monitor' => [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'status' => $monitor->status,
            ],
            'events' => $page->map(static fn (CheckInEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toISOString(),
                'monitor_name' => $event->monitor_name,
                'command' => $event->command,
                'display' => $event->display,
                'expression' => $event->expression,
                'execution_id' => $event->execution_id,
                'timezone' => $event->timezone,
                'exit_code' => $event->exit_code,
                'runtime' => $event->runtime,
                'background' => $event->background,
                'last_check_in_at' => $event->last_check_in_at?->toISOString(),
                'next_due_at' => $event->next_due_at?->toISOString(),
                'alert_deliveries' => $event->alertDeliveries->map(static fn (AlertDelivery $delivery): array => [
                    'id' => $delivery->id,
                    'channel' => $delivery->channel,
                    'status' => $delivery->status,
                    'attempt_count' => $delivery->attempt_count,
                    'last_error_code' => $delivery->last_error_code,
                    'last_attempt_at' => $delivery->last_attempt_at?->toISOString(),
                    'delivered_at' => $delivery->delivered_at?->toISOString(),
                    'created_at' => $delivery->created_at?->toISOString(),
                    'updated_at' => $delivery->updated_at?->toISOString(),
                ])->all(),
            ])->all(),
            'next_cursor' => $hasMore && $page->isNotEmpty()
                ? Cursor::encode([
                    'occurred_at' => $page->last()->occurred_at->format('Y-m-d\TH:i:s.u\Z'),
                    'id' => $page->last()->id,
                ])
                : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'monitor_id' => $schema->integer()->min(1)->required(),
            'event_types' => $schema->array()
                ->items($schema->string()->enum(self::EVENT_TYPES))
                ->min(1)
                ->description('Optional subset of check-in and incident event types.')
                ->default(self::EVENT_TYPES),
            'cursor' => $schema->string()->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = $this->advertisedToolArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @return array{occurred_at: CarbonImmutable, id: int}|null */
    private function cursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $values = Cursor::decode($cursor);
        } catch (InvalidArgumentException) {
            $this->invalidCursor();
        }

        if (array_keys($values) !== ['occurred_at', 'id']
            || ! is_string($values['occurred_at'])
            || ! is_int($values['id'])
            || $values['id'] < 1) {
            $this->invalidCursor();
        }

        try {
            $occurredAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $values['occurred_at'],
                'UTC',
            );
        } catch (InvalidArgumentException) {
            $this->invalidCursor();
        }

        if ($occurredAt === null
            || $occurredAt->format('Y-m-d\TH:i:s.u\Z') !== $values['occurred_at']) {
            $this->invalidCursor();
        }

        return ['occurred_at' => $occurredAt, 'id' => $values['id']];
    }
}
