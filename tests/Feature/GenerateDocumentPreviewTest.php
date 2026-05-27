<?php

declare(strict_types=1);

use App\Domains\Files\Jobs\GenerateDocumentPreview;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

it('dispatches the preview job after uploading a PDF', function () {
    Queue::fake([GenerateDocumentPreview::class]);

    $this->seed(RoleAndPermissionSeeder::class);
    $user = User::factory()->create();
    $customer = createCustomer();
    AppSetting::current()->update(['files_feature_enabled' => true]);
    $customer->update(['files_feature_enabled' => true]);
    joinCustomer($user, $customer);
    $user->settings()->merge(['files_enabled' => true]);

    $this->actingAs($user)
        ->post(customerUrl($customer, '/files'), [
            'files' => [UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf')],
        ])
        ->assertRedirect();

    Queue::assertPushed(GenerateDocumentPreview::class);
});

it('skips the preview job for plain images', function () {
    Queue::fake([GenerateDocumentPreview::class]);

    $this->seed(RoleAndPermissionSeeder::class);
    $user = User::factory()->create();
    $customer = createCustomer();
    AppSetting::current()->update(['files_feature_enabled' => true]);
    $customer->update(['files_feature_enabled' => true]);
    joinCustomer($user, $customer);
    $user->settings()->merge(['files_enabled' => true]);

    $this->actingAs($user)
        ->post(customerUrl($customer, '/files'), [
            'files' => [UploadedFile::fake()->image('cat.png')],
        ])
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('gracefully exits when the FileItem is missing', function () {
    expect(fn () => (new GenerateDocumentPreview(999_999))->handle(app(Factory::class)))
        ->not->toThrow(Throwable::class);
});
