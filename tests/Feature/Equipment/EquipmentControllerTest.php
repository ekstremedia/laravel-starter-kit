<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Files\Models\FileItem;
use App\Domains\Operations\Models\Activity;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->workspace = createWorkspace();
    $this->workspace->update(['files_feature_enabled' => true]);

    $this->admin = makeSuperAdmin(User::factory()->create());
});

it('requires authentication', function () {
    $this->get(workspaceUrl($this->workspace, '/equipment'))->assertRedirect('/login');
});

it('lists and creates equipment', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Equipment/Index')->has('stats')->has('categories'));

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment'), [
            'name' => 'Forklift #3',
            'category' => 'Machine',
            'serial' => 'FL-00042',
        ])
        ->assertRedirect();

    expect(Equipment::where('workspace_id', $this->workspace->id)->where('name', 'Forklift #3')->exists())->toBeTrue();
});

it('shows an item with its document area and activity', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, "/equipment/{$equipment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Equipment/Show')
            ->where('equipment.name', $equipment->name)
            ->where('owner.type', 'equipment')
            ->where('owner.id', $equipment->id)
            ->has('activities'));
});

it('blocks non-members', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(workspaceUrl($this->workspace, '/equipment'))
        ->assertForbidden();
});

it('uploads a document owned by the item', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/entity-files'), [
            'owner_type' => 'equipment',
            'owner_id' => $equipment->id,
            'files' => [UploadedFile::fake()->image('photo.png', 100, 100)],
        ])
        ->assertRedirect();

    $item = FileItem::query()->forOwner($equipment)->first();
    expect($item)->not->toBeNull()
        ->and($item->owner_type)->toBe('equipment')
        ->and($item->owner_id)->toBe($equipment->id)
        ->and($item->type)->toBe(FileItem::TYPE_FILE);
});

it('updates and deletes an item', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->put(workspaceUrl($this->workspace, "/equipment/{$equipment->id}"), ['name' => 'Renamed'])
        ->assertRedirect();
    expect($equipment->fresh()->name)->toBe('Renamed');

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/equipment/{$equipment->id}"))
        ->assertRedirect();
    expect(Equipment::find($equipment->id))->toBeNull();
});

it('mass-deletes selected items', function () {
    $ids = Equipment::factory()->count(3)->create(['workspace_id' => $this->workspace->id])->pluck('id')->all();

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment/bulk/delete'), ['ids' => $ids])
        ->assertRedirect();

    expect(Equipment::whereIn('id', $ids)->count())->toBe(0)
        ->and(Equipment::withTrashed()->whereIn('id', $ids)->count())->toBe(3);
});

it('mass re-categorizes selected items', function () {
    $ids = Equipment::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'category' => 'Old',
    ])->pluck('id')->all();

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment/bulk/update'), ['ids' => $ids, 'category' => 'New'])
        ->assertRedirect();

    expect(Equipment::whereIn('id', $ids)->where('category', 'New')->count())->toBe(2);
});

it('sets a cover and rejects a foreign file', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);

    $own = FileItem::factory()->ownedBy($equipment)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
    ]);
    $foreign = FileItem::factory()->ownedBy($other)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/equipment/{$equipment->id}/cover"), ['file_item_id' => $own->id])
        ->assertRedirect();
    expect($equipment->fresh()->cover_file_item_id)->toBe($own->id);

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/equipment/{$equipment->id}/cover"), ['file_item_id' => $foreign->id])
        ->assertStatus(422);
});

it('exports the list as CSV honoring search', function () {
    Equipment::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Exportable Crane']);

    $res = $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment/export?q=Crane&format=csv'));

    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('equipment.csv');
});

it('lists, restores and force-deletes trashed items', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);
    $equipment->delete();

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment/trash'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Equipment/Trash'));

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, "/equipment/trash/{$equipment->id}/restore"))
        ->assertRedirect();
    expect(Equipment::find($equipment->id))->not->toBeNull();

    $equipment->delete();
    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/equipment/trash/{$equipment->id}"))
        ->assertRedirect();
    expect(Equipment::withTrashed()->find($equipment->id))->toBeNull();
});

it('records an activity entry under the equipment log', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->put(workspaceUrl($this->workspace, "/equipment/{$equipment->id}"), ['name' => 'Audited'])
        ->assertRedirect();

    expect(Activity::query()
        ->where('log_name', 'equipment')
        ->where('subject_type', 'equipment')
        ->where('subject_id', $equipment->id)
        ->exists())->toBeTrue();
});
