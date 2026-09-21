<?php

declare(strict_types=1);

use App\Http\Controllers\MonitorController;
use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function (): void {
    Route::resource('monitors', MonitorController::class)->except('destroy');
    Route::patch('monitors/{monitor}/enable', [MonitorController::class, 'enable'])->name('monitors.enable');
    Route::patch('monitors/{monitor}/disable', [MonitorController::class, 'disable'])->name('monitors.disable');
});
