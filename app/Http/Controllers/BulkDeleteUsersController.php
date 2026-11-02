<?php

namespace App\Http\Controllers;

use App\Services\BulkDeleteUsersService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BulkDeleteUsersController extends Controller
{
    public function destroy(Request $request, BulkDeleteUsersService $service)
    {
        // Decode objects separately so that {} cannot be mistaken for an empty list.
        $body = json_decode($request->getContent());
        if (!is_object($body) || !isset($body->teachers, $body->students)
            || !is_array($body->teachers) || !is_array($body->students)) {
            return response()->json(['details' => 'invalid data format'], 400);
        }
        $validator = Validator::make((array) $body, [
            'teachers.*' => 'required|uuid',
            'students.*' => 'required|uuid',
        ]);
        if ($validator->fails()) {
            return response()->json(['details' => 'invalid data format'], 400);
        }

        $service->delete($body->teachers, $body->students);
        return response()->noContent();
    }
}
