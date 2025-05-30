<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Auth\EmailVerificationPromptController as AdminEmailVerificationPromptController;
use App\Http\Controllers\Admin\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\Auth\VerifyEmailController;

use App\Http\Controllers\Admin\ProductController;

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('test', function(){
    return view('admin.Test.main');
});
Route::middleware(['auth.admin'])->group(function () {
    Route::get('logout', [AuthenticatedSessionController::class, "destroy"])->name('admin.logout');
    Route::get('verify-email', [AdminEmailVerificationPromptController::class, "__invoke"])
        ->name('admin.verification.notice');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('admin.verification.send');
    Route::get('account/verify/{id}', [VerifyEmailController::class, 'verifyAccount'])
        ->name('admin.user.verify');
    Route::get('verify-email/success', [VerifyEmailController::class, 'success'])
        ->name('admin.verify.success');
});

Route::middleware(['auth.admin', 'admin.verified'])->group(function () {
    Route::get('/', [DashboardController::class, "index"])->name('admin.home');
    Route::get('/statistical', [DashboardController::class, "statistical"])->name('admin.statistical');

    Route::group(['prefix' => 'users'], function(){
        Route::get('/', [UserController::class, "index"])->name('admin.users_index');
        Route::get('create', [UserController::class, "create"])->name('admin.users_create');
        Route::post('create', [UserController::class, "store"])->name('admin.users_store');
        Route::get('edit/{user}', [UserController::class, "edit"])->name('admin.users_edit');
        Route::post('update/{user}', [UserController::class, "update"])->name('admin.users_update');
        Route::post('delete', [UserController::class, "delete"])->name('admin.users_delete');
    });

    
    Route::group(['prefix' => 'products'], function(){
        Route::get('/', [ProductController::class, "index"])->name('admin.product_index');
        Route::get('create', [ProductController::class, "create"])->name('admin.products_create');
        Route::post('create', [ProductController::class, "store"])->name('admin.products_store');
        Route::get('update/{product}', [ProductController::class, "edit"])->name('admin.products_edit');
        Route::post('update/{product}', [ProductController::class, "update"])->name('admin.products_update');
        Route::post('delete', [ProductController::class, "delete"])->name('admin.products_delete');
    });  
});