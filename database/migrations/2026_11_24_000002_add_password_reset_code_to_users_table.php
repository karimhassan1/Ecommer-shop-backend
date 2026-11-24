<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One time password reset codes.
 *
 * The reset flow no longer e-mails a signed link: the customer asks for a code,
 * receives six digits and types them on the "reset password" screen together
 * with the new password. The code is stored **hashed** and carries its own
 * expiry and attempt counter, exactly like the e-mail verification code, so a
 * leaked database row cannot be replayed.
 *
 * The columns are listed in `User::$hidden`, which is what keeps them out of the
 * GraphQL schema LaraQL builds from the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password_reset_code')->nullable()->after('verification_attempts');
            $table->timestamp('password_reset_code_expires_at')->nullable()->after('password_reset_code');
            $table->unsignedTinyInteger('password_reset_attempts')->default(0)->after('password_reset_code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'password_reset_code',
                'password_reset_code_expires_at',
                'password_reset_attempts',
            ]);
        });
    }
};
