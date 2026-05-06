<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'in:role_changed,user_deleted'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with(['admin:id,email', 'targetUser:id,email'])
            ->when(filled($validated['action'] ?? null), fn ($q) => $q->where('action', $validated['action']))
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($logs);
    }
}
