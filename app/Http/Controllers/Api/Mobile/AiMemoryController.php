<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AiTenantMemory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * "AI মেমোরী" — mirrors Tenant\AiMemoryController's store/update/destroy
 * exactly (same AiTenantMemory model, same voiceColumnsReady() degrade,
 * same tenant-scoped ai-memory/{tenant_id}/... storage path as the web
 * controller's own docblock describes). Web has no dedicated list
 * endpoint of its own — the Settings page pulls `aiMemories` inline
 * (Tenant\SettingController::index()) — so index() here is additive, not
 * a mirror of an existing web route.
 *
 * Pure DB reads/writes only — never calls OpenAI, same "don't burn
 * tokens just to save a memory" constraint the web controller's docblock
 * states. Matching a saved Q&A against a real customer message happens
 * later, at AI reply time, in App\Services\AI\AiTenantMemoryService,
 * untouched by this controller.
 */
class AiMemoryController extends Controller
{
    public function index()
    {
        if (! AiTenantMemory::tablesReady()) {
            return response()->json(['data' => []]);
        }

        $memories = AiTenantMemory::orderByDesc('id')->get();

        return response()->json(['data' => $memories->map(fn (AiTenantMemory $m) => $this->present($m))->all()]);
    }

    public function store(Request $request)
    {
        [$fields, $audioFile] = $this->validated($request);

        $memory = new AiTenantMemory($fields);

        if ($audioFile) {
            $memory->answer_audio_path = $this->storeAudio($audioFile);
        }

        $memory->save();

        return response()->json($this->present($memory), 201);
    }

    public function update(Request $request, int $aiMemory)
    {
        $memory = AiTenantMemory::where('tenant_id', app('currentTenant')->id)->findOrFail($aiMemory);

        [$fields, $audioFile] = $this->validated($request, updating: true);

        if (AiTenantMemory::voiceColumnsReady()) {
            if ($memory->answer_audio_path && ($fields['answer_type'] === 'text' || $audioFile)) {
                Storage::disk('public')->delete($memory->answer_audio_path);
            }

            if ($audioFile) {
                $fields['answer_audio_path'] = $this->storeAudio($audioFile);
            } elseif ($fields['answer_type'] === 'audio') {
                $fields['answer_audio_path'] = $memory->answer_audio_path;
            } else {
                $fields['answer_audio_path'] = null;
            }
        }

        $memory->update($fields);

        return response()->json($this->present($memory));
    }

    public function destroy(int $aiMemory)
    {
        $memory = AiTenantMemory::where('tenant_id', app('currentTenant')->id)->findOrFail($aiMemory);

        if ($memory->answer_audio_path) {
            Storage::disk('public')->delete($memory->answer_audio_path);
        }
        $memory->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{0: array, 1: \Illuminate\Http\UploadedFile|null}
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $voiceReady = AiTenantMemory::voiceColumnsReady();

        $rules = ['question' => 'required|string|max:500'];

        if ($voiceReady) {
            $rules['answer_type'] = 'nullable|in:text,audio';
            $rules['answer'] = 'required_if:answer_type,text|nullable|string|max:2000';
            $rules['answer_audio'] = ($updating ? 'nullable' : 'required_if:answer_type,audio')
                .'|file|mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp3,audio/mp4,audio/wav,audio/x-m4a,audio/aac|max:8192';
        } else {
            $rules['answer'] = 'required|string|max:2000';
        }

        $validated = $request->validate($rules);

        $type = $voiceReady ? ($validated['answer_type'] ?? 'text') : 'text';

        $fields = $voiceReady
            ? ['question' => $validated['question'], 'answer_type' => $type, 'answer' => $type === 'text' ? $validated['answer'] : null]
            : ['question' => $validated['question'], 'answer' => $validated['answer']];

        $audioFile = ($voiceReady && $type === 'audio') ? $request->file('answer_audio') : null;

        return [$fields, $audioFile];
    }

    protected function storeAudio($file): string
    {
        return $file->store('ai-memory/'.app('currentTenant')->id, 'public');
    }

    protected function present(AiTenantMemory $m): array
    {
        return [
            'id' => $m->id,
            'question' => $m->question,
            'answer_type' => $m->answer_type ?? 'text',
            'answer' => $m->answer,
            'answer_audio_url' => $m->answer_audio_path ? asset('storage/'.$m->answer_audio_path) : null,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }
}
