<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('admin');

        $events = AdminAuditEvent::query()
            ->when($request->string('action')->toString() !== '', fn ($q) => $q->where('action', 'like', $request->string('action')->toString().'%'))
            ->when($request->string('actor')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('actor')->toString()).'%';
                $query->whereHas('actor', fn ($q) => $q->where('email', 'like', $term));
            })
            ->with('actor')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', ['events' => $events, 'filters' => $request->only(['action', 'actor'])]);
    }
}
