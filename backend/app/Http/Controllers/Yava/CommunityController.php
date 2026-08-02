<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityInvitation;
use App\Models\CommunityJoinRequest;
use App\Models\CommunityMembership;
use App\Services\Auth\OtpService;
use App\Services\Yava\FarmService;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityController extends Controller
{
    public function index(Request $request)
    {
        $query = Community::query();
        if ($request->user()->role?->value !== 'admin') {
            $query->whereHas('memberships', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'));
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request, FarmService $service)
    {
        $data = $request->validate($this->rules());

        return response()->json(['data' => $service->createCommunity($request->user(), $data)], 201);
    }

    public function show(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community);

        $canManageMembers = $permissions->hasCommunityPermission($request->user(), $community, 'manage_members');
        $community->load('memberships.user.profile');
        $payload = $community->toArray();
        $payload['memberships'] = $community->memberships
            ->map(fn (CommunityMembership $membership) => $this->memberPayload($membership, $canManageMembers))
            ->values();

        return response()->json(['data' => $payload]);
    }

    public function update(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');
        $community->update($request->validate($this->rules(true)));

        return response()->json(['data' => $community->fresh()]);
    }

    public function destroy(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');
        $community->delete();

        return response()->json(null, 204);
    }

    public function invite(Request $request, Community $community, PermissionService $permissions, OtpService $otp)
    {
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');
        $data = $request->validate([
            'email' => ['nullable', 'email', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'required_without:email'],
            'role' => ['sometimes', 'in:member,coordinator,resource_manager,admin'],
            'expires_in_days' => ['sometimes', 'integer', 'between:1,30'],
        ]);
        $code = Str::upper(Str::random(10));
        if (! empty($data['phone'])) {
            $data['phone'] = $otp->normalizePhone($data['phone']);
        }
        $invitation = CommunityInvitation::query()->create([
            'community_id' => $community->id, 'invited_by_user_id' => $request->user()->id,
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? 'member', 'code_hash' => hash('sha256', $code),
            'status' => 'pending', 'expires_at' => now()->addDays($data['expires_in_days'] ?? 7),
        ]);

        return response()->json(['data' => $invitation, 'invitation_code' => $code], 201);
    }

    public function invitations(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');

        return response()->json(['data' => CommunityInvitation::query()->where('community_id', $community->id)->latest()->get()]);
    }

    public function members(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community);

        $canManageMembers = $permissions->hasCommunityPermission($request->user(), $community, 'manage_members');
        $memberships = CommunityMembership::query()->with('user.profile')->where('community_id', $community->id)->get()
            ->map(fn (CommunityMembership $membership) => $this->memberPayload($membership, $canManageMembers));

        return response()->json(['data' => $memberships]);
    }

    public function acceptInvitation(Request $request, string $code, OtpService $otp)
    {
        $invitation = DB::transaction(function () use ($code, $request, $otp): CommunityInvitation {
            $invitation = CommunityInvitation::query()->where('code_hash', hash('sha256', Str::upper($code)))->lockForUpdate()->firstOrFail();
            if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => ['This invitation is invalid or has expired.']]);
            }
            if ($invitation->email && strcasecmp($invitation->email, $request->user()->email) !== 0) {
                throw ValidationException::withMessages(['code' => ['This invitation belongs to another account.']]);
            }
            if ($invitation->phone && (! $request->user()->phone_verified_at || $otp->normalizePhone((string) $request->user()->phone) !== $invitation->phone)) {
                throw ValidationException::withMessages(['code' => ['Verify the invited phone number before accepting this invitation.']]);
            }
            CommunityMembership::query()->updateOrCreate(
                ['community_id' => $invitation->community_id, 'user_id' => $request->user()->id],
                ['role' => $invitation->role, 'status' => 'active', 'approved_by_user_id' => $invitation->invited_by_user_id, 'joined_at' => now(), 'revoked_at' => null]
            );
            $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);

            return $invitation->fresh();
        }, 3);

        return response()->json(['data' => $invitation]);
    }

    public function requestJoin(Request $request, Community $community)
    {
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        $join = CommunityJoinRequest::query()->updateOrCreate(
            ['community_id' => $community->id, 'user_id' => $request->user()->id],
            ['message' => $data['message'] ?? null, 'status' => 'pending', 'decided_by_user_id' => null, 'decided_at' => null]
        );

        return response()->json(['data' => $join], 201);
    }

    public function joinRequests(Request $request, Community $community, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');

        return response()->json(['data' => CommunityJoinRequest::query()->with('user:id,email')->where('community_id', $community->id)->latest()->get()]);
    }

    public function decideJoin(Request $request, Community $community, CommunityJoinRequest $joinRequest, PermissionService $permissions)
    {
        abort_unless($joinRequest->community_id === $community->id, 404);
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');
        $status = $request->validate(['status' => ['required', 'in:approved,rejected']])['status'];
        $joinRequest = DB::transaction(function () use ($joinRequest, $request, $status): CommunityJoinRequest {
            $locked = CommunityJoinRequest::query()->lockForUpdate()->findOrFail($joinRequest->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['This join request has already been decided.']]);
            }
            $locked->update(['status' => $status, 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()]);
            if ($status === 'approved') {
                CommunityMembership::query()->updateOrCreate(
                    ['community_id' => $locked->community_id, 'user_id' => $locked->user_id],
                    ['role' => 'member', 'status' => 'active', 'approved_by_user_id' => $request->user()->id, 'joined_at' => now(), 'revoked_at' => null]
                );
            }

            return $locked->fresh();
        }, 3);

        return response()->json(['data' => $joinRequest]);
    }

    public function approveJoin(Request $request, Community $community, CommunityJoinRequest $joinRequest, PermissionService $permissions)
    {
        $request->merge(['status' => 'approved']);

        return $this->decideJoin($request, $community, $joinRequest, $permissions);
    }

    public function rejectJoin(Request $request, Community $community, CommunityJoinRequest $joinRequest, PermissionService $permissions)
    {
        $request->merge(['status' => 'rejected']);

        return $this->decideJoin($request, $community, $joinRequest, $permissions);
    }

    public function updateMember(Request $request, Community $community, CommunityMembership $membership, PermissionService $permissions)
    {
        abort_unless($membership->community_id === $community->id, 404);
        $permissions->authorizeCommunity($request->user(), $community, 'manage_members');
        $data = $request->validate(['role' => ['sometimes', 'in:member,coordinator,resource_manager,admin'], 'status' => ['sometimes', 'in:active,revoked']]);
        $membership = DB::transaction(function () use ($membership, $community, $data): CommunityMembership {
            Community::query()->lockForUpdate()->findOrFail($community->id);
            $locked = CommunityMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $removesAdmin = $locked->role === 'admin' && $locked->status === 'active'
                && (($data['role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active');
            if ($removesAdmin && CommunityMembership::query()->where('community_id', $community->id)->where('role', 'admin')->where('status', 'active')->count() === 1) {
                throw ValidationException::withMessages(['membership' => ['Assign another active Community Admin before removing the sole administrator.']]);
            }
            $locked->update($data + (isset($data['status']) && $data['status'] === 'revoked' ? ['revoked_at' => now()] : []));

            return $locked->fresh();
        }, 3);

        return response()->json(['data' => $membership]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'timezone' => ['sometimes', 'timezone:all'], 'country_code' => ['sometimes', 'string', 'size:2'],
            'state_code' => ['nullable', 'string', 'max:10'], 'district' => ['nullable', 'string', 'max:150'],
            'taluk' => ['nullable', 'string', 'max:150'], 'locality' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function memberPayload(CommunityMembership $membership, bool $includePrivateContact): array
    {
        $user = $membership->user;
        $payload = $membership->withoutRelations()->toArray();
        $payload['user'] = array_filter([
            'id' => $user?->id,
            'name' => $user?->profile?->name,
            'surname' => $user?->profile?->surname,
            'email' => $includePrivateContact ? $user?->email : null,
            'phone' => $includePrivateContact ? $user?->phone : null,
        ], static fn ($value) => $value !== null);

        return $payload;
    }
}
