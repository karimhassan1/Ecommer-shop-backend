<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One time codes for e-mail verification.
 *
 * The 6 digit code is never stored in the clear: `verification_code` holds a
 * hash and `verification_code_expires_at` bounds its lifetime. Both columns are
 * listed in `User::$hidden`, so LaraQL keeps them out of the GraphQL schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            $table->unsignedTinyInteger('verification_attempts')->default(0)->after('verification_code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['verification_code', 'verification_code_expires_at', 'verification_attempts']);
        });
    }
};
