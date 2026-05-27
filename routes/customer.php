<?php

declare(strict_types=1);

use App\Domains\Files\Http\Controllers\CompanyFileController;
use App\Domains\Files\Http\Controllers\CompanyFileTrashController;
use App\Domains\Files\Http\Controllers\FileItemController;
use App\Domains\Files\Http\Controllers\FileShareController;
use App\Domains\Files\Http\Controllers\FileTrashController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Tenancy\Http\Controllers\CustomerMembersController;
use App\Domains\Tenancy\Http\Controllers\CustomerProfileController;
use App\Domains\Tenancy\Http\Controllers\DashboardController;
use App\Domains\Users\Http\Controllers\AvatarController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Customer-scoped routes (shared between single- and multi-tenant modes)
|--------------------------------------------------------------------------
|
| This file lists the routes that a logged-in user owns inside a customer.
| It's mounted from `bootstrap/app.php` in one of two shapes depending on
| `config('tenancy.enabled')`:
|
|   - enabled  → prefix `/c/{customer}`, middleware auth+verified+InitializeTenancyByPath,
|                name prefix `customer.`
|   - disabled → root paths, middleware auth+verified, no name prefix
|
| Keep this file as a flat list of route definitions only — no `prefix()`,
| `middleware()`, or `name()` wrappers here. The mounting side decides that.
*/

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile');
Route::post('/profile/avatar', [AvatarController::class, 'store'])->name('profile.avatar.store');
Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');

// Customer "About" — the company's own profile card. View is open to any
// member; edit is gated to customer Admins (and SuperAdmins) by the
// TenantProfilePolicy inside the controller.
Route::get('/about', [CustomerProfileController::class, 'show'])->name('about.show');
Route::get('/about/edit', [CustomerProfileController::class, 'edit'])->name('about.edit');
Route::put('/about', [CustomerProfileController::class, 'update'])->name('about.update');

Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
Route::delete('/notifications', [NotificationController::class, 'destroyAll'])->name('notifications.destroyAll');

// Personal file system (tenant + per-user opt-in enforced in controller)
Route::get('/files', [FileItemController::class, 'index'])->name('files.index');
Route::get('/files/trash', [FileTrashController::class, 'index'])->name('files.trash.index');
Route::post('/files/trash/{id}/restore', [FileTrashController::class, 'restore'])
    ->whereNumber('id')
    ->name('files.trash.restore');
Route::delete('/files/trash/{id}', [FileTrashController::class, 'forceDelete'])
    ->whereNumber('id')
    ->name('files.trash.forceDelete');
Route::delete('/files/trash', [FileTrashController::class, 'empty'])->name('files.trash.empty');

// Company-shared files. Literal segments registered before `/files/{folder}`
// so "company" isn't swallowed by the numeric folder binding.
Route::get('/files/company', [CompanyFileController::class, 'index'])->name('files.company.index');
Route::get('/files/company/trash', [CompanyFileTrashController::class, 'index'])->name('files.company.trash.index');
Route::post('/files/company/trash/{id}/restore', [CompanyFileTrashController::class, 'restore'])
    ->whereNumber('id')
    ->name('files.company.trash.restore');
Route::delete('/files/company/trash/{id}', [CompanyFileTrashController::class, 'forceDelete'])
    ->whereNumber('id')
    ->name('files.company.trash.forceDelete');
Route::post('/files/company/folder', [CompanyFileController::class, 'storeFolder'])->name('files.company.folder.store');
Route::post('/files/company', [CompanyFileController::class, 'store'])
    ->middleware('company.storage.available')
    ->name('files.company.store');
Route::get('/files/company/{folder}', [CompanyFileController::class, 'index'])
    ->whereNumber('folder')
    ->name('files.company.show');
Route::patch('/files/company/{file}', [CompanyFileController::class, 'update'])
    ->whereNumber('file')
    ->name('files.company.update');
Route::delete('/files/company/{file}', [CompanyFileController::class, 'destroy'])
    ->whereNumber('file')
    ->name('files.company.destroy');
Route::get('/files/company/{file}/download', [CompanyFileController::class, 'download'])
    ->whereNumber('file')
    ->name('files.company.download');
Route::delete('/files/company/links/{link}', [CompanyFileController::class, 'unlink'])
    ->whereNumber('link')
    ->name('files.company.links.destroy');

// Share/unshare a personal file to the customer's company tree.
Route::post('/files/{file}/share-to-company', [FileItemController::class, 'share'])
    ->whereNumber('file')
    ->name('files.shareToCompany');
Route::delete('/files/{file}/share-to-company', [FileItemController::class, 'unshare'])
    ->whereNumber('file')
    ->name('files.unshareFromCompany');

Route::get('/files/{folder}', [FileItemController::class, 'index'])
    ->whereNumber('folder')
    ->name('files.show');
Route::post('/files/folder', [FileItemController::class, 'storeFolder'])->name('files.folder.store');
Route::post('/files', [FileItemController::class, 'store'])
    ->middleware('storage.available')
    ->name('files.store');
Route::patch('/files/{file}', [FileItemController::class, 'update'])
    ->whereNumber('file')
    ->name('files.update');
Route::delete('/files/{file}', [FileItemController::class, 'destroy'])
    ->whereNumber('file')
    ->name('files.destroy');
Route::get('/files/{file}/download', [FileItemController::class, 'download'])
    ->whereNumber('file')
    ->name('files.download');

// Share-link management (creating / listing / revoking links). Public viewing
// of shared items is in routes/web.php under /share/*.
Route::get('/files/{file}/shares', [FileShareController::class, 'index'])
    ->whereNumber('file')
    ->name('files.shares.index');
Route::post('/files/{file}/shares', [FileShareController::class, 'store'])
    ->whereNumber('file')
    ->name('files.shares.store');
Route::post('/files/{file}/shares/signed', [FileShareController::class, 'quickSignedLink'])
    ->whereNumber('file')
    ->name('files.shares.signed');
Route::delete('/files/shares/{share}', [FileShareController::class, 'destroy'])->name('files.shares.destroy');

// Customer-Admin — manage the members of the active customer. `role:Admin`
// resolves against the team id set by InitializeTenancyByPath, so it means
// "Admin on THIS customer" (not platform admin).
Route::middleware('customer.admin')->prefix('members')->name('members.')->group(function () {
    Route::get('/', [CustomerMembersController::class, 'index'])->name('index');
    Route::post('/', [CustomerMembersController::class, 'store'])->name('store');
    Route::patch('/{user}/role', [CustomerMembersController::class, 'setRole'])->name('setRole');
    Route::delete('/{user}', [CustomerMembersController::class, 'destroy'])->name('destroy');
});
