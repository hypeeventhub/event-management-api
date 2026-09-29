<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function raffleEvent(User $owner, string $slug = 'raffle-event'): Event
{
    return Event::create(['created_by' => $owner->id, 'title' => 'Annual Gala', 'slug' => $slug, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
}

test('an event persists ordered duplicate raffle names and settings', function () {
    $event = raffleEvent(User::factory()->create());
    $first = $event->raffleEntries()->create(['name' => 'Pedro', 'position' => 0]);
    $second = $event->raffleEntries()->create(['name' => 'Pedro', 'position' => 1]);
    $settings = $event->raffleSetting()->create(['remove_winners' => true, 'speed' => 3, 'theme' => 'purple']);

    expect($event->raffleEntries()->orderBy('position')->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($settings->remove_winners)->toBeTrue()
        ->and($event->raffleWinners()->getQuery()->getModel()->getFillable())->not->toContain('registration_id');
});

test('owner saves and reloads a normalized event name pool', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $payload = ['names' => [' Pedro ', '', 'Juan', 'Pedro'], 'remove_winners' => false, 'speed' => 5, 'theme' => 'rose'];
    $this->actingAs($owner)->putJson("/api/events/{$event->slug}/raffle/settings", $payload)->assertOk()
        ->assertJsonPath('data.entries.0.name', 'Pedro')->assertJsonPath('data.entries.2.name', 'Pedro');
    $this->getJson("/api/events/{$event->slug}/raffle")->assertOk()
        ->assertJsonPath('data.settings.remove_winners', false)->assertJsonPath('data.settings.speed', 5)
        ->assertJsonPath('data.settings.theme', 'rose')->assertJsonCount(3, 'data.entries');
});

test('scanner and another admin cannot access event raffle data', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $this->actingAs(User::factory()->create())->getJson("/api/events/{$event->slug}/raffle")->assertNotFound();
    $this->actingAs(User::factory()->scanner()->create())->getJson("/api/events/{$event->slug}/raffle")->assertForbidden();
});

test('draw is server selected and confirm stores only event and name', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $this->actingAs($owner)->putJson("/api/events/{$event->slug}/raffle/settings", ['names' => ['Pedro'], 'remove_winners' => true, 'speed' => 3, 'theme' => 'purple'])->assertOk();
    $draw = $this->postJson("/api/events/{$event->slug}/raffle/draws", ['entry_id' => 999])->assertCreated()
        ->assertJsonPath('data.entry.name', 'Pedro')->json('data.draw.id');
    $this->postJson("/api/events/{$event->slug}/raffle/draws/{$draw}/confirm")->assertOk()
        ->assertJsonPath('data.winner.name', 'Pedro')->assertJsonPath('data.removed', true);
    $this->assertDatabaseHas('raffle_winners', ['event_id' => $event->id, 'name' => 'Pedro']);
    expect(DB::getSchemaBuilder()->getColumnListing('raffle_winners'))->not->toContain('registration_id', 'registrants_id');
    $this->assertDatabaseCount('raffle_entries', 0);
    $this->assertDatabaseHas('raffle_draws', ['id' => $draw, 'status' => 'confirmed', 'raffle_entry_id' => null]);
});

test('remove winners off retains the confirmed entry and cancel releases a draw', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $this->actingAs($owner)->putJson("/api/events/{$event->slug}/raffle/settings", ['names' => ['Juan'], 'remove_winners' => false, 'speed' => 2, 'theme' => 'purple'])->assertOk();
    $url = "/api/events/{$event->slug}/raffle/draws";
    $draw = $this->postJson($url)->assertCreated()->json('data.draw.id');
    $this->deleteJson("{$url}/{$draw}")->assertOk()->assertJsonPath('data.draw.status', 'cancelled');
    $second = $this->postJson($url)->assertCreated()->json('data.draw.id');
    $this->postJson("{$url}/{$second}/confirm")->assertOk()->assertJsonPath('data.removed', false);
    $this->assertDatabaseCount('raffle_entries', 1);
    $this->assertDatabaseCount('raffle_winners', 1);
});

