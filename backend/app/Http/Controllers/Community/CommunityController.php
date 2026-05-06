<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\StoreCommunityPostRequest;
use App\Http\Requests\Community\UpdateCommunityPostRequest;
use App\Http\Resources\Community\CommunityPostResource;
use App\Models\CommunityPost;
use App\Models\Plot;
use App\Services\Community\CommunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, CommunityService $service)
    {
        $owner = $this->resolveGardenOwner($request);
        $posts = $service->listFeed($owner);

        return CommunityPostResource::collection($posts);
    }

    public function plotFeed(Request $request, Plot $plot, CommunityService $service)
    {
        $owner = $this->resolveGardenOwner($request);
        $posts = $service->listByPlot($owner, $plot);

        return CommunityPostResource::collection($posts);
    }

    public function store(StoreCommunityPostRequest $request, CommunityService $service)
    {
        $owner = $this->resolveGardenOwner($request);
        $profile = $owner->profile;

        abort_unless($profile, 403, 'Naudotojas neturi profilio.');

        $post = $service->createPost($owner, $profile, $request->validated());

        return CommunityPostResource::make($post)
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateCommunityPostRequest $request,
        CommunityPost $post,
        CommunityService $service
    ) {
        $updatedPost = $service->updatePost(
            $this->resolveGardenOwner($request),
            $post,
            $request->validated()
        );

        return CommunityPostResource::make($updatedPost);
    }

    public function destroy(Request $request, CommunityPost $post, CommunityService $service): JsonResponse
    {
        $service->deletePost($this->resolveGardenOwner($request), $post);

        return response()->json([
            'message' => "\u{012E}ra\u{0161}as s\u{0117}kmingai pa\u{0161}alintas",
        ]);
    }
}
