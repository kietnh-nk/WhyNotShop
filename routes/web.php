<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\HomeController;

use Illuminate\Support\Facades\Route;

Route::middleware(['maintenance_active'])->group(function () {
    //Hiển thị trang bảo trì website
    Route::get('maintenance', [HomeController::class, "maintenance"])->name('user.maintenance');
});


    
    Route::middleware('guest')->group(function () {
        //FE: Trang đăng nhập
        Route::get('login', [AuthenticatedSessionController::class, "create"])->name('user.login');
        Route::post('login', [AuthenticatedSessionController::class, "store"]);
    
        //FE: Trang đăng ký
        Route::get('register', [RegisterController::class, "create"])->name('user.register');
        Route::post('register', [RegisterController::class, "store"]);

        //FE: Trang xác thực tài khoản
        Route::get('verify-email/{user}', [RegisterController::class, "verifyEmail"])
            ->name('user.verification.notice');
        Route::get('account/verify/{id}', [VerifyEmailController::class, 'verifyAccount'])
            ->name('user.verify');
        Route::post('resend-email', [RegisterController::class, "resendEmail"])->name('user.resend_email');

        //FE: Trang xác thực tài khoản thành công
        Route::get('verify-success', [RegisterController::class, "success"])->name('user.verify.success');
        
        //FE: Trang quên mật khẩu
        Route::get('forgot-password', [ForgotPasswordController::class, "create"])->name('user.forgot_password_create');
        Route::post('forgot-password', [ForgotPasswordController::class, "store"])->name('user.forgot_password_store');

        //FE: Trang đỏi mật khẩu mới khi quên mật khẩu
        Route::get('account/change-new-password', [ForgotPasswordController::class, "changePassword"])->name('user.change_new_password');
        Route::post('account/change-new-password', [ForgotPasswordController::class, "updatePassword"]);
    
    });




