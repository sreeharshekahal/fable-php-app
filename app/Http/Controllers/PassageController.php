<?php

namespace App\Http\Controllers;

use App\Models\Passage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PassageController extends Controller
{
    /**
     * Update status for a passage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $sanitizedId = $this->sanitizeUuid($id);

        if (!$sanitizedId) {
            return response()->json([
                'message' => 'Passage not found'
            ], 404);
        }

        $data = $request->all();

        if (isset($data['status'])) {
            if ($data['status'] === 'true' || $data['status'] === 1 || $data['status'] === '1') {
                $data['status'] = true;
            } elseif ($data['status'] === 'false' || $data['status'] === 0 || $data['status'] === '0') {
                $data['status'] = false;
            }
        }

        $validator = Validator::make($data, [
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $passage = Passage::find($sanitizedId);

        if (!$passage) {
            return response()->json([
                'message' => 'Passage not found'
            ], 404);
        }

        $passage->status = (bool) $data['status'];
        $passage->save();

        return response()->json([
            'message' => 'Passage status updated successfully',
            'data' => $passage
        ], 200);
    }
}
