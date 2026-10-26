<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 36): Bausteine «Community» im Kern — das öffentliche Profil am Kundenkonto (FO-K2, mit Verknüpfung zum
 * Team, FO-K14) und Besuchergruppen mit Rechten (FO-K3). Rechte gelten global (`subject_type` leer, `subject_id` 0) oder
 * für ein Objekt eines Moduls (`forum_node`, 12); der Wert ist 1 = ja, 0 = nein, -1 = nie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 40);
            $table->string('name_key', 40);
            $table->string('avatar_path')->nullable();
            $table->text('signature')->nullable();
            $table->unsignedInteger('posts')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'name_key']);
            $table->unique(['site_id', 'user_id']);
        });

        Schema::create('visitor_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30)->nullable();
            $table->string('name', 60);
            $table->string('badge', 30)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });

        Schema::create('visitor_group_customer', function (Blueprint $table): void {
            $table->foreignId('visitor_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['visitor_group_id', 'customer_id']);
        });

        Schema::create('visitor_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_group_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60);
            $table->string('subject_type', 40)->default('');
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->tinyInteger('value');
            $table->timestamps();
            $table->unique(['visitor_group_id', 'permission', 'subject_type', 'subject_id'], 'visitor_permissions_unique');
            $table->index(['site_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_permissions');
        Schema::dropIfExists('visitor_group_customer');
        Schema::dropIfExists('visitor_groups');
        Schema::dropIfExists('community_profiles');
    }
};
