<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

class UserApiController
{
    public function show(int $id)
    {
        return UserResource::make(User::findOrFail($id));
    }

    public function index()
    {
        return UserResource::collection(User::all());
    }

    public function latest()
    {
        return new UserResource(User::first());
    }

    public function framework()
    {
        // The framework base resource is not an application resource — no edge.
        return JsonResource::collection(User::all());
    }

    public function frameworkNew()
    {
        return new JsonResource(User::first());
    }
}