test('expired pending draw is released at ten minute boundary', function () {
    $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $this->actingAs($owner)->putJson("/api/events/{$event->slug}/raffle/settings", ['names' => ['Jose'], 'remove_winners' => true, 'speed' => 3, 'theme' => 'purple']);
    $first = $this->postJson("/api/events/{$event->slug}/raffle/draws")->json('data.draw.id');
    $this->travelTo(Carbon::parse('2026-09-29 10:10:00'));
    $second = $this->postJson("/api/events/{$event->slug}/raffle/draws")->assertCreated()->json('data.draw.id');
    expect($second)->not->toBe($first);
    $this->assertDatabaseHas('raffle_draws', ['id' => $first, 'status' => 'cancelled']);
});

test('raffle state supports one thousand names', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $names = collect(range(1, 1005))->map(fn ($number) => "Guest {$number}")->all();
    $this->actingAs($owner)->putJson("/api/events/{$event->slug}/raffle/settings", ['names' => $names, 'remove_winners' => true, 'speed' => 3, 'theme' => 'purple'])->assertOk();
    $this->getJson("/api/events/{$event->slug}/raffle")->assertOk()->assertJsonCount(1005, 'data.entries');
});

test('an orphaned legacy pending draw is cancelled and does not block the raffle', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $event->raffleEntries()->create(['name' => 'Ana', 'position' => 0]);
    $legacy = $event->raffleDraws()->create(['raffle_entry_id' => null, 'status' => 'pending', 'selected_at' => now(), 'expires_at' => now()->addMinutes(10)]);

    $this->actingAs($owner)->postJson("/api/events/{$event->slug}/raffle/draws")->assertCreated()->assertJsonPath('data.entry.name', 'Ana');
    $this->assertDatabaseHas('raffle_draws', ['id' => $legacy->id, 'status' => 'cancelled']);
});

test('repeated draw returns one active reservation and terminal mutations are scoped', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner, 'primary-raffle');
    $other = raffleEvent($owner, 'other-raffle');
    $event->raffleEntries()->create(['name' => 'Ana', 'position' => 0]);
    $url = "/api/events/{$event->slug}/raffle/draws";
    $first = $this->actingAs($owner)->postJson($url)->assertCreated();
    $second = $this->postJson($url)->assertOk();
    expect($second->json('data.draw.id'))->toBe($first->json('data.draw.id'));
    $draw = $first->json('data.draw.id');
    $this->postJson("/api/events/{$other->slug}/raffle/draws/{$draw}/confirm")->assertNotFound();
    $this->postJson("{$url}/{$draw}/confirm")->assertOk();
    $this->postJson("{$url}/{$draw}/confirm")->assertStatus(409);
});

test('winner history is paginated with stable metadata', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    foreach (range(1, 30) as $number) {
        $event->raffleWinners()->create(['name' => "Winner {$number}", 'won_at' => now()->addSeconds($number)]);
    }
    $this->actingAs($owner)->getJson("/api/events/{$event->slug}/raffle?winners_page=1")->assertOk()
        ->assertJsonCount(25, 'data.winners')->assertJsonPath('data.winner_pagination.current_page', 1)
        ->assertJsonPath('data.winner_pagination.last_page', 2)->assertJsonPath('data.winner_pagination.total', 30);
    $this->getJson("/api/events/{$event->slug}/raffle?winners_page=2")->assertOk()->assertJsonCount(5, 'data.winners');
});

test('scanner and another admin cannot mutate raffle settings or draws', function () {
    $owner = User::factory()->create();
    $event = raffleEvent($owner);
    $payload = ['names' => ['Ana'], 'remove_winners' => true, 'speed' => 3, 'theme' => 'purple'];
    $this->actingAs(User::factory()->create())->putJson("/api/events/{$event->slug}/raffle/settings", $payload)->assertNotFound();
    $this->actingAs(User::factory()->scanner()->create())->postJson("/api/events/{$event->slug}/raffle/draws")->assertForbidden();
});
