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
            'data' => Document::paginate(15),
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
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension();
        $sizeKb = (int) ceil($file->getSize() / 1024);
        $storage = $request->input('storage', 's3');
        $path = null;
        $s3Key = null;
        $s3Bucket = null;
        $result = false;
        if ($storage === 's3') {
            $dir = 'test/' . date('Y/m');
            $filename = Str::random(40) . '.' . $ext;
            try {
                $key = rtrim($dir, '/') . '/' . $filename;
                if (method_exists(Storage::disk('s3'), 'writeStream')) {
                    $stream = fopen($file->getRealPath(), 'rb');
                    if ($stream === false) {
                        throw new \RuntimeException('Cannot open upload temp file stream.');
                    }

                    Storage::disk('s3')->writeStream($key, $stream, [
                        // 'visibility' => 'public', // atau 'public'
                        'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
                        'throw' => true, 
                    ]);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                } else {
                    $returned = Storage::disk('s3')->putFileAs($dir, $file, $filename, [
                        'visibility' => 'private',
                        'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
                        'throw' => true,
                    ]);
                    $key = is_string($returned) ? $returned : $key;
                }
                $exists = Storage::disk('s3')->exists($key);
                if (!$exists) {
                    throw new \RuntimeException("Upload reported success but object not found at key: {$key}");
                }

                $s3Key = $key;
                $s3Bucket = config('filesystems.disks.s3.bucket');
                $result = true;
            } catch (\Throwable $e) {
                report($e);
                return response()->json([
                    'success' => false,
                    'message' => 'Upload to S3 failed: ' . $e->getMessage(),
                ], 422);
            }
        } else {
            $path = $file->store('documents', ['disk' => 'local']);
            $result = (bool) $path;
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
            'has_expired' => $request->boolean('has_expired') ? 1 : 0,
            'expired_date' => $request->input('expired_date'),
            'storage' => $storage,
            's3_key' => $s3Key,
            's3_bucket' => $s3Bucket,
            'status' => $request->input('status'),
            // 'created_by' => 0, // sesuaikan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document created successfully',
            'data' => $doc,
            'result' => $result,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        $doc = Document::findOrFail($id);

        $data = $request->only([
            'name',
            'document_type',
            'version_no',
            'has_expired',
            'expired_date',
            'status'
        ]);
        if (array_key_exists('has_expired', $data)) {
            $data['has_expired'] = (bool) $data['has_expired'] ? 1 : 0;
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $sizeKb = (int) ceil($file->getSize() / 1024);

            if ($doc->storage === 's3') {
                $dir = 'documents/' . date('Y/m');
                $filename = Str::random(40) . '.' . $ext;

                try {
                    $newKey = Storage::disk('s3')->putFileAs($dir, $file, $filename, [
                        'visibility' => 'private',
                        'ContentType' => $file->getMimeType(),
                        'throw' => true,
                    ]);

                    // hapus lama setelah yang baru sukses
                    if ($doc->s3_key) {
                        Storage::disk('s3')->delete($doc->s3_key);
                    }

                    $data['s3_key'] = $newKey;
                } catch (\Throwable $e) {
                    report($e);
                    return response()->json([
                        'success' => false,
                        'message' => 'Upload to S3 failed: ' . $e->getMessage(),
                    ], 422);
                }
            } else {
                if ($doc->path) {
                    Storage::disk('local')->delete($doc->path);
                }
                $data['path'] = $file->store('documents', ['disk' => 'local']);
            }

            $data['ext'] = $ext;
            $data['size_kb'] = $sizeKb;
            $data['original_name'] = $file->getClientOriginalName();
        }

        $doc->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Document updated successfully',
            'data' => $doc,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->getUserFromRedis($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)'], 401);
        }

        $doc = Document::findOrFail($id);

        if ($doc->storage === 's3' && $doc->s3_key) {
            Storage::disk('s3')->delete($doc->s3_key);
        }
        if ($doc->path) {
            Storage::disk('local')->delete($doc->path);
        }

        $doc->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully',
            'data' => $doc,
        ]);
    }

    private function getUserFromRedis(Request $request)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $key = 'user_session:' . $token;
        try {
            $predis = new \Predis\Client();
            $payload = $predis->get($key);
        } catch (\Throwable $e) {
            $payload = null;
        }

        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!$data || !isset($data['pk_user_id'])) {
            return null;
        }

        try {
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getUserIdFromRedis(Request $request)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $key = 'user_session:' . $token;
        try {
            $predis = new \Predis\Client();
            $payload = $predis->get($key);
        } catch (\Throwable $e) {
            $payload = null;
        }

        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!$data || !isset($data['pk_user_id'])) {
            return null;
        }

        try {
            return $data['pk_user_id'];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
