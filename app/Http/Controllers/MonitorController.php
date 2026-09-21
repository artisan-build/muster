<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpsertMonitorRequest;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class MonitorController extends Controller
{
    public function index(): View
    {
        return view('monitors.index', [
            'monitors' => Monitor::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('monitors.create');
    }

    public function store(UpsertMonitorRequest $request): RedirectResponse
    {
        $monitor = CredentialPartition::local()->monitors()->create($request->validated());

        return to_route('monitors.show', $monitor);
    }

    public function show(Monitor $monitor): View
    {
        $monitor->load([
            'events' => fn ($query) => $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id'),
            'executions' => fn ($query) => $query
                ->where('status', 'running')
                ->orderBy('started_at')
                ->orderBy('id'),
        ]);

        return view('monitors.show', ['monitor' => $monitor]);
    }

    public function edit(Monitor $monitor): View
    {
        return view('monitors.edit', ['monitor' => $monitor]);
    }

    public function update(UpsertMonitorRequest $request, Monitor $monitor): RedirectResponse
    {
        $monitor->update($request->validated());

        return to_route('monitors.show', $monitor);
    }

    public function enable(Monitor $monitor): RedirectResponse
    {
        $monitor->forceFill(['is_enabled' => true])->save();

        return to_route('monitors.show', $monitor);
    }

    public function disable(Monitor $monitor): RedirectResponse
    {
        $monitor->forceFill(['is_enabled' => false])->save();

        return to_route('monitors.show', $monitor);
    }
}
