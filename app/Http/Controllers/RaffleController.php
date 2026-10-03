<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\RaffleDraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class RaffleController extends Controller
{
    public const PENDING_MINUTES = 10;

    private const THEMES = ['purple', 'violet', 'indigo', 'blue', 'teal', 'green', 'amber', 'orange', 'rose', 'pink'];

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);
        $this->expire($event);
        $settings = $event->raffleSetting()->firstOrCreate([], ['remove_winners' => true, 'speed' => 3, 'theme' => 'purple']);
        $pending = $event->raffleDraws()->where('status', 'pending')->whereNotNull('raffle_entry_id')->where('expires_at', '>', now())->with('raffleEntry')->latest('id')->first();
        $winners = $event->raffleWinners()->orderByDesc('won_at')->orderByDesc('id')->paginate(25, ['id', 'name', 'won_at'], 'winners_page');

        return response()->json(['data' => [
            'event' => $event->only(['id', 'title', 'slug']),
            'settings' => $this->settings($settings),
            'entries' => $event->raffleEntries()->orderBy('position')->get(['id', 'name', 'position']),
            'pending_draw' => $pending ? $this->draw($pending) : null,
            'winners' => $winners->items(),
            'winner_pagination' => ['current_page' => $winners->currentPage(), 'last_page' => $winners->lastPage(), 'total' => $winners->total()],
        ]]);
    }

    public function updateSettings(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);
        $data = $request->validate([
            'names' => ['present', 'array', 'max:5000'], 'names.*' => ['nullable', 'string', 'max:255'],
            'remove_winners' => ['required', 'boolean'], 'speed' => ['required', 'integer', 'between:1,5'],
            'theme' => ['required', Rule::in(self::THEMES)],
        ]);
        $names = collect($data['names'])->map(fn ($name) => trim((string) $name))->filter(fn ($name) => $name !== '')->values();

        return DB::transaction(function () use ($event, $data, $names) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->expire($event);
            if ($event->raffleDraws()->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                return response()->json(['message' => 'Finish or cancel the current draw before changing settings.'], 409);
            }
            $setting = $event->raffleSetting()->updateOrCreate([], collect($data)->only(['remove_winners', 'speed', 'theme'])->all());
            $event->raffleEntries()->delete();
            $stamp = now();
            if ($names->isNotEmpty()) {
                $event->raffleEntries()->insert($names->map(fn ($name, $position) => ['event_id' => $event->id, 'name' => $name, 'position' => $position, 'created_at' => $stamp, 'updated_at' => $stamp])->all());
            }

            return response()->json(['data' => ['settings' => $this->settings($setting), 'entries' => $event->raffleEntries()->orderBy('position')->get(['id', 'name', 'position'])]]);
        });
    }

    public function uploadLogo(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);
        $validated = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $disk = Storage::disk('public');
        $path = $validated['logo']->store("raffle-logos/{$event->id}", 'public');

        if (! $path) {
            return response()->json(['message' => 'The logo could not be saved.'], 500);
        }

        try {
            [$setting, $oldPath] = DB::transaction(function () use ($event, $path): array {
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $setting = $event->raffleSetting()->lockForUpdate()->first();
                if (! $setting) {
                    $setting = $event->raffleSetting()->create([
                        'remove_winners' => true,
                        'speed' => 3,
                        'theme' => 'purple',
                    ]);
                }
                $oldPath = $setting->logo_path;
                $setting->update(['logo_path' => $path]);

                return [$setting->refresh(), $oldPath];
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        $this->deleteLogo($oldPath, $event->id);

        return response()->json(['data' => ['settings' => $this->settings($setting)]]);
    }

    public function removeLogo(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);

        [$setting, $oldPath] = DB::transaction(function () use ($event): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $setting = $event->raffleSetting()->lockForUpdate()->first();
            if (! $setting) {
                $setting = $event->raffleSetting()->create([
                    'remove_winners' => true,
                    'speed' => 3,
                    'theme' => 'purple',
                ]);
            }
            $oldPath = $setting->logo_path;
            $setting->update(['logo_path' => null]);

            return [$setting->refresh(), $oldPath];
        });

        $this->deleteLogo($oldPath, $event->id);

        return response()->json(['data' => ['settings' => $this->settings($setting)]]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($request, $event) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->expire($event);
            $pending = $event->raffleDraws()->where('status', 'pending')->where('expires_at', '>', now())->with('raffleEntry')->first();
            if ($pending) {
                return response()->json(['data' => $this->draw($pending)]);
            }
            $ids = $event->raffleEntries()->pluck('id');
            if ($ids->isEmpty()) {
                return response()->json(['message' => 'Add at least one name before starting the raffle.'], 409);
            }
            $entry = $event->raffleEntries()->findOrFail($ids[random_int(0, $ids->count() - 1)]);
            $now = now();
            $draw = $event->raffleDraws()->create(['raffle_entry_id' => $entry->id, 'selected_by' => $request->user()->id, 'status' => 'pending', 'selected_at' => $now, 'expires_at' => $now->copy()->addMinutes(self::PENDING_MINUTES)]);
            $draw->setRelation('raffleEntry', $entry);

            return response()->json(['data' => $this->draw($draw)], 201);
        });
    }

    public function confirm(Request $request, Event $event, string $draw): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($event, $draw) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $record = $event->raffleDraws()->whereKey($draw)->lockForUpdate()->with('raffleEntry')->first();
            if (! $record) {
                return $this->missing();
            }
            if ($record->status !== 'pending' || $record->expires_at->lte(now())) {
                return response()->json(['message' => 'Draw is no longer pending.'], 409);
            }
            if (! $record->raffleEntry) {
                return $this->missing();
            }
            $now = now();
            $entry = $record->raffleEntry;
            $winner = $event->raffleWinners()->create(['name' => $entry->name, 'won_at' => $now]);
            $record->update(['status' => 'confirmed', 'confirmed_at' => $now]);
            $removed = $event->raffleSetting()->value('remove_winners') ?? true;
            if ($removed) {
                $entry->delete();
            }

            return response()->json(['data' => ['draw' => $this->drawData($record), 'winner' => ['id' => $winner->id, 'name' => $winner->name, 'won_at' => $winner->won_at->toISOString()], 'removed' => $removed]]);
        });
    }

    public function destroy(Request $request, Event $event, string $draw): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($event, $draw) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $record = $event->raffleDraws()->whereKey($draw)->lockForUpdate()->with('raffleEntry')->first();
            if (! $record || ! $record->raffleEntry) {
                return $this->missing();
            }
            if ($record->status !== 'pending') {
                return response()->json(['message' => 'Draw is no longer pending.'], 409);
            }
            $record->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return response()->json(['data' => $this->draw($record)]);
        });
    }

    private function owner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function expire(Event $event): void
    {
        $event->raffleDraws()->where('status', 'pending')->whereNull('raffle_entry_id')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $event->raffleDraws()->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    private function settings($value): array
    {
        return [
            'remove_winners' => $value->remove_winners,
            'speed' => $value->speed,
            'theme' => $value->theme,
            'logo_path' => $value->logo_path,
            'logo_url' => $value->logo_path ? Storage::disk('public')->url($value->logo_path) : null,
        ];
    }

    private function deleteLogo(?string $path, int $eventId): void
    {
        if ($path && str_starts_with($path, "raffle-logos/{$eventId}/")) {
            Storage::disk('public')->delete($path);
        }
    }

    private function drawData(RaffleDraw $draw): array
    {
        return ['id' => $draw->id, 'status' => $draw->status, 'selected_at' => $draw->selected_at->toISOString(), 'expires_at' => $draw->expires_at->toISOString(), 'confirmed_at' => $draw->confirmed_at?->toISOString(), 'cancelled_at' => $draw->cancelled_at?->toISOString()];
    }

    private function draw(RaffleDraw $draw): array
    {
        return ['draw' => $this->drawData($draw), 'entry' => ['id' => $draw->raffleEntry->id, 'name' => $draw->raffleEntry->name, 'position' => $draw->raffleEntry->position]];
    }

    private function missing(): JsonResponse
    {
        return response()->json(['message' => 'Raffle draw not found.'], 404);
    }
}
