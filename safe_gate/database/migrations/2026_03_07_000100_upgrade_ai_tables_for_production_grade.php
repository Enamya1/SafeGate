<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_sessions')) {
            Schema::table('ai_chat_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_chat_sessions', 'session_uuid')) {
                    $table->char('session_uuid', 36)->nullable()->unique()->after('user_id');
                }
            });
        }

        if (Schema::hasTable('ai_chat_messages')) {
            Schema::table('ai_chat_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_chat_messages', 'message_type')) {
                    $table->enum('message_type', ['user', 'assistant', 'system'])->default('user')->after('role');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'content_type')) {
                    $table->enum('content_type', ['text', 'voice', 'function_call'])->default('text')->after('message_type');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'function_name')) {
                    $table->string('function_name', 100)->nullable()->after('content');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'function_arguments')) {
                    $table->json('function_arguments')->nullable()->after('function_name');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'function_response')) {
                    $table->json('function_response')->nullable()->after('function_arguments');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'tokens_used')) {
                    $table->unsignedInteger('tokens_used')->default(0)->after('tokens');
                }
                if (! Schema::hasColumn('ai_chat_messages', 'audio_duration_seconds')) {
                    $table->decimal('audio_duration_seconds', 6, 2)->nullable()->after('tokens_used');
                }
            });
        }

        if (Schema::hasTable('ai_activity_events')) {
            Schema::table('ai_activity_events', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_activity_events', 'event_type')) {
                    $table->string('event_type', 50)->nullable()->after('payload');
                }
                if (! Schema::hasColumn('ai_activity_events', 'model_used')) {
                    $table->string('model_used', 50)->nullable()->after('event_type');
                }
                if (! Schema::hasColumn('ai_activity_events', 'total_tokens')) {
                    $table->unsignedInteger('total_tokens')->default(0)->after('model_used');
                }
                if (! Schema::hasColumn('ai_activity_events', 'prompt_tokens')) {
                    $table->unsignedInteger('prompt_tokens')->default(0)->after('total_tokens');
                }
                if (! Schema::hasColumn('ai_activity_events', 'completion_tokens')) {
                    $table->unsignedInteger('completion_tokens')->default(0)->after('prompt_tokens');
                }
                if (! Schema::hasColumn('ai_activity_events', 'cost')) {
                    $table->decimal('cost', 10, 6)->unsigned()->default(0)->after('completion_tokens');
                }
                if (! Schema::hasColumn('ai_activity_events', 'duration_ms')) {
                    $table->unsignedInteger('duration_ms')->default(0)->after('cost');
                }
                if (! Schema::hasColumn('ai_activity_events', 'success')) {
                    $table->boolean('success')->default(true)->after('duration_ms');
                }
                if (! Schema::hasColumn('ai_activity_events', 'error_message')) {
                    $table->text('error_message')->nullable()->after('success');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_activity_events')) {
            Schema::table('ai_activity_events', function (Blueprint $table) {
                if (Schema::hasColumn('ai_activity_events', 'error_message')) {
                    $table->dropColumn('error_message');
                }
                if (Schema::hasColumn('ai_activity_events', 'success')) {
                    $table->dropColumn('success');
                }
                if (Schema::hasColumn('ai_activity_events', 'duration_ms')) {
                    $table->dropColumn('duration_ms');
                }
                if (Schema::hasColumn('ai_activity_events', 'cost')) {
                    $table->dropColumn('cost');
                }
                if (Schema::hasColumn('ai_activity_events', 'completion_tokens')) {
                    $table->dropColumn('completion_tokens');
                }
                if (Schema::hasColumn('ai_activity_events', 'prompt_tokens')) {
                    $table->dropColumn('prompt_tokens');
                }
                if (Schema::hasColumn('ai_activity_events', 'total_tokens')) {
                    $table->dropColumn('total_tokens');
                }
                if (Schema::hasColumn('ai_activity_events', 'model_used')) {
                    $table->dropColumn('model_used');
                }
                if (Schema::hasColumn('ai_activity_events', 'event_type')) {
                    $table->dropColumn('event_type');
                }
            });
        }

        if (Schema::hasTable('ai_chat_messages')) {
            Schema::table('ai_chat_messages', function (Blueprint $table) {
                if (Schema::hasColumn('ai_chat_messages', 'audio_duration_seconds')) {
                    $table->dropColumn('audio_duration_seconds');
                }
                if (Schema::hasColumn('ai_chat_messages', 'tokens_used')) {
                    $table->dropColumn('tokens_used');
                }
                if (Schema::hasColumn('ai_chat_messages', 'function_response')) {
                    $table->dropColumn('function_response');
                }
                if (Schema::hasColumn('ai_chat_messages', 'function_arguments')) {
                    $table->dropColumn('function_arguments');
                }
                if (Schema::hasColumn('ai_chat_messages', 'function_name')) {
                    $table->dropColumn('function_name');
                }
                if (Schema::hasColumn('ai_chat_messages', 'content_type')) {
                    $table->dropColumn('content_type');
                }
                if (Schema::hasColumn('ai_chat_messages', 'message_type')) {
                    $table->dropColumn('message_type');
                }
            });
        }

        if (Schema::hasTable('ai_chat_sessions') && Schema::hasColumn('ai_chat_sessions', 'session_uuid')) {
            Schema::table('ai_chat_sessions', function (Blueprint $table) {
                $table->dropUnique(['session_uuid']);
                $table->dropColumn('session_uuid');
            });
        }
    }
};
