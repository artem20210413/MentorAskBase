<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use App\Services\DocumentIngestion\DocumentUploadService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentUploadService $uploadService) {}

    public function index(): JsonResponse
    {
        $documents = Document::query()->latest('created_at')->get();

        return response()->json([
            'data' => $documents->map(fn (Document $document) => $this->transform($document)),
        ]);
    }

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->uploadService->upload($request->file('file'));
        } catch (RuntimeException $e) {
            // FR-001b: файл пошкоджений або має непідтримуваний формат
            return response()->json([
                'message' => 'Файл не пройшов валідацію.',
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $status = $document->status === 'duplicate' ? 200 : 201;

        return response()->json($this->transform($document), $status);
    }

    public function show(string $id): JsonResponse
    {
        $document = Document::find($id);

        if (! $document) {
            return response()->json(['message' => 'Документ не знайдено.'], 404);
        }

        return response()->json($this->transform($document));
    }

    public function destroy(string $id): JsonResponse
    {
        $document = Document::find($id);

        if (! $document) {
            return response()->json(['message' => 'Документ не знайдено.'], 404);
        }

        // FR-017/FR-017a: м'яке видалення, негайно, незалежно від активних запитів
        $document->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Document $document): array
    {
        return [
            'id' => $document->id,
            'original_name' => $document->original_name,
            'status' => $document->status,
            'failure_reason' => $document->failure_reason,
            'duplicate_of_document_id' => $document->duplicate_of_document_id,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
