<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Denetim kayıtları: yalnız okunur. Kayıtlar değiştirilemez ve silinemez. */
class AuditLogController extends Controller
{
    /** Panelde filtre olarak gösterilen işlem grupları (action öneki). */
    private const GROUPS = ['auth', 'license', 'customer', 'user'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', Rule::in(self::GROUPS)],
            'user_id' => ['nullable', 'integer'],
            'license_id' => ['nullable', 'integer'],
        ]);

        $query = AuditLog::query()->with('user:id,name,username')->latest('id');

        if (! empty($data['group'])) {
            $query->where('action', 'like', $data['group'].'.%');
        }
        if (! empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        }
        if (! empty($data['license_id'])) {
            $query->where('subject_type', 'License')->where('subject_id', $data['license_id']);
        }

        $page = $query->paginate(50);

        // Lisans kayıtları için kodu tek sorguda getir (N+1 yok).
        $licenseKeys = License::whereIn('id', $page->getCollection()
            ->where('subject_type', 'License')->pluck('subject_id')->unique())
            ->pluck('license_key', 'id');

        $page->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'created_at' => $log->created_at->toIso8601String(),
            'action' => $log->action,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
            'subject' => $log->subject_type ? [
                'type' => $log->subject_type,
                'id' => $log->subject_id,
                'label' => $log->subject_type === 'License' ? ($licenseKeys[$log->subject_id] ?? null) : null,
            ] : null,
            'data' => $log->data,
            'ip_address' => $log->ip_address,
        ]);

        return response()->json([
            ...$page->toArray(),
            // Filtre listesi için kullanıcılar (yalnız ad)
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
