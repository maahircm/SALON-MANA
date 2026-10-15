<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('audit.view');
        $logs = AuditLog::with('user:id,name')
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($request->query('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('audit.index', compact('logs'));
    }

    public function show(AuditLog $audit)
    {
        $this->authorize('audit.view');

        return view('audit.show', ['log' => $audit->load('user:id,name')]);
    }
}
