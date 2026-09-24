<?php

use App\Auth\EmailIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One mailbox, one account (App\Auth\EmailIdentity). Addresses are stored in
 * lower case from now on; this backfills both. Production had 12 accounts, no
 * two of them the same mailbox, none in mixed case (checked 2026-09-25) - the
 * unique index below would refuse the migration rather than merge anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_canonical')->nullable()->after('email');
        });
        foreach (DB::table('users')->select('id', 'email')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'email' => strtolower(trim($user->email)),
                'email_canonical' => EmailIdentity::canonical($user->email),
            ]);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email_canonical');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email_canonical']);
            $table->dropColumn('email_canonical');
        });
    }
};
