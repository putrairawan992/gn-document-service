<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documents retrieved successfully',
            'data' => Document::paginate(15)->where('status', '!=', 'deleted'),
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        $doc = Document::find($id);

        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Document not found'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document retrieved successfully',
            'data' => $doc,
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        $request->validate([
            'name' => 'required|string',
            'file' => 'required|file',
            'storage' => 'in:s3,local',
        ]);

        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $ext = $file->getClientOriginalExtension();
            $sizeKb = (int) ceil($file->getSize() / 1024);
            $storage = $request->input('storage', 's3');

            if ($storage === 's3') {
                $key = 'documents/'.date('Y/m').'/'.Str::random(40).'.'.$ext;
                $uploaded = Storage::disk('s3')->putFileAs(
                    'documents/'.date('Y/m'),
                    $file,
                    Str::random(40).'.'.$ext
                );
                $path = null;
                $s3Key = $uploaded;
                $s3Bucket = config('filesystems.disks.s3.bucket');
            } else {
                $path = $file->store('documents', ['disk' => 'local']);
                $s3Key = null;
                $s3Bucket = null;
            }

            $doc = Document::create([
                'source_id' => $request->input('source_id'),
                'source_type' => $request->input('source_type'),
                'document_type' => $request->input('document_type'),
                'reg_date' => $request->input('reg_date'),
                'document_no' => $request->input('document_no'),
                'name' => $request->input('name'),
                'version_no' => $request->input('version_no'),
                'size_kb' => $sizeKb,
                'ext' => $ext,
                'original_name' => $originalName,
                'path' => $path,
                'has_expired' => $request->input('has_expired', false) == true ? 1 : 0,
                'expired_date' => $request->input('expired_date'),
                'storage' => $storage,
                's3_key' => $s3Key,
                's3_bucket' => $s3Bucket,
                'status' => 'active',
                'created_by' => $user['pk_user_id'],
                'created_date' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document created successfully',
                'data' => $doc,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create document: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        try {
            $doc = Document::findOrFail($id);

            $request->validate([
                'name' => 'sometimes|required|string',
                'file' => 'sometimes|file',
                'storage' => 'in:s3,local',
            ]);

            $data = $request->only([
                'name','document_type','version_no','has_expired','expired_date','status'
            ]);

            // optional file replace
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $ext = $file->getClientOriginalExtension();
                $sizeKb = (int) ceil($file->getSize() / 1024);
                $storage = $request->input('storage', $doc->storage);

                // delete old file
                if ($doc->storage === 's3' && $doc->s3_key) {
                    Storage::disk('s3')->delete($doc->s3_key);
                }
                if ($doc->storage === 'local' && $doc->path) {
                    Storage::disk('local')->delete($doc->path);
                }

                if ($storage === 's3') {
                    $key = 'documents/'.date('Y/m').'/'.Str::random(40).'.'.$ext;
                    Storage::disk('s3')->putFileAs(
                        'documents/'.date('Y/m'),
                        $file,
                        Str::random(40).'.'.$ext
                    );
                    $data['s3_key'] = $key;
                    $data['s3_bucket'] = config('filesystems.disks.s3.bucket');
                    $data['path'] = null;
                } else {
                    $path = $file->store('documents', ['disk' => 'local']);
                    $data['path'] = $path;
                    $data['s3_key'] = null;
                    $data['s3_bucket'] = null;
                }

                $data['storage'] = $storage;
                $data['ext'] = $ext;
                $data['size_kb'] = $sizeKb;
                $data['original_name'] = $file->getClientOriginalName();
            }

            $data['updated_at'] = now();
            $doc->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $doc,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        $doc = Document::findOrFail($id);

        $doc->status = 'deleted';
        $doc->updated_at = now();
        $doc->save();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully',
            'data' => $doc,
        ]);
    }

    /**
     * Retrieve user session stored in Redis by t-service-user login.
     * Key format: user_session:{token}
     */
    private function getUserFromRedis(Request $request)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $key = 'user_session:' . $token;

        // Try using Illuminate Redis facade first, fallback to Predis client if facade not configured
        try {
            $payload = Redis::get($key);
        } catch (\Throwable $e) {
            try {
                $predis = new \Predis\Client();
                $payload = $predis->get($key);
            } catch (\Throwable $e) {
                dd($e);
                $payload = null;
            }
        }

        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);

        return $data ?: null;
    }
}
