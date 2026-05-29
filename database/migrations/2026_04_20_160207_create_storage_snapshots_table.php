<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('bytes_used')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'snapshot_date'], 'storage_snapshots_unique');
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_snapshots');
    }
};
