<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiTenantMemory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * "AI মেমোরী" — tenant-authored Q&A business knowledge (database/sql/
 * chunk41.sql/chunk42.sql, App\Models\AiTenantMemory). Pure DB reads/
 * writes (plus a plain file upload for a voice answer) only — never calls
 * OpenAI, per the task's explicit "don't burn tokens just to save a
 * memory" constraint. Matching a saved Q&A against a real customer
 * message happens later, at AI reply time, in
 * App\Services\AI\AiTenantMemoryService — this controller never touches
 * that. Tenant isolation on update()/destroy() comes from implicit route
 * binding through App\Traits\BelongsToTenant::resolveRouteBinding() (see
 * that trait's docblock) — a route parameter naming another tenant's
 * memory 404s before this controller ever runs.
 *
 * A voice answer (recorded in-browser via MediaRecorder, or uploaded as
 * an existing file — both arrive here identically, as a normal multipart
 * file upload) is stored on the SAME "public" disk and tenant-scoped
 * directory shape (ai-memory/{tenant_id}/...) the Messenger/WhatsApp
 * outbound-media uploads already use in MessengerInboxController::
 * reply()/WhatsAppInboxController::reply() — no new storage mechanism.
 */
class AiMemoryController extends Controller
{
    public function store(Request $request)
    {
        [$fields, $audioFile] = $this->validated($request);

        $memory = new AiTenantMemory($fields);

        if ($audioFile) {
            $memory->answer_audio_path = $this->storeAudio($audioFile);
        }

        $memory->save();

        return back()->with('success', 'প্রশ্ন-উত্তর সেভ হয়েছে।');
    }

    public function update(Request $request, AiTenantMemory $aiMemory)
    {
        [$fields, $audioFile] = $this->validated($request, updating: true);

        if (AiTenantMemory::voiceColumnsReady()) {
            // Switching to text (or replacing the audio file) must not
            // leave an orphaned file behind on disk.
            if ($aiMemory->answer_audio_path && ($fields['answer_type'] === 'text' || $audioFile)) {
                Storage::disk('public')->delete($aiMemory->answer_audio_path);
            }

            if ($audioFile) {
                $fields['answer_audio_path'] = $this->storeAudio($audioFile);
            } elseif ($fields['answer_type'] === 'audio') {
                // Keep the existing file — editing the question text
                // alone must not require re-recording the answer.
                $fields['answer_audio_path'] = $aiMemory->answer_audio_path;
            } else {
                $fields['answer_audio_path'] = null;
            }
        }

        $aiMemory->update($fields);

        return back()->with('success', 'প্রশ্ন-উত্তর আপডেট হয়েছে।');
    }

    public function destroy(AiTenantMemory $aiMemory)
    {
        if ($aiMemory->answer_audio_path) {
            Storage::disk('public')->delete($aiMemory->answer_audio_path);
        }

        $aiMemory->delete();

        return back()->with('success', 'প্রশ্ন-উত্তর মুছে ফেলা হয়েছে।');
    }

    /**
     * @return array{0: array, 1: UploadedFile|null}
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        // Older-than-chunk42 deploys: force text-only, ignore any
        // answer_type/answer_audio the form might still submit — same
        // "additive column not there yet" degrade every other voice-
        // column-gated code path in this app follows.
        $voiceReady = AiTenantMemory::voiceColumnsReady();

        $rules = [
            'question' => 'required|string|max:500',
        ];

        if ($voiceReady) {
            $rules['answer_type'] = 'nullable|in:text,audio';
            $rules['answer'] = 'required_if:answer_type,text|nullable|string|max:2000';
            // On create, a new audio memory must actually include the
            // file; on update, an existing audio memory may be edited
            // (question text, or nothing at all) without re-uploading.
            $rules['answer_audio'] = ($updating ? 'nullable' : 'required_if:answer_type,audio')
                .'|file|mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp3,audio/mp4,audio/wav,audio/x-m4a,audio/aac|max:8192';
        } else {
            $rules['answer'] = 'required|string|max:2000';
        }

        $validated = $request->validate($rules);

        $type = $voiceReady ? ($validated['answer_type'] ?? 'text') : 'text';

        // answer_type/answer_audio_path are only real columns once
        // chunk42.sql is imported — never write keys the DB doesn't have.
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
}
