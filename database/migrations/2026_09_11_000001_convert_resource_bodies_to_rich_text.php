<?php

use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $converter = app(MarkdownGuideConverter::class);
        $documents = app(RichTextDocument::class);
        DB::transaction(function () use ($converter, $documents): void {
            foreach ([['group_resources', 'working_copy'], ['group_resource_revisions', 'snapshot']] as [$table, $field]) {
                foreach (DB::table($table)->select('id', $field)->orderBy('id')->lockForUpdate()->get() as $row) {
                    $snapshot = json_decode($row->{$field} ?? 'null', true);
                    if (! is_array($snapshot) || is_array($snapshot['body'] ?? null)) {
                        continue;
                    }
                    $snapshot['body'] = $converter->convert($snapshot['body'] ?? '', 200000);
                    $snapshot['body_format'] = RichTextDocument::FORMAT;
                    $snapshot['body_text'] = $documents->text($snapshot['body']);
                    DB::table($table)->where('id', $row->id)->update([$field => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Rich-text resource documents cannot be losslessly reverted to Markdown. Restore a database backup to revert this migration.');
    }
};
