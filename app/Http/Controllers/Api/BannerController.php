<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;

class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::where('is_active', true)
            ->orderBy('position')
            ->get()
            ->map(fn ($banner) => [
                'id' => $banner->id,
                'title' => $banner->title,
                'subtitle' => $banner->description,
                'image' => $banner->image_path ? $banner->image_url : null,
                'link' => $banner->link,
                'position' => $banner->position,
            ]);

        return response()->json([
            'success' => true,
            'banners' => $banners,
        ]);
    }
}
