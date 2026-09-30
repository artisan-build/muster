<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\Cursor;
use App\Mcp\Tools\Concerns\ValidatesClosedInput;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
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

#[Name('unhealthy_monitors')]
#[Description('List enabled late, missed, or failed monitors in this Muster installation using stable cursor pagination.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class UnhealthyMonitorsTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedToolArray;
    }
    use RespectsEffectCeiling;
    use ValidatesClosedInput;

    /** @var list<string> */
    private const array STATUSES = ['late', 'missed', 'failed'];

    public function handle(Request $request): Response
    {
        $validated = $this->validateClosed($request, [
            'statuses' => ['sometimes', 'array', 'list', 'min:1'],
            'statuses.*' => ['string', Rule::in(self::STATUSES)],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $statuses = $validated['statuses'] ?? self::STATUSES;
        $limit = $validated['limit'] ?? 25;
        $cursorId = $this->cursorId($validated['cursor'] ?? null);

        /** @var Collection<int, Monitor> $monitors */
        $monitors = CredentialPartition::local()->monitors()
            ->where('is_enabled', true)
            ->whereIn('status', $statuses)
            ->when($cursorId !== null, fn (Builder $query): Builder => $query->where('id', '>', $cursorId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $monitors->count() > $limit;
        $page = $monitors->take($limit)->values();

        return Response::json([
            'monitors' => $page->map(static fn (Monitor $monitor): array => [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'status' => $monitor->status,
                'schedule_key' => $monitor->schedule_key,
                'command' => $monitor->command,
                'display' => $monitor->display,
                'expression' => $monitor->expression,
                'timezone' => $monitor->timezone,
                'cadence_seconds' => $monitor->cadence_seconds,
                'grace_seconds' => $monitor->grace_seconds,
                'last_check_in_at' => $monitor->last_check_in_at?->toISOString(),
                'next_due_at' => $monitor->next_due_at?->toISOString(),
                'last_exit_code' => $monitor->last_exit_code,
                'last_runtime' => $monitor->last_runtime,
                'background' => $monitor->background,
            ])->all(),
            'next_cursor' => $hasMore && $page->isNotEmpty()
                ? Cursor::encode(['id' => $page->last()->id])
                : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'statuses' => $schema->array()
                ->items($schema->string()->enum(self::STATUSES))
                ->min(1)
                ->description('Optional subset of unhealthy monitor statuses.')
                ->default(self::STATUSES),
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

    private function cursorId(?string $cursor): ?int
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $values = Cursor::decode($cursor);
        } catch (InvalidArgumentException) {
            $this->invalidCursor();
        }

        if (array_keys($values) !== ['id'] || ! is_int($values['id']) || $values['id'] < 1) {
            $this->invalidCursor();
        }

        return $values['id'];
    }
}
