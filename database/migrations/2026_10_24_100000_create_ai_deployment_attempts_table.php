<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_deployment_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('deployment_uuid');
            $table->string('trigger')->default('manual');
            $table->string('status')->default('pending');
            $table->text('diagnosis')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('fixable')->default(true);
            $table->json('plan')->nullable();
            $table->string('plan_hash')->nullable();
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->json('created_resources')->nullable();
            $table->json('deployment_uuids')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
            $table->index('deployment_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_deployment_attempts');
    }
};
