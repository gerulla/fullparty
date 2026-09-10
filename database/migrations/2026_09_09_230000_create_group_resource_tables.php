<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_resource_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('visibility')->default('private');
            $table->json('customization')->nullable();
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('group_resource_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('group_resource_collections')->noActionOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('icon', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->unique(['group_id', 'slug']);
            $table->index(['group_id', 'parent_id', 'sort_order'], 'resource_collection_order');
        });

        Schema::create('group_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained('group_resource_collections')->noActionOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug', 160);
            $table->string('access_level')->default('everyone');
            $table->string('management_access_level')->default('everyone');
            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->unsignedBigInteger('pending_revision_id')->nullable();
            $table->json('working_copy')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('editing_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('editing_token_hash', 64)->nullable();
            $table->timestamp('editing_expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'slug']);
            $table->index(['group_id', 'status', 'access_level'], 'resource_reader_lookup');
        });

        Schema::create('group_resource_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->unique(['group_id', 'name']);
        });

        Schema::create('group_resource_tag', function (Blueprint $table) {
            $table->foreignId('resource_id')->constrained('group_resources')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('group_resource_tags')->cascadeOnDelete();
            $table->primary(['resource_id', 'tag_id']);
        });

        Schema::create('group_resource_activity_type', function (Blueprint $table) {
            $table->foreignId('resource_id')->constrained('group_resources')->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['resource_id', 'activity_type_id']);
        });

        Schema::create('group_resource_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('group_resources')->cascadeOnDelete();
            $table->foreignId('editor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('editor');
            $table->json('snapshot');
            $table->string('summary', 300);
            $table->string('state')->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'state']);
        });

        Schema::create('group_resource_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('group_resources')->cascadeOnDelete();
            $table->string('slug', 160);
            $table->unique(['group_id', 'slug']);
        });

        Schema::create('group_resource_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->unique()->constrained('group_resources')->cascadeOnDelete();
            $table->string('name', 64);
            $table->boolean('enabled')->default(false);
            $table->json('embed');
            $table->timestamps();
            $table->unique(['group_id', 'name']);
        });

        Schema::create('group_resource_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('group_resources')->noActionOnDelete();
            $table->foreignId('uploader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('access_level')->default('admin');
            $table->string('path');
            $table->string('mime_type', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text', 500)->default('');
            $table->string('caption', 1000)->nullable();
            $table->timestamps();
            $table->index(['group_id', 'created_at']);
        });

        Schema::table('discord_guild_integrations', function (Blueprint $table) {
            $table->foreignId('integration_client_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discord_guild_integrations', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_client_id'));
        foreach (['group_resource_images', 'group_resource_commands', 'group_resource_slugs', 'group_resource_revisions', 'group_resource_activity_type', 'group_resource_tag', 'group_resource_tags', 'group_resources', 'group_resource_collections', 'group_resource_libraries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
