<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $visibility = $request->boolean('community') ? 'community_show' : 'show';
        $model = Notice::where($visibility, 1);
        if ($request->has('id')) {
            $id = $request->input('id');
            $notice = $model->where('id', $id)->first();
    
            if (!$notice) {
                return response([
                    'message' => 'Notice not found'
                ], 404);
            }
    
            return response([
                'data' => $notice
            ]);
        }
    
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 5);
    
        $pageSize = min(max($pageSize, 1), 100);
    
        $model->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
    
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();
    
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

}
