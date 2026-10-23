<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispute_attachments', function (Blueprint $table) {
            $table->foreignId('dispute_event_id')->nullable()->constrained('dispute_events')->nullOnDelete();
        });
        // Anciennes preuves : rattachement au message du même auteur le plus proche.
        DB::table('dispute_attachments')->orderBy('id')->chunkById(200, function ($attachments) {
            foreach ($attachments as $attachment) {
                $event = DB::table('dispute_events')->where('dispute_id', $attachment->dispute_id)
                    ->where('actor_type', $attachment->author_type)->where('actor_id', $attachment->author_id)
                    ->whereIn('type', ['opened', 'evidence', 'replacement_rejected'])->get()
                    ->sortBy(fn ($event) => abs(strtotime($event->occurred_at) - strtotime($attachment->created_at)))->first();
                if ($event) {
                    DB::table('dispute_attachments')->where('id', $attachment->id)->update(['dispute_event_id' => $event->id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispute_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('dispute_event_id'));
    }
};
