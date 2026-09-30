<?php

declare(strict_types=1);

use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MonitorTokenController;
use App\Http\Controllers\ScheduleEventController;
use App\Mcp\MusterMcpServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', MusterMcpServer::class)
    ->middleware('bfc.mcp:product,read');

Route::post('api/monitors/{monitorId}/check-ins', [MonitorTokenController::class, 'checkIn'])
    ->name('monitor-check-ins.store');
Route::get('api/monitors/{monitorId}/check-ins', [MonitorTokenController::class, 'history'])
    ->name('monitor-check-ins.index');
Route::post('api/schedule-events', [ScheduleEventController::class, 'store'])
    ->name('schedule-events.store');

Route::middleware('bfc.auth')->group(function (): void {
    Route::resource('monitors', MonitorController::class)->except('destroy');
    Route::patch('monitors/{monitor}/enable', [MonitorController::class, 'enable'])->name('monitors.enable');
    Route::patch('monitors/{monitor}/disable', [MonitorController::class, 'disable'])->name('monitors.disable');
});
